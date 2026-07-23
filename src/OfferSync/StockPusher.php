<?php
/**
 * Slice OfferSync — push stanu magazynowego Woo → Allegro (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Auth\TokenRefresher;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use Qutlet\Core\ProductInfo\RawLayerMeta;
use WC_Product;

/**
 * Wypycha AKTUALNY stan magazynowy produktu Woo do powiązanej oferty Allegro
 * (`PATCH /sale/product-offers/{offerId}`, slot `write`). Jedno miejsce z logiką
 * pusha dla obu dróg D-6.G3: natychmiastowej (listener zdarzenia zamówieniowego,
 * kontekst WWW) i cronowej (ponowienie zaległych pushy w `sync-stock`).
 *
 * ## Bezpiecznik D-2.G7 (przeczytany, świadomie NIE wołany)
 * `Environment::assert_offer_content_write_allowed()` broni przed zapisem TREŚCI
 * oferty na produkcji — a PATCH stanu magazynowego jest tam jawnie wskazany jako
 * JEDYNA dozwolona operacja zapisu i bramce NIE podlega. Gwarancją, że nie
 * wypychamy niczego poza stanem, jest konstrukcja ciała żądania w
 * {@see self::push()}: literalnie sam obiekt `stock` — żadne pole treści nie ma
 * jak się tam znaleźć.
 *
 * ## Routing środowiska (D-6.2.2)
 * Push (odpalany hookiem, bez flagi CLI) wyprowadza środowisko z POCHODZENIA
 * produktu — bazy zapisanego `allegro_url` ({@see OfferMapper::environment_from_offer_url()}).
 * Produkt bez rozpoznawalnego pochodzenia → odmowa (nigdy „domyślne środowisko":
 * pomyłka oznaczałaby PATCH cudzej/nieistniejącej oferty w złym świecie).
 *
 * ## Trait poza komendą CLI (świadome rozszerzenie P-6.0)
 * Z traitu {@see AllegroCliSupport} używamy WYŁĄCZNIE metod transportowych
 * `send()`/`error_detail()` — one nie dotykają `WP_CLI` i działają w WWW. Token
 * bierzemy bezpośrednio z {@see TokenRefresher::get_valid()} (zwraca `WP_Error`
 * do obsłużenia), NIE przez traitowe `access_token()`, które kończy proces przez
 * `WP_CLI::error()` — w środku checkoutu klienta to niedopuszczalne. Alternatywą
 * było skopiowanie `send()` — dokładnie to, co P-6.0 wyrugował.
 */
final class StockPusher {

	use AllegroCliSupport;

	/**
	 * Timeout żądania PATCH (sekundy) — {@see AllegroCliSupport::send()} czyta
	 * przez `self::`. Krótki: push natychmiastowy działa w żądaniu WWW (checkout
	 * klienta) i nie wolno mu wisieć; awaria → marker + ponowienie cronem.
	 */
	private const REQUEST_TIMEOUT = 8;

	/**
	 * `meta_key` markera ZALEGŁEGO pusha (wartość: unix timestamp oznaczenia) —
	 * kontrakt §10.5 (VERBATIM). Obecność = cron ma ponowić push AKTUALNEGO stanu
	 * Woo, zanim zastosuje jakikolwiek pull dla produktu (D-6.2.4).
	 */
	public const META_PUSH_PENDING = '_qutlet_allegro_stock_push_pending';

	/**
	 * Po tylu sekundach nieudanego ponawiania marker uznajemy za PORZUCONY, nie
	 * tylko zaległy (recenzja P-6.2b, sesja 2026-07-24): przyczyny takie jak brak
	 * rozpoznawalnego pochodzenia produktu, brak zarządzania stanem albo brak
	 * sekretów w `wp-config.php` NIE ustępują same z kolejnym przebiegiem crona —
	 * bez tego progu marker blokowałby pull dla tego produktu NA ZAWSZE (D-6.2.4
	 * każe pullowi czekać, dopóki marker istnieje), cicho wypadając z synchronizacji
	 * bez żadnej ścieżki odzyskania. Godzina to dziesiątki cykli crona (~2 min) —
	 * z zapasem dla przejściowych awarii sieci/tokenu, zanim uznamy sprawę za
	 * wymagającą interwencji człowieka.
	 */
	private const PENDING_STALE_SECONDS = 3600;

	/**
	 * Domyślna jednostka stanu, gdy verbatim JSON oferty jej nie niesie
	 * (100% snapshotu ma `UNIT`).
	 */
	private const DEFAULT_STOCK_UNIT = 'UNIT';

	/**
	 * Wypycha aktualny stan Woo produktu do powiązanej oferty. Czyta stan ŚWIEŻO
	 * z produktu (nie z ładunku zdarzenia) — między zdarzeniem a pushem stan mógł
	 * się zmienić ponownie i wypchnięcie starej wartości byłoby regresją.
	 *
	 * Sukces kasuje marker zaległego pusha; PORAŻKA GO NIE USTAWIA — o markerze
	 * decyduje wywołujący (listener oznacza, przebieg cronowy tylko ponawia).
	 *
	 * @param int $product_id Id produktu Woo.
	 * @return array{ok:bool,pushed_stock:int|null,environment:string|null,detail:string}
	 */
	public function push( int $product_id ): array {
		// Produkt w koszu = wycofany (D-6.2.1) — nie pushujemy i nie tworzymy.
		if ( 'trash' === get_post_status( $product_id ) ) {
			return $this->failure( null, sprintf( 'produkt %d w koszu — wycofany (D-6.2.1), push pominięty', $product_id ) );
		}

		$offer_id = (string) get_post_meta( $product_id, AllegroLinkMeta::META_OFFER_ID, true );

		if ( '' === $offer_id ) {
			return $this->failure( null, sprintf( 'produkt %d bez %s — nie pochodzi z Allegro', $product_id, AllegroLinkMeta::META_OFFER_ID ) );
		}

		$environment = OfferMapper::environment_from_offer_url(
			(string) get_post_meta( $product_id, 'allegro_url', true )
		);

		if ( null === $environment ) {
			return $this->failure( null, sprintf( 'produkt %d bez rozpoznawalnego pochodzenia (allegro_url) — push odmówiony (D-6.2.2)', $product_id ) );
		}

		$product = wc_get_product( $product_id );
		$stock   = $product instanceof WC_Product ? $product->get_stock_quantity() : null;

		if ( ! is_int( $stock ) ) {
			return $this->failure( $environment, sprintf( 'produkt %d bez zarządzania stanem (stock_quantity=null) — nie ma czego pushować', $product_id ) );
		}

		$config = Environment::for_environment( $environment );

		if ( ! $config->has_credentials( Environment::ROLE_WRITE ) ) {
			return $this->failure( $environment, sprintf( 'brak sekretów slotu %s/%s w wp-config.php', $environment, Environment::ROLE_WRITE ) );
		}

		$tokens = ( new TokenRefresher() )->get_valid( $environment, Environment::ROLE_WRITE );

		if ( is_wp_error( $tokens ) ) {
			return $this->failure( $environment, sprintf( 'brak ważnego tokenu %s/%s: %s', $environment, Environment::ROLE_WRITE, $tokens->get_error_message() ) );
		}

		/*
		 * Ciało = LITERALNIE sam stan (gwarancja D-2.G7 — patrz docblock klasy).
		 * `unit` bierzemy z verbatim JSON oferty (kontrakt §10.3: `stock.unit`
		 * zostaje w blobie) — PATCH z samym `available` zostawiłby pole na łasce
		 * defaultów API, a my znamy oryginał.
		 */
		$response = $this->send(
			'PATCH',
			$config->api_base_url() . '/sale/product-offers/' . rawurlencode( $offer_id ),
			$tokens->access_token(),
			array(
				'stock' => array(
					'available' => max( 0, $stock ),
					'unit'      => $this->stock_unit( $product_id ),
				),
			)
		);

		if ( 200 !== $response['status'] ) {
			return $this->failure(
				$environment,
				sprintf( 'PATCH stanu oferty %s → HTTP %d %s', $offer_id, $response['status'], $this->error_detail( $response ) )
			);
		}

		self::clear_pending( $product_id );

		return array(
			'ok'           => true,
			'pushed_stock' => max( 0, $stock ),
			'environment'  => $environment,
			'detail'       => '',
		);
	}

	/**
	 * Oznacza produkt markerem zaległego pusha (awaria pusha natychmiastowego —
	 * cron ponowi; D-6.2.3).
	 *
	 * @param int $product_id Id produktu.
	 * @return void
	 */
	public static function mark_pending( int $product_id ): void {
		update_post_meta( $product_id, self::META_PUSH_PENDING, (string) time() );
	}

	/**
	 * Kasuje marker zaległego pusha.
	 *
	 * @param int $product_id Id produktu.
	 * @return void
	 */
	public static function clear_pending( int $product_id ): void {
		delete_post_meta( $product_id, self::META_PUSH_PENDING );
	}

	/**
	 * Czy produkt ma marker zaległego pusha.
	 *
	 * @param int $product_id Id produktu.
	 * @return bool
	 */
	public static function is_pending( int $product_id ): bool {
		return '' !== (string) get_post_meta( $product_id, self::META_PUSH_PENDING, true );
	}

	/**
	 * Czy marker zaległego pusha jest PORZUCONY — starszy niż
	 * {@see self::PENDING_STALE_SECONDS} (recenzja P-6.2b: bez tego progu produkt
	 * z trwałą przyczyną awarii — np. brak pochodzenia, brak zarządzania stanem —
	 * blokowałby pull na zawsze). Wywołujący (`SyncStockCommand`) czyści marker i
	 * loguje na poziomie wymagającym uwagi człowieka; sam `StockReconciler` o
	 * porzuceniu nic nie wie — dostaje już wyczyszczony stan.
	 *
	 * @param int $product_id Id produktu.
	 * @return bool False, gdy marker nieobecny (nie jest ani zaległy, ani porzucony).
	 */
	public static function is_abandoned_pending( int $product_id ): bool {
		$marked_at = (string) get_post_meta( $product_id, self::META_PUSH_PENDING, true );

		if ( '' === $marked_at || ! is_numeric( $marked_at ) ) {
			return false;
		}

		return ( time() - (int) $marked_at ) >= self::PENDING_STALE_SECONDS;
	}

	/**
	 * Jednostka stanu z verbatim JSON oferty (`stock.unit`), z bezpiecznym
	 * defaultem — blob mógł jeszcze nie powstać (produkt sprzed pełnego importu).
	 *
	 * @param int $product_id Id produktu.
	 * @return string
	 */
	private function stock_unit( int $product_id ): string {
		$decoded = json_decode( (string) get_post_meta( $product_id, RawLayerMeta::META_OFFER, true ), true );
		$unit    = is_array( $decoded ) ? ( $decoded['stock']['unit'] ?? null ) : null;

		return is_string( $unit ) && '' !== $unit ? $unit : self::DEFAULT_STOCK_UNIT;
	}

	/**
	 * Znormalizowany wynik porażki.
	 *
	 * @param string|null $environment Rozpoznane środowisko (jeśli doszliśmy tak daleko).
	 * @param string      $detail      Opis do logu.
	 * @return array{ok:bool,pushed_stock:int|null,environment:string|null,detail:string}
	 */
	private function failure( ?string $environment, string $detail ): array {
		return array(
			'ok'           => false,
			'pushed_stock' => null,
			'environment'  => $environment,
			'detail'       => $detail,
		);
	}
}
