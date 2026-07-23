<?php
/**
 * Slice OfferSync — komenda WP-CLI importu ofert Allegro → produkty Woo (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro import-offers` — pobiera oferty `ACTIVE` z Allegro
 * (`GET /sale/offers` → `GET /sale/product-offers/{id}`) i tworzy/aktualizuje
 * produkty Woo wg mappingu FAZY 4, wypełniając warstwę surową FAZY 5 i pola
 * `AllegroLink` (zapis: {@see ProductWriter}).
 *
 * ## Środowisko (D-6.G5)
 * Import jest parametryzowany środowiskiem (`--environment`, domyślnie sandbox):
 * w pracy deweloperskiej ciągniemy z SANDBOXA (zasianego w FAZIE 3A), na produkcji
 * z produkcji. Slot `read`. Import to odczyt Allegro → zapis Woo, więc bezpiecznik
 * D-2.G7 (zakaz zapisu treści ofert na produkcji) nie ma tu zastosowania — dotyczy
 * dopiero sync stanów (P-6.2).
 *
 * ## Idempotencja i wznawialność
 * Klucz powiązania: `_qutlet_allegro_offer_id` — ponowny przebieg aktualizuje,
 * nie duplikuje (produkty ani zdjęcia — te są kluczowane po `_qutlet_source_url`).
 * Pełny katalog z obrazami przekracza timeout mostu MCP, więc komenda jest
 * bezpieczna do przerwania i ponownego uruchomienia; do przebiegów porcjowanych
 * służy `--max-offers` (już zaimportowane oferty przy kolejnym przebiegu tylko
 * się aktualizują, a ich zdjęcia nie są pobierane ponownie).
 *
 * ## Jawnie pomijane (mapping §6)
 * - oferty nie-`ACTIVE` (import odtwarza asortyment sprzedawalny),
 * - `sellingMode.format !== BUY_NOW` (aukcje mają inny kształt ceny),
 * - `productSet` o długości ≠ 1 (model FAZY 1 zakłada pojedynczy produkt).
 *
 * ## Kategorie
 * `category.id` → ścieżka przez API drzewa ({@see CategoryResolver}, cache per
 * przebieg) → kolaps wg reguł ({@see CategoryMapRules}). Oferta bez reguły
 * dostaje kosz `pozostale`, a podsumowanie wypisuje nierozwiązane gałęzie
 * (id + nazwy) do dopisania reguł (D-6.1.2).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class ImportOffersCommand {

	use AllegroCliSupport;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy) — {@see AllegroCliSupport::send()}.
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Rozmiar strony listy `GET /sale/offers` (maksimum API).
	 */
	private const DEFAULT_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji listy (jak w `SandboxSeed\OfferSnapshotCommand`).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Status publikacji kwalifikujący ofertę do importu (literał VERBATIM).
	 */
	private const STATUS_ACTIVE = 'ACTIVE';

	/**
	 * Format sprzedaży obsługiwany przez model (mapping §6: aukcje pomijamy).
	 */
	private const FORMAT_BUY_NOW = 'BUY_NOW';

	/**
	 * Co ile przetworzonych ofert wypisać linię postępu.
	 */
	private const PROGRESS_EVERY = 10;

	/**
	 * Importuje oferty Allegro do produktów WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--environment=<env>]
	 * : Środowisko źródłowe (`sandbox`/`production`), slot `read` (D-6.G5).
	 * ---
	 * default: sandbox
	 * options:
	 *   - sandbox
	 *   - production
	 * ---
	 *
	 * [--offer=<id>]
	 * : Import pojedynczej oferty o podanym id (pomija listowanie).
	 *
	 * [--limit=<n>]
	 * : Rozmiar strony listy `GET /sale/offers` (1–100).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--max-offers=<n>]
	 * : Górny limit ofert przetworzonych w tym przebiegu (0 = bez limitu).
	 *   Do przebiegów porcjowanych pod timeout mostu MCP.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--skip-images]
	 * : Nie pobieraj zdjęć (szybkie przebiegi próbne; pola i meta pełne).
	 *
	 * [--status=<status>]
	 * : Status posta dla NOWO tworzonych produktów (istniejących nie zmienia).
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro import-offers --max-offers=5 --skip-images
	 *     wp qutlet-allegro import-offers --environment=production --offer=18780385602
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$environment = (string) get_flag_value( $assoc_args, 'environment', Environment::SANDBOX );

		if ( Environment::SANDBOX !== $environment && Environment::PRODUCTION !== $environment ) {
			WP_CLI::error( sprintf( 'Nieznane środowisko: „%s" (dozwolone: sandbox, production).', $environment ) );
		}

		$single_offer = get_flag_value( $assoc_args, 'offer', '' );
		$single_offer = is_string( $single_offer ) ? trim( $single_offer ) : '';
		$limit        = min( self::DEFAULT_LIMIT, max( 1, (int) get_flag_value( $assoc_args, 'limit', (string) self::DEFAULT_LIMIT ) ) );
		$max_offers   = max( 0, (int) get_flag_value( $assoc_args, 'max-offers', '0' ) );
		$skip_images  = (bool) get_flag_value( $assoc_args, 'skip-images', false );
		$status       = (string) get_flag_value( $assoc_args, 'status', 'publish' );

		if ( 'publish' !== $status && 'draft' !== $status ) {
			WP_CLI::error( sprintf( 'Nieznany status: „%s" (dozwolone: publish, draft).', $status ) );
		}

		$this->assert_import_dependencies();

		$access = $this->access_token( $environment, Environment::ROLE_READ );
		$api    = Environment::for_environment( $environment )->api_base_url();

		// Rozdzielczość drzewa kategorii — transport wstrzyknięty, cache per przebieg.
		$resolver = new CategoryResolver(
			function ( string $category_id ) use ( $api, $access ): ?array {
				$resp = $this->get( $api . '/sale/categories/' . rawurlencode( $category_id ), $access );

				return 200 === $resp['status'] && is_array( $resp['data'] ) ? $resp['data'] : null;
			}
		);
		$writer   = new ProductWriter();

		// Lista ofert do przetworzenia: jawna flaga --offer albo pełny indeks ACTIVE.
		if ( '' !== $single_offer ) {
			$targets = array( $single_offer );
			WP_CLI::log( sprintf( 'Import pojedynczej oferty %s (%s).', $single_offer, $environment ) );
		} else {
			$index   = $this->offer_index( $api, $access, $limit );
			$targets = array();

			foreach ( $index as $offer_id => $offer_status ) {
				if ( self::STATUS_ACTIVE === $offer_status ) {
					$targets[] = (string) $offer_id;
				}
			}

			WP_CLI::log(
				sprintf(
					'Lista: %d ofert, w tym %d ACTIVE do importu (%s).',
					count( $index ),
					count( $targets ),
					$environment
				)
			);
		}

		$created   = 0;
		$updated   = 0;
		$skipped   = 0;
		$failed    = 0;
		$processed = 0;

		/**
		 * Nierozwiązane gałęzie kategorii (D-6.1.2): leaf id → opis ścieżki do logu.
		 *
		 * @var array<string,string> $unmapped
		 */
		$unmapped = array();

		foreach ( $targets as $offer_id ) {
			if ( $max_offers > 0 && $processed >= $max_offers ) {
				break;
			}

			++$processed;

			$full = $this->get( $api . '/sale/product-offers/' . rawurlencode( $offer_id ), $access );

			if ( 200 !== $full['status'] || ! is_array( $full['data'] ) ) {
				++$failed;
				WP_CLI::warning( sprintf( 'Oferta %s → HTTP %d %s', $offer_id, $full['status'], $this->error_detail( $full ) ) );

				continue;
			}

			$offer = $full['data'];

			// Bramy modelu (mapping §6) — jawne pominięcia z powodem w logu.
			$publication_status = $offer['publication']['status'] ?? null;

			if ( self::STATUS_ACTIVE !== $publication_status ) {
				++$skipped;
				WP_CLI::log( sprintf( '  %s pominięta: publication.status=%s.', $offer_id, (string) $publication_status ) );

				continue;
			}

			$format = $offer['sellingMode']['format'] ?? null;

			if ( self::FORMAT_BUY_NOW !== $format ) {
				++$skipped;
				WP_CLI::log( sprintf( '  %s pominięta: sellingMode.format=%s (obsługujemy tylko BUY_NOW).', $offer_id, (string) $format ) );

				continue;
			}

			$product_set = $offer['productSet'] ?? null;

			if ( ! is_array( $product_set ) || 1 !== count( $product_set ) ) {
				++$skipped;
				WP_CLI::log(
					sprintf(
						'  %s pominięta: productSet o długości %s (model zakłada pojedynczy produkt).',
						$offer_id,
						is_array( $product_set ) ? (string) count( $product_set ) : 'brak'
					)
				);

				continue;
			}

			// Kategoria: rozdzielczość ścieżki + kolaps; brak reguły → kosz + rejestr.
			$leaf_id = (string) ( $offer['category']['id'] ?? '' );
			$path    = '' !== $leaf_id ? $resolver->path( $leaf_id ) : array();
			$slug    = CategoryMapRules::resolve_slug( $path );

			if ( null === $slug ) {
				$slug = CategoryMapRules::FALLBACK_SLUG;

				// Oferta bez category.id (nieobserwowana w snapshocie, ale możliwa)
				// też musi być widoczna w rejestrze kuratora — klucz syntetyczny.
				$registry_key = '' !== $leaf_id ? $leaf_id : 'brak-category-id:' . $offer_id;

				if ( ! isset( $unmapped[ $registry_key ] ) ) {
					$unmapped[ $registry_key ] = '' !== $leaf_id
						? $this->describe_path( $leaf_id, $path )
						: sprintf( 'oferta %s bez category.id', $offer_id );
				}
			}

			$result = $writer->upsert( $offer, $full['body'], $environment, $slug, $path, $status, $skip_images );

			foreach ( $result['warnings'] as $warning ) {
				WP_CLI::warning( sprintf( 'Oferta %s: %s', $offer_id, $warning ) );
			}

			if ( 'created' === $result['action'] ) {
				++$created;
			} elseif ( 'updated' === $result['action'] ) {
				++$updated;
			} else {
				++$failed;
			}

			if ( 0 === $processed % self::PROGRESS_EVERY ) {
				WP_CLI::log( sprintf( '  przetworzono %d ofert (utworzone: %d, zaktualizowane: %d)…', $processed, $created, $updated ) );
			}
		}

		// Rejestr nierozwiązanych gałęzi — materiał dla kuratora (D-6.1.2).
		if ( array() !== $unmapped ) {
			WP_CLI::log( sprintf( 'Kategorie bez reguły kolapsu (%d) — produkty trafiły do kosza „%s"; dopisz reguły w CategoryMapRules:', count( $unmapped ), CategoryMapRules::FALLBACK_SLUG ) );

			foreach ( $unmapped as $line ) {
				WP_CLI::log( '  - ' . $line );
			}
		}

		/*
		 * „Poprawnie pusto" ≠ awaria (recenzja P-6.1b): przebieg złożony wyłącznie z
		 * legalnych pominięć (aukcje, nie-ACTIVE, productSet>1) kończy się sukcesem z
		 * licznikami — komenda jest wznawialna i taki wynik jest prawidłowy. Błędem
		 * (exit 1) kończymy tylko stan, w którym nic nie zapisano, a wystąpiły
		 * REALNE błędy — wtedy „sukces" maskowałby awarię.
		 */
		if ( 0 === $created + $updated && $failed > 0 ) {
			WP_CLI::error(
				sprintf(
					'Import nie zapisał żadnego produktu (pominięte: %d, błędy: %d).',
					$skipped,
					$failed
				)
			);
		}

		WP_CLI::success(
			sprintf(
				'Import (%s): %d utworzonych, %d zaktualizowanych, %d pominiętych, %d błędów.',
				$environment,
				$created,
				$updated,
				$skipped,
				$failed
			)
		);
	}

	/**
	 * Twarde zależności importu — jasny komunikat zamiast fatala w połowie zapisu.
	 * Obecność Woo i core gwarantuje bootstrap (D-G5); tu bronimy się przed core
	 * SPRZED P-6.1a (brak stawki rabatu) i przed nieaktywnym ACF (pola kanału).
	 *
	 * @return void
	 */
	private function assert_import_dependencies(): void {
		if ( ! class_exists( '\Qutlet\Core\Pricing\DiscountRate' ) ) {
			WP_CLI::error( 'Brak Qutlet\Core\Pricing\DiscountRate — zaktualizuj qutlet-core do wersji z P-6.1a (stawka rabatu).' );
		}

		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'Brak funkcji update_field() — import wymaga aktywnego ACF (pola kanału Allegro rejestruje qutlet-core).' );
		}
	}

	/**
	 * Indeks ofert konta: `offerId => publication.status` ze WSZYSTKICH stron
	 * `GET /sale/offers`. Wzorzec paginacji jak w snapshocie (P-3A.1a): błąd HTTP
	 * na dowolnej stronie kończy komendę — import z niepełną listą byłby cichym
	 * pominięciem nieznanej liczby ofert.
	 *
	 * @param string $api    Baza REST API Allegro.
	 * @param string $access Access token.
	 * @param int    $limit  Rozmiar strony.
	 * @return array<string,string>
	 */
	private function offer_index( string $api, string $access, int $limit ): array {
		$index  = array();
		$total  = null;
		$offset = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$url  = $api . '/sale/offers?' . http_build_query(
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			);
			$resp = $this->get( $url, $access );

			if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
				WP_CLI::error(
					sprintf(
						'GET /sale/offers (offset=%d) zwróciło HTTP %d %s — import z niepełną listą pominąłby nieznaną liczbę ofert.',
						$offset,
						$resp['status'],
						$this->error_detail( $resp )
					)
				);
			}

			$offers = isset( $resp['data']['offers'] ) && is_array( $resp['data']['offers'] )
				? array_values( $resp['data']['offers'] )
				: array();

			if ( null === $total && isset( $resp['data']['totalCount'] ) ) {
				$total = (int) $resp['data']['totalCount'];
			}

			if ( array() === $offers ) {
				break;
			}

			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) || ! isset( $offer['id'] ) ) {
					continue;
				}

				$offer_id = (string) $offer['id'];

				if ( '' === $offer_id ) {
					continue;
				}

				$index[ $offer_id ] = isset( $offer['publication']['status'] )
					? (string) $offer['publication']['status']
					: 'unknown';
			}

			$offset += count( $offers );

			if ( null !== $total && $offset >= $total ) {
				break;
			}

			if ( self::MAX_PAGES - 1 === $page ) {
				WP_CLI::warning(
					sprintf( 'Przerwano paginację na bezpieczniku %d stron (offset=%d) — lista może być niepełna.', self::MAX_PAGES, $offset )
				);
			}
		}

		return $index;
	}

	/**
	 * Czytelny opis (id + rozwiązane nazwy) nierozwiązanej gałęzi do logu kuratora.
	 *
	 * @param string                                  $leaf_id Id liścia z oferty.
	 * @param array<int,array{id:string,name:string}> $path    Rozwiązana ścieżka (może być pusta/częściowa).
	 * @return string
	 */
	private function describe_path( string $leaf_id, array $path ): string {
		if ( array() === $path ) {
			return sprintf( '%s (ścieżka nierozwiązana — błąd API drzewa)', $leaf_id );
		}

		$names = array();

		foreach ( array_reverse( $path ) as $node ) {
			$names[] = sprintf( '%s (%s)', $node['name'], $node['id'] );
		}

		return implode( ' > ', $names );
	}
}
