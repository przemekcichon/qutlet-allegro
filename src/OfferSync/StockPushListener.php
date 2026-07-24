<?php
/**
 * Slice OfferSync — nasłuch akcji domenowej core i natychmiastowy push (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use Qutlet\Core\OfferSync\StockEvents;

/**
 * Subskrybuje akcję domenową core `qutlet_product_order_stock_changed`
 * (mostek P-6.2a, `Qutlet\Core\OfferSync\StockEvents`) i NATYCHMIAST wypycha
 * stan do Allegro (D-6.G3: sprzedaż w sklepie nie czeka na cron — minimalizacja
 * okna nadsprzedaży dla towaru jednosztukowego; cofnięcie zamówienia propaguje
 * się tak samo). Awaria pusha → marker zaległego pusha + log; przebieg cronowy
 * `sync-stock` domyka (D-6.2.3/D-6.2.4).
 *
 * Granice repo: hooki Woo hakuje core; my konsumujemy już przełożone zdarzenie
 * PRODUKTOWE — literał akcji bierzemy ze stałej `StockEvents::ACTION` (twarda
 * zależność od core, jak `AllegroLinkMeta`).
 *
 * Logi przez `wc_get_logger()` (WooCommerce = twarda zależność, D-G5): kontekst
 * WWW nie ma stdout komendy, a zdarzenia sprzedażowe muszą zostawiać ślad.
 */
final class StockPushListener {

	/**
	 * Źródło wpisów loggera Woo (WooCommerce → Status → Logs).
	 */
	private const LOG_SOURCE = 'qutlet-allegro-stock-sync';

	/**
	 * Wpina nasłuch. Wołane z bootstrapu (po `dependencies_met()`); dodatkowy
	 * guard na klasę mostka broni przed core SPRZED P-6.2a — wtedy natychmiastowego
	 * pusha po prostu nie ma (stan wyrówna rekoncyliacja `sync-stock --full`),
	 * zamiast fatala przy starcie wtyczki.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( StockEvents::class ) ) {
			return;
		}

		add_action( StockEvents::ACTION, array( $this, 'on_order_stock_changed' ), 10, 4 );
	}

	/**
	 * Zamówienie Woo zmieniło stan produktu (sprzedaż albo cofnięcie) — push.
	 *
	 * Ładunek zdarzenia służy TYLKO logom: pusher czyta stan świeżo z produktu
	 * ({@see StockPusher::push()}), więc wyścig „dwa zdarzenia zanim pierwszy
	 * push wyszedł" kończy się wypchnięciem stanu końcowego, nie pośredniego.
	 *
	 * @param mixed $product_id Id produktu (int wg kontraktu akcji).
	 * @param mixed $new_stock  Nowy stan (do logu).
	 * @param mixed $direction  `reduce`/`restore` (do logu).
	 * @param mixed $order_id   Id zamówienia źródłowego (do logu).
	 * @return void
	 */
	public function on_order_stock_changed( $product_id, $new_stock, $direction, $order_id ): void {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		// Produkt bez klucza powiązania nie pochodzi z Allegro — cisza (to jest
		// normalna sprzedaż produktu spoza kanału, nie anomalia do logowania).
		if ( '' === (string) get_post_meta( $product_id, AllegroLinkMeta::META_OFFER_ID, true ) ) {
			return;
		}

		$context = array( 'source' => self::LOG_SOURCE );
		$prefix  = sprintf(
			'zamówienie #%d (%s) → produkt %d, stan %s',
			(int) $order_id,
			is_string( $direction ) ? $direction : '?',
			$product_id,
			is_numeric( $new_stock ) ? (string) (int) $new_stock : '?'
		);

		// Kosz = wycofany (D-6.2.1): bez pusha, ale ze śladem — sprzedaż
		// wycofanego produktu to sytuacja warta uwagi kuratora.
		if ( 'trash' === get_post_status( $product_id ) ) {
			wc_get_logger()->warning( $prefix . ' — produkt w koszu (wycofany, D-6.2.1), push pominięty.', $context );

			return;
		}

		$result = ( new StockPusher() )->push( $product_id );

		if ( $result['ok'] ) {
			wc_get_logger()->info(
				sprintf( '%s — wypchnięto %d do Allegro (%s).', $prefix, (int) $result['pushed_stock'], (string) $result['environment'] ),
				$context
			);

			return;
		}

		StockPusher::mark_pending( $product_id );
		wc_get_logger()->error(
			sprintf( '%s — push nieudany (%s); oznaczono do ponowienia przez sync-stock.', $prefix, $result['detail'] ),
			$context
		);
	}
}
