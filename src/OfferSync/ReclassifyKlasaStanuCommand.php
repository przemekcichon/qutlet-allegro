<?php
/**
 * Slice OfferSync — reklasyfikacja klasy stanu na żądanie (P-19.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy;
use Qutlet\Core\ProductInfo\RawLayerMeta;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro reclassify-klasa-stanu` — przelicza relację `klasa_stanu`
 * już zaimportowanych produktów z surowej oferty Allegro
 * ({@see RawLayerMeta::META_OFFER}) wg AKTUALNEJ `OfferMapper::CONDITION_MAP`,
 * na żądanie operatora (FAZA 19).
 *
 * ## Dlaczego to nie robi ani import, ani backfill
 * `ProductWriter::upsert()` (D-6.1.4) ustawia `klasa_stanu` TYLKO gdy produkt
 * NIE MA jeszcze relacji z {@see ClassDefinitionsTaxonomy} — świadoma ochrona
 * ręcznej oceny kuratora przed nadpisaniem kolejnym importem. Konsekwencja:
 * zmiana `CONDITION_MAP` w kodzie działa TYLKO dla nowych produktów, nigdy
 * retroaktywnie dla już zaimportowanych. {@see \Qutlet\Core\ProductCondition\
 * BackfillKlasaStanuRelationCommand} (jednorazowa migracja cutoverowa
 * P-12.2a) też nie adresuje tego przypadku — migruje WYŁĄCZNIE historyczny
 * literał postmeta `klasa_stanu`, bez czytania surowej oferty. Ta komenda
 * jest deliberatnym, punktowym odstępstwem od D-6.1.4 dla jednej, ręcznie
 * wywołanej operacji (D-19.1–D-19.4, `docs/plan.md` FAZA 19).
 *
 * ## Brak śladu pochodzenia (D-19.2, zaakceptowane ryzyko)
 * Komenda nadpisuje relację bez żadnego mechanizmu odróżniania „auto-mapa
 * importu" od „ręczna ocena kuratora" — ryzyko świadomie zaakceptowane,
 * NIE adresowane nowym polem/meta. Ekran edycji produktu i tak już pokazuje
 * surowy „Stan" obok wyboru `klasa_stanu` (P-13.7a).
 *
 * ## Zapis relacji
 * `wp_set_object_terms()` BEZPOŚREDNIO (wzorem `BackfillKlasaStanuRelationCommand`),
 * NIE przez `update_field()` — unika dotykania prywatnej stałej
 * `ProductWriter::ACF_KEY_CONDITION`. Ten sam efekt końcowy (ACF `taxonomy`
 * z `save_terms=1` i tak tylko opakowuje tę samą funkcję WP).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki, obok
 * `import-offers`/`sync-stock`.
 */
final class ReclassifyKlasaStanuCommand {

	/**
	 * Rozmiar strony iteracji produktów (wzorzec `BackfillKlasaStanuRelationCommand`).
	 */
	private const PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (wzorzec `BackfillKlasaStanuRelationCommand`).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Przelicza klasę stanu produktów z surową ofertą Allegro wg aktualnej
	 * `OfferMapper::CONDITION_MAP` i (gdy różni się) nadpisuje relację
	 * {@see ClassDefinitionsTaxonomy}.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Policz kandydatów i pokaż, co zostałoby zmienione — bez jednego zapisu.
	 *
	 * [--stan=<wartosc>]
	 * : Zawęża kandydatów do produktów, których surowy parametr „Stan" (Allegro)
	 *   DOKŁADNIE odpowiada podanej wartości (D-19.1, np. `--stan="Po zwrocie"`) —
	 *   do punktowej korekty jednej klasy bez ruszania reszty katalogu.
	 *   Porównanie ścisłe, case-sensitive (jak klucze `OfferMapper::CONDITION_MAP`).
	 *   Bez flagi: przelicza WSZYSTKIE produkty z surową ofertą.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro reclassify-klasa-stanu --dry-run
	 *     wp qutlet-allegro reclassify-klasa-stanu
	 *     wp qutlet-allegro reclassify-klasa-stanu --stan="Po zwrocie" --dry-run
	 *     wp qutlet-allegro reclassify-klasa-stanu --stan="Po zwrocie"
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = (bool) get_flag_value( $assoc_args, 'dry-run', false );

		$stan_filter = get_flag_value( $assoc_args, 'stan' );
		$stan_filter = is_string( $stan_filter ) && '' !== trim( $stan_filter ) ? trim( $stan_filter ) : null;

		$checked     = 0;
		$changed     = 0;
		$unchanged   = 0;
		$unknown_kod = 0;
		$no_stan     = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$product_ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any', // Bez kosza (wzorzec BackfillKlasaStanuRelationCommand).
					'posts_per_page' => self::PAGE_LIMIT,
					'paged'          => $page,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- operacja na żądanie (FAZA 19), nie ścieżka na krytycznym torze.
						array(
							'key'     => RawLayerMeta::META_OFFER,
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);

			if ( array() === $product_ids ) {
				break;
			}

			foreach ( $product_ids as $product_id ) {
				$product_id = (int) $product_id;

				$offer = $this->decode_offer( $product_id );

				if ( null === $offer ) {
					continue; // meta_query dopasowuje != '', ale zdekodowana wartość może być nieprawidłowym JSON-em — guard.
				}

				if ( null !== $stan_filter && OfferMapper::condition_raw( $offer ) !== $stan_filter ) {
					continue; // D-19.1: --stan zawęża kandydatów — poza zakresem tego uruchomienia.
				}

				++$checked;

				$kod = OfferMapper::condition_class( $offer );

				if ( null === $kod ) {
					++$no_stan;
					WP_CLI::warning( sprintf( 'Produkt %d: brak/nieznana wartość parametru „Stan" w ofercie („%s") — pominięto.', $product_id, (string) OfferMapper::condition_raw( $offer ) ) );

					continue;
				}

				$definicja = ClassDefinitionsTaxonomy::get( $kod );

				if ( null === $definicja ) {
					++$unknown_kod;
					WP_CLI::warning( sprintf( 'Produkt %d: kod „%s" (auto-mapa „Stan") nie ma jeszcze zdefiniowanego termu w taksonomii „Klasy stanu" — pominięto.', $product_id, $kod ) );

					continue;
				}

				$current     = ClassDefinitionsTaxonomy::for_product( $product_id );
				$current_kod = $current['kod'] ?? null;

				if ( $current_kod === $kod ) {
					++$unchanged;

					continue;
				}

				++$changed;

				$stara_klasa = null !== $current ? sprintf( '„%s" (kod „%s")', $current['nazwa'], $current_kod ) : 'brak relacji';

				if ( $dry_run ) {
					WP_CLI::log( sprintf( '  (dry-run) produkt %d: %s → „%s" (kod „%s", term_id %d).', $product_id, $stara_klasa, $definicja['nazwa'], $kod, $definicja['term_id'] ) );

					continue;
				}

				wp_set_object_terms( $product_id, array( $definicja['term_id'] ), ClassDefinitionsTaxonomy::TAXONOMY, false );

				WP_CLI::log( sprintf( '  produkt %d: %s → „%s" (kod „%s", term_id %d) ustawiono.', $product_id, $stara_klasa, $definicja['nazwa'], $kod, $definicja['term_id'] ) );
			}

			if ( count( $product_ids ) < self::PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano na bezpieczniku %d stron — uruchom komendę ponownie (idempotentna, dokończy resztę).', self::MAX_PAGES ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'reclassify-klasa-stanu (dry-run): sprawdzono %d, zmieniłoby się %d, bez zmian %d, nieznany kod %d, brak parametru „Stan" %d.', $checked, $changed, $unchanged, $unknown_kod, $no_stan ) );

			return;
		}

		WP_CLI::success( sprintf( 'reclassify-klasa-stanu: sprawdzono %d, zmieniono %d, bez zmian %d, nieznany kod %d, brak parametru „Stan" %d.', $checked, $changed, $unchanged, $unknown_kod, $no_stan ) );
	}

	/**
	 * Zdekodowana surowa oferta Allegro ({@see RawLayerMeta::META_OFFER}) produktu.
	 *
	 * @param int $product_id Id produktu.
	 * @return array<string,mixed>|null Null, gdy meta puste albo JSON nieprawidłowy.
	 */
	private function decode_offer( int $product_id ): ?array {
		$offer_json = get_post_meta( $product_id, RawLayerMeta::META_OFFER, true );

		if ( ! is_string( $offer_json ) || '' === trim( $offer_json ) ) {
			return null;
		}

		$offer = json_decode( $offer_json, true );

		return is_array( $offer ) ? $offer : null;
	}
}
