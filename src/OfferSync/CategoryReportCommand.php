<?php
/**
 * Slice OfferSync — raport liści kategorii + re-kategoryzacja (P-6.8a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro category-report` — dla KAŻDEGO liścia kategorii Allegro obecnego
 * w imporcie (grupowanego po `_qutlet_allegro_category_id`, kontrakt §10.1) drukuje/zapisuje
 * jeden wiersz: id liścia, nazwa, pełna ścieżka do korzenia, liczba zaimportowanych
 * (non-trash) produktów, obecnie przypisany `product_cat`, dopasowana reguła
 * ({@see CategoryMapRules::resolve()} — leaf/branch) albo kosz „brak reguły" (D-6.1.2).
 * Warsztat kuratora pod P-6.8b (docelowy zestaw termów + kuracja reguł).
 *
 * ## Zero żądań do Allegro (domyślnie)
 * Czyta WYŁĄCZNIE zapisane meta `_qutlet_allegro_category_id` / `_qutlet_allegro_category_path`
 * (wypełnione przez `import-offers`, P-6.1b) — żadnego wywołania API. Liście, których ścieżka
 * jest pusta (nierozwiązana przy imporcie — błąd API drzewa w danym przebiegu), zostają
 * oznaczone jako nierozwiązane, CHYBA że poda się `--resolve-missing` (opt-in HTTP, slot
 * `read`, jak {@see ImportOffersCommand}).
 *
 * ## `--apply` — re-kategoryzacja istniejącego katalogu
 * Bez `--apply` komenda jest czystym raportem (zero zapisów). Z `--apply`, dla KAŻDEGO
 * produktu w każdym rozwiązanym liściu, przelicza regułę wg AKTUALNEGO
 * {@see CategoryMapRules} i nadpisuje `product_cat`, gdy wynik się różni od obecnego
 * przypisania — dokładnie ta sama logika przypisania kategorii co przy imporcie
 * ({@see ProductWriter::upsert()}), ale bez dotykania zdjęć/opisu/ceny/stanu. Liście
 * z nierozwiązaną ścieżką (i bez `--resolve-missing`) są pomijane przy `--apply` — nie da
 * się bezpiecznie przeliczyć reguły bez ścieżki.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (jak {@see ImportOffersCommand}).
 */
final class CategoryReportCommand {

	use AllegroCliSupport;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy) — {@see AllegroCliSupport::send()}.
	 * Używany tylko z `--resolve-missing`.
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Rozmiar strony iteracji produktów.
	 */
	private const PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (jak pozostałe komendy repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Statusy produktu liczone jako „zaimportowany" — bez kosza (D-6.2.1: produkt w
	 * koszu jest świadomie wycofany, nie liczy się do żywego katalogu).
	 *
	 * @var array<int,string>
	 */
	private const NON_TRASH_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Klucz syntetyczny bucketu dla ofert bez `category.id` (obserwowane w
	 * `ImportOffersCommand` jako `brak-category-id:{offer_id}` per-ofertowo; tu grupujemy
	 * je razem, bo raport jest per-liść, nie per-oferta).
	 */
	private const NO_CATEGORY_ID_KEY = '(brak-category-id)';

	/**
	 * Buduje raport liści kategorii i (opcjonalnie) re-kategoryzuje istniejący katalog.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<path>]
	 * : Ścieżka pliku CSV. Bez tej flagi raport trafia na stdout jako tabela.
	 *
	 * [--apply]
	 * : Przelicza regułę wg aktualnego CategoryMapRules dla każdego zaimportowanego
	 *   produktu i nadpisuje `product_cat`, gdy wynik się zmienił. Liście z nierozwiązaną
	 *   ścieżką (i bez --resolve-missing) są pomijane.
	 *
	 * [--resolve-missing]
	 * : Dociąga z API Allegro ścieżkę dla liści, których `_qutlet_allegro_category_path`
	 *   jest puste (nierozwiązane przy imporcie). Bez tej flagi — zero żądań HTTP.
	 *
	 * [--environment=<env>]
	 * : Środowisko API dla `--resolve-missing` (slot `read`, D-6.G5). Bez znaczenia bez
	 *   `--resolve-missing`.
	 * ---
	 * default: sandbox
	 * options:
	 *   - sandbox
	 *   - production
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro category-report --out=category-report.csv
	 *     wp qutlet-allegro category-report --resolve-missing --environment=production
	 *     wp qutlet-allegro category-report --apply
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$out             = get_flag_value( $assoc_args, 'out', '' );
		$out             = is_string( $out ) ? trim( $out ) : '';
		$apply           = (bool) get_flag_value( $assoc_args, 'apply', false );
		$resolve_missing = (bool) get_flag_value( $assoc_args, 'resolve-missing', false );
		$environment     = (string) get_flag_value( $assoc_args, 'environment', Environment::SANDBOX );

		if ( Environment::SANDBOX !== $environment && Environment::PRODUCTION !== $environment ) {
			WP_CLI::error( sprintf( 'Nieznane środowisko: „%s" (dozwolone: sandbox, production).', $environment ) );
		}

		$resolver = null;

		if ( $resolve_missing ) {
			$access   = $this->access_token( $environment, Environment::ROLE_READ );
			$api      = Environment::for_environment( $environment )->api_base_url();
			$resolver = new CategoryResolver(
				function ( string $category_id ) use ( $api, $access ): ?array {
					$resp = $this->get( $api . '/sale/categories/' . rawurlencode( $category_id ), $access );

					return 200 === $resp['status'] && is_array( $resp['data'] ) ? $resp['data'] : null;
				}
			);
		}

		$buckets = $this->collect_buckets();
		$rows    = array();
		$writer  = $apply ? new ProductWriter() : null;
		$applied = 0;

		foreach ( $buckets as $leaf_key => $bucket ) {
			$path = $bucket['path'];

			if ( array() === $path && null !== $resolver && self::NO_CATEGORY_ID_KEY !== $leaf_key ) {
				$path = $resolver->path( $bucket['leaf_id'] );
			}

			$row    = self::build_row( $bucket['leaf_id'], $path, count( $bucket['product_ids'] ), array_keys( $bucket['current_slugs'] ) );
			$rows[] = $row;

			if ( $apply && array() !== $path && null !== $writer ) {
				$applied += $this->apply_to_products( $bucket['product_ids'], $row['matched_rule_slug'], $bucket['leaf_id'], $writer );
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['imported_products'] <=> $a['imported_products'];
			}
		);

		if ( '' !== $out ) {
			$this->write( $out, self::to_csv( $rows ) );
			WP_CLI::log( sprintf( 'Raport zapisany do %s (%d liści).', $out, count( $rows ) ) );
		} elseif ( array() !== $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}

		if ( $apply ) {
			WP_CLI::success( sprintf( 'category-report --apply: %d liści, przekategoryzowano %d produktów.', count( $rows ), $applied ) );

			return;
		}

		WP_CLI::success( sprintf( 'category-report: %d liści.', count( $rows ) ) );
	}

	/**
	 * Grupuje wszystkie zaimportowane (non-trash) produkty po `_qutlet_allegro_category_id`.
	 *
	 * @return array<string,array{leaf_id:string,path:array<int,array{id:string,name:string}>,product_ids:array<int,int>,current_slugs:array<string,true>}>
	 */
	private function collect_buckets(): array {
		$buckets = array();

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => self::NON_TRASH_STATUSES,
					'posts_per_page' => self::PAGE_LIMIT,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- raport kuratora (P-6.8a), nie ścieżka na krytycznym torze.
						array(
							'key'     => AllegroLinkMeta::META_CATEGORY_ID,
							'compare' => 'EXISTS',
						),
					),
				)
			);

			if ( array() === $product_ids ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				$product_id = (int) $product_id;
				$leaf_id    = (string) get_post_meta( $product_id, AllegroLinkMeta::META_CATEGORY_ID, true );
				$leaf_key   = '' !== $leaf_id ? $leaf_id : self::NO_CATEGORY_ID_KEY;

				if ( ! isset( $buckets[ $leaf_key ] ) ) {
					$buckets[ $leaf_key ] = array(
						'leaf_id'       => $leaf_id,
						'path'          => array(),
						'product_ids'   => array(),
						'current_slugs' => array(),
					);
				}

				$buckets[ $leaf_key ]['product_ids'][] = $product_id;

				if ( array() === $buckets[ $leaf_key ]['path'] ) {
					$path = get_post_meta( $product_id, AllegroLinkMeta::META_CATEGORY_PATH, true );

					if ( is_array( $path ) && array() !== $path ) {
						$buckets[ $leaf_key ]['path'] = $path;
					}
				}

				$terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );

				if ( is_array( $terms ) ) {
					foreach ( $terms as $slug ) {
						$buckets[ $leaf_key ]['current_slugs'][ $slug ] = true;
					}
				}
			}

			if ( count( $product_ids ) < self::PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano zbieranie na bezpieczniku %d stron — raport może być niepełny.', self::MAX_PAGES ) );
			}
		}

		return $buckets;
	}

	/**
	 * Re-kategoryzuje produkty jednego liścia (`--apply`): dla każdego produktu, którego
	 * obecne `product_cat` różni się od `$expected_slug`, tworzy/znajduje term i nadpisuje
	 * przypisanie. Tylko kategoria — bez dotykania zdjęć/opisu/ceny/stanu (to rola
	 * `import-offers`).
	 *
	 * @param array<int,int> $product_ids   Id produktów tego liścia.
	 * @param string         $expected_slug Slug wyliczony z aktualnych reguł.
	 * @param string         $leaf_id       Id liścia (do logu).
	 * @param ProductWriter  $writer        Współdzielona logika tworzenia termu.
	 * @return int Liczba faktycznie zmienionych produktów.
	 */
	private function apply_to_products( array $product_ids, string $expected_slug, string $leaf_id, ProductWriter $writer ): int {
		$applied = 0;

		foreach ( $product_ids as $product_id ) {
			$before = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
			$before = is_array( $before ) ? $before : array();

			if ( array( $expected_slug ) === $before ) {
				continue;
			}

			$term_id = $writer->ensure_product_cat_term( $expected_slug );

			if ( null === $term_id ) {
				WP_CLI::warning( sprintf( 'Produkt %d: nie udało się utworzyć/znaleźć termu „%s".', $product_id, $expected_slug ) );
				continue;
			}

			$assigned = wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', false );

			if ( is_wp_error( $assigned ) ) {
				WP_CLI::warning( sprintf( 'Produkt %d: nie przypisano kategorii „%s": %s', $product_id, $expected_slug, $assigned->get_error_message() ) );
				continue;
			}

			++$applied;
			WP_CLI::log(
				sprintf(
					'  produkt %d: product_cat %s → %s (liść %s).',
					$product_id,
					array() !== $before ? implode( ',', $before ) : '(brak)',
					$expected_slug,
					$leaf_id
				)
			);
		}

		return $applied;
	}

	/**
	 * Buduje jeden wiersz raportu dla liścia — CZYSTA funkcja (bez WP), testowalna wprost.
	 * Rdzeń logiki komendy: co dopasowuje {@see CategoryMapRules::resolve()} vs co jest
	 * DZIŚ przypisane na produktach, i czy się to zgadza.
	 *
	 * @param string             $leaf_id        Id liścia (`_qutlet_allegro_category_id`, pusty = brak w ofercie).
	 * @param array<int,array{id:string,name:string}> $path Ścieżka liść→korzeń (pusta = nierozwiązana).
	 * @param int                $imported_count Liczba zaimportowanych (non-trash) produktów tego liścia.
	 * @param array<int,string>  $current_slugs  Zbiór slugów `product_cat` DZIŚ przypisanych na tych produktach.
	 * @return array{leaf_id:string,leaf_name:string,path:string,imported_products:int,current_product_cat:string,matched_rule_type:string,matched_rule_slug:string,status:string}
	 */
	public static function build_row( string $leaf_id, array $path, int $imported_count, array $current_slugs ): array {
		sort( $current_slugs );
		$current_str = array() !== $current_slugs ? implode( '|', $current_slugs ) : '(brak)';

		// Ścieżka nierozwiązana: reguły NIE dało się policzyć (resolve() dostałby pustą
		// tablicę i zawsze zwróciłby null) — pokazanie fallbacku `pozostale` jako
		// „dopasowanej reguły" sugerowałoby wynik, którego realnie nie ma (recenzja
		// P-6.8a). `status` sam wystarczająco sygnalizuje priorytet działania.
		if ( array() === $path ) {
			return array(
				'leaf_id'             => '' !== $leaf_id ? $leaf_id : '(brak)',
				'leaf_name'           => '(nierozwiązana)',
				'path'                => '(nierozwiązana — uruchom z --resolve-missing)',
				'imported_products'   => $imported_count,
				'current_product_cat' => $current_str,
				'matched_rule_type'   => 'n/d',
				'matched_rule_slug'   => '',
				'status'              => 'nierozwiazana-sciezka',
			);
		}

		$match         = CategoryMapRules::resolve( $path );
		$expected_slug = $match['slug'] ?? CategoryMapRules::FALLBACK_SLUG;
		$status        = $current_str === $expected_slug ? 'ok' : 'do-zmiany';

		return array(
			'leaf_id'             => '' !== $leaf_id ? $leaf_id : '(brak)',
			'leaf_name'           => $path[0]['name'],
			'path'                => self::describe_path( $path ),
			'imported_products'   => $imported_count,
			'current_product_cat' => $current_str,
			'matched_rule_type'   => $match['type'] ?? 'brak',
			'matched_rule_slug'   => $expected_slug,
			'status'              => $status,
		);
	}

	/**
	 * Czytelna ścieżka korzeń→liść do wiersza raportu (jak `ImportOffersCommand::describe_path()`,
	 * bez id oferty — tu opisujemy liść, nie pojedynczą ofertę). Czysta funkcja, bez WP.
	 *
	 * @param array<int,array{id:string,name:string}> $path Ścieżka liść→korzeń (mapping §7b), niepusta.
	 * @return string
	 */
	public static function describe_path( array $path ): string {
		$names = array();

		foreach ( array_reverse( $path ) as $node ) {
			$names[] = sprintf( '%s (%s)', $node['name'], $node['id'] );
		}

		return implode( ' > ', $names );
	}

	/**
	 * Serializuje wiersze raportu do CSV (nagłówek z kluczy pierwszego wiersza). Czysta
	 * funkcja — `fopen`/`fputcsv` na `php://temp` to wbudowane funkcje PHP, nie WP.
	 *
	 * BOM UTF-8 na początku (recenzja P-6.8a): nazwy kategorii niosą polskie diakrytyki
	 * („Pozostałe", „Słuchawki"), a to jest warsztat pod arkusz (Excel na Windows domyślnie
	 * zgaduje CP-1250 bez BOM-a i rozjeżdża ogonki) — bez BOM-a narzędzie nie spełniałoby
	 * własnego celu.
	 *
	 * @param array<int,array<string,int|string>> $rows Wiersze raportu.
	 * @return string
	 */
	public static function to_csv( array $rows ): string {
		if ( array() === $rows ) {
			return '';
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bufor w pamięci, nie plik na dysku.

		if ( false === $handle ) {
			return '';
		}

		// Escape jawny (5. argument) — od PHP 8.4 domyślna wartość jest przestarzała.
		fputcsv( $handle, array_keys( $rows[0] ), ',', '"', '\\' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return "\xEF\xBB\xBF" . ( false !== $csv ? $csv : '' );
	}
}
