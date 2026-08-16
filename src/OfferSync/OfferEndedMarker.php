<?php
/**
 * Slice OfferSync — marker operacyjny „oferta zniknęła z Allegro" (P-15.4).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Wycofanie kanału Allegro na produkcie, którego oferta zniknęła z indeksu
 * ACTIVE Allegro (D-15.7/D-15.8, FAZA 15). Wzorzec markera operacyjnego to
 * {@see StockPusher::META_PUSH_PENDING} (D-6.2.3) — meta WŁASNOŚCI
 * qutlet-allegro, świadomie NIE rejestrowana przez core (stan operacyjny
 * syncu, nie fakt modelu).
 *
 * `post_status` produktu NIGDY nie jest dotykany (D-15.8 — odrzucony nowy
 * `post_status`, ground-truth `ProductFilterQuery.php`: 4 miejsca surowego SQL
 * `post_status = 'publish'` + ryzyko 404 na stronie produktu). Efekt widoczny
 * na froncie to WYŁĄCZNIE `allegro_wlaczone = 0` (D-15.9) — ten sam zapis co
 * {@see ProductWriter::upsert()} już robi przy tworzeniu (D-9.1b.1), tylko z
 * wartością odwrotną i w NOWYM miejscu kodu (tutaj), żeby nie kolidować z
 * regułą „`allegro_wlaczone` TYLKO przy tworzeniu" z `upsert()`.
 * `allegro_url`/`cena_allegro` NIE są czyszczone — zostają jako historyczny
 * zapis ostatniej znanej oferty.
 *
 * Kosz nadrzędny (D-15.12) wobec CAŁEGO mechanizmu: produkt w koszu jest
 * pomijany bezwarunkowo przez obie strony (oznaczanie i auto-reversal) —
 * kurator wygrywa, D-6.2.1 bez zmian.
 */
final class OfferEndedMarker {

	/**
	 * `meta_key` markera „oferta zakończona" (wartość: unix timestamp
	 * oznaczenia, wzorzec {@see StockPusher::META_PUSH_PENDING}) — meta
	 * operacyjna qutlet-allegro (D-15.8), świadomie NIE zarejestrowana przez
	 * core.
	 */
	public const META_OFFER_ENDED = '_qutlet_allegro_offer_ended';

	/**
	 * Akcja domenowa odpalana przy PIERWSZYM wykryciu zniknięcia oferty
	 * (D-15.10) — payload `product_id`, nazwa produktu, permalink,
	 * WYŁĄCZNIE na potrzeby przyszłej, jeszcze nieistniejącej notyfikacji
	 * redaktora (poza zakresem P-15.4 — żaden konsument nie powstaje tutaj).
	 * Idempotentne: kolejne przebiegi z markerem już ustawionym NIE odpalają
	 * akcji ponownie ({@see self::mark()}).
	 */
	public const ACTION_OFFER_ENDED = 'qutlet_allegro_offer_ended';

	/**
	 * Oznacza produkt jako „oferta zakończona": zapisuje `allegro_wlaczone = 0`
	 * (D-15.9) i ustawia marker. Kosz nadrzędny (D-15.12a) — pomijany
	 * bezwarunkowo, zero zapisów. Idempotentne (D-15.10) — marker już
	 * ustawiony nie nadpisuje pola po raz drugi ani nie odpala akcji domenowej
	 * ponownie.
	 *
	 * @param int $product_id Id produktu Woo powiązanego ze zniknięta ofertą.
	 * @return string `marked` (pierwsze wykrycie) / `already-marked` / `skipped-trashed`.
	 */
	public static function mark( int $product_id ): string {
		if ( 'trash' === get_post_status( $product_id ) ) {
			return 'skipped-trashed';
		}

		if ( self::is_marked( $product_id ) ) {
			return 'already-marked';
		}

		update_field( ProductWriter::ACF_KEY_ALLEGRO_ENABLED, 0, $product_id );
		update_post_meta( $product_id, self::META_OFFER_ENDED, (string) time() );

		do_action(
			self::ACTION_OFFER_ENDED,
			$product_id,
			(string) get_the_title( $product_id ),
			(string) get_permalink( $product_id )
		);

		return 'marked';
	}

	/**
	 * Auto-reversal (D-15.11): oferta wróciła do indeksu ACTIVE — czyści
	 * marker i przywraca `allegro_wlaczone = 1` BEZWARUNKOWO (marker istnieje
	 * TYLKO gdy TEN mechanizm sam wcześniej wyłączył kanał — zero ryzyka
	 * nadpisania niezależnej, ręcznej decyzji kuratora: kurator wyłączający
	 * kanał z własnej woli nigdy nie ustawia tego markera). Kosz nadrzędny
	 * (D-15.12b) — pomijany bezwarunkowo, nawet z markerem ustawionym PRZED
	 * wyrzuceniem do kosza (zero automatycznego „odkosza").
	 *
	 * @param int $product_id Id produktu Woo z ustawionym markerem.
	 * @return string `reversed` / `not-marked` / `skipped-trashed`.
	 */
	public static function reverse( int $product_id ): string {
		if ( 'trash' === get_post_status( $product_id ) ) {
			return 'skipped-trashed';
		}

		if ( ! self::is_marked( $product_id ) ) {
			return 'not-marked';
		}

		update_field( ProductWriter::ACF_KEY_ALLEGRO_ENABLED, 1, $product_id );
		delete_post_meta( $product_id, self::META_OFFER_ENDED );

		return 'reversed';
	}

	/**
	 * Czy produkt ma marker „oferta zakończona".
	 *
	 * @param int $product_id Id produktu.
	 * @return bool
	 */
	public static function is_marked( int $product_id ): bool {
		return '' !== (string) get_post_meta( $product_id, self::META_OFFER_ENDED, true );
	}

	/**
	 * Id produktów z ustawionym markerem — kandydaci auto-reversalu (D-15.11).
	 * Wzorzec {@see SyncStockCommand::retry_pending_pushes()} (ten sam kształt
	 * zapytania po marker operacyjny): statusy z koszem włącznie
	 * ({@see ProductWriter::LINK_LOOKUP_STATUSES}) — wywołujący sam musi
	 * odróżnić kosz (D-15.12b), zapytanie tego nie robi za niego.
	 *
	 * @return array<int,int>
	 */
	public static function marked_product_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => ProductWriter::LINK_LOOKUP_STATUSES,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_OFFER_ENDED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- marker operacyjny syncu (D-15.8), wzorzec StockPusher::META_PUSH_PENDING/SyncStockCommand::retry_pending_pushes(); zakończonych ofert są sztuki, nie tysiące.
				'meta_compare'   => 'EXISTS',
			)
		);

		return array_map( 'intval', $ids );
	}
}
