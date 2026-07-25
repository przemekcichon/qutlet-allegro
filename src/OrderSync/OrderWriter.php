<?php
/**
 * Slice OrderSync — zapis zamówienia Allegro do natywnego `WC_Order` (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

/**
 * Tworzy/aktualizuje natywny `WC_Order` z pełnej zwrotki
 * `GET /order/checkout-forms/{id}` wg mappingu §8 — wyłącznie przez WC CRUD
 * (`$order->set_*()`, `$order->update_meta_data()`), bo pod HPOS zamówienie nie
 * jest `post_meta` (D-6.3.4). Woo/ACF są READ-ONLY: nie dotykamy ich hooków, tylko
 * publicznego API `WC_Order`.
 *
 * ## Idempotencja (D-6.3.6 — upsert po `checkoutForm.id`, nie insert)
 * Strumień `order/events` powtarza to samo zamówienie wielokrotnie (§8d), a kursor
 * przesuwa się dopiero po przetworzeniu całości — więc TEN SAM formularz może wejść
 * tu ponownie. Kluczem powiązania jest indeksowana meta
 * `_qutlet_allegro_checkout_form_id` (kontrakt §12.1). Upsert godzi DWIE NIEZALEŻNE
 * osie (D-6.5.7): TREŚĆ zamówienia (pozycje/adresy/suma — sterowana `revision`) oraz
 * STATUS realizacji (`fulfillment.status`, który `revision` NIE bumpuje):
 * - brak zamówienia + `status = READY_FOR_PROCESSING` → tworzymy (próg D-6.3.1);
 * - brak zamówienia + tylko tranzycja (SENT/CANCELLED/…) → SKIP: nie tworzymy
 *   zamówień z połowy cyklu życia (P-6.5c);
 * - istnieje: TREŚĆ przebudowujemy tylko przy zmianie `revision` (usuwamy pozycje
 *   Allegro i zapisujemy od nowa z autorytatywnej treści), a STATUS ustawiamy, gdy
 *   docelowy z {@see OrderMapper::woo_status()} różni się od bieżącego — NIEZALEŻNIE
 *   od `revision` (D-6.5.7: NO-OP po samej rewizji przełknąłby tranzycję wysyłki);
 * - `revision` i status bez zmian → NO-OP (ponowione zdarzenie);
 * - istnieje w KOSZU → wycofane ręcznie przez człowieka: NIE ruszamy i NIE
 *   odtwarzamy (analogicznie do produktu w koszu, D-6.2.1 — „nie psujemy świadomej
 *   decyzji operatora"). Zgłaszane w podsumowaniu do potwierdzenia jako reguła.
 *
 * ## Kierunek = tylko pull (D-6.5.1)
 * Zapisujemy WYŁĄCZNIE do Woo; zero żądań zapisu do Allegro (slot `read`). Allegro
 * jest źródłem prawdy statusu — pull nadpisuje ręczną zmianę w adminie (D-6.5.2),
 * kosz pozostaje jedynym wyjątkiem „nie ruszamy".
 *
 * ## Granice zapisu i PII (D-6.3.5 / §8g)
 * Zapisujemy tylko zakres funkcjonalny: billing z `buyer`, shipping z `delivery`,
 * płatność, pozycje, suma, notatka. `buyer.personalIdentity`/`buyer.login` NIE
 * trafiają nigdzie; BEZ verbatim blobu zamówienia. Zamówienia są GOŚCINNE —
 * `customer_id = 0`, bez tworzenia/dopasowania kont klientów (to warunkowy P-6.4).
 *
 * ## Kwoty i podatek
 * Kwoty Allegro są STRINGAMI brutto (§8f); ustawiamy je jako totale WPROST
 * ({@see self::money()}), BEZ `calculate_totals()` — to zachowuje wartości Allegro
 * jako źródło prawdy i nie uzależnia importu od konfiguracji stawek Woo. Świadomie
 * NIE rozbijamy pozycji na netto+VAT: `tax.rate` nie ma odpowiednika-meta w §12,
 * a suma brutto pozostaje spójna z `summary.totalToPay` (rozbicie podatku domknie
 * osobny punkt, gdy zajdzie potrzeba — §8e).
 *
 * Literały meta VERBATIM z kontraktu §12; klucz powiązania pozycji z produktem
 * bierzemy ze STAŁEJ core {@see AllegroLinkMeta::META_OFFER_ID} (jedno źródło prawdy).
 *
 * ## Order attribution „Origin" (P-6.6b, kontrakt §12.6, D-6.6.1/D-6.6.2)
 * Osobny mechanizm Woo od `created_via` (wyżej) — rodzina meta natywna
 * `_wc_order_attribution_*` licząca etykietę „Origin" w adminie. {@see self::apply_attribution()}
 * ustawia ją idempotentnie (tylko gdy brak) przy KAŻDYM imporcie/przebudowie treści;
 * {@see self::backfill_attribution()} to wariant z natychmiastowym zapisem dla
 * jednorazowej migracji ({@see BackfillOrderAttributionCommand}, D-6.6.2).
 */
final class OrderWriter {

	/**
	 * Klucz idempotencji: id zamówienia Allegro na `WC_Order` (kontrakt §12.1, VERBATIM).
	 */
	public const META_CHECKOUT_FORM_ID = '_qutlet_allegro_checkout_form_id';

	/**
	 * Rewizja treści zamówienia (kontrakt §12.1, VERBATIM).
	 */
	private const META_REVISION = '_qutlet_allegro_order_revision';

	/**
	 * Punkt odbioru / paczkomat — serializowana tablica (kontrakt §12.1, VERBATIM).
	 */
	private const META_PICKUP_POINT = '_qutlet_allegro_pickup_point';

	/**
	 * Id pozycji Allegro na pozycji `WC_Order_Item_Product` (kontrakt §12.2, VERBATIM).
	 */
	private const META_LINE_ITEM_ID = '_qutlet_allegro_line_item_id';

	/**
	 * Id metody dostawy Allegro na pozycji `WC_Order_Item_Shipping` (kontrakt §12.2, VERBATIM).
	 */
	private const META_DELIVERY_METHOD_ID = '_qutlet_allegro_delivery_method_id';

	/**
	 * Meta key rodzaju źródła atrybucji Origin (kontrakt §12.6, D-6.6.1) — natywny
	 * prefiks WooCommerce `_wc_order_attribution_`, WŁASNOŚĆ core, NIE nasz
	 * `_qutlet_allegro_` prefiks. Publiczny: {@see BackfillOrderAttributionCommand}
	 * filtruje po nim zamówienia jeszcze BEZ atrybucji (`NOT EXISTS`).
	 */
	public const META_ATTRIBUTION_SOURCE_TYPE = '_wc_order_attribution_source_type';

	/**
	 * Meta key źródła (wartość wstawiana do etykiety Origin), jw. — kontrakt §12.6.
	 */
	private const META_ATTRIBUTION_UTM_SOURCE = '_wc_order_attribution_utm_source';

	/**
	 * Slug metody płatności ustawiany na zamówieniu (mapping §8c — payload nie niesie
	 * gotowego sluga Woo, więc stała po naszej stronie).
	 */
	private const PAYMENT_METHOD = 'allegro';

	/**
	 * Znacznik pochodzenia zamówienia (`created_via`) — natywne pole Woo, nie meta;
	 * mapping §8c/§8e (marketplace → źródło zamówienia).
	 */
	private const CREATED_VIA = 'allegro';

	/**
	 * Id transakcyjnych e-maili WooCommerce wyłączanych na czas zapisu. Każda
	 * tranzycja statusu odpalana przez `save()` (utworzenie `pending → processing`,
	 * pull statusu `processing → shipped`/`completed`/`cancelled`) wysyła maile Woo:
	 * `new_order` (admin) oraz `customer_*_order` (do KUPUJĄCEGO). To zamówienia z
	 * Allegro — kupujący dostał już powiadomienia Allegro, a mail z NASZEGO sklepu
	 * byłby działaniem na zewnątrz do realnej osoby; kontakt do kupujących Allegro to
	 * odrębny, warunkowy i prawnie bramkowany punkt (P-6.4), którego pull NIE otwiera.
	 * Zbiór pokrywa wszystkie tranzycje mapowane w P-6.5c (D-6.5.4) i defensywnie
	 * pozostałe maile zamówień.
	 *
	 * @var array<int,string>
	 */
	private const SUPPRESSED_EMAILS = array(
		'new_order',
		'cancelled_order',
		'failed_order',
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_refunded_order',
		'customer_invoice',
		'customer_note',
	);

	/**
	 * Tworzy/aktualizuje `WC_Order` z pełnej zwrotki zamówienia (idempotentnie po
	 * `checkoutForm.id`), godząc oś TREŚCI (rewizja) i oś STATUSU
	 * ({@see OrderMapper::woo_status()}) niezależnie (D-6.5.7). Wołane dla zdarzeń
	 * `READY_FOR_PROCESSING` (tworzenie/treść) ORAZ tranzycji fulfillmentu/anulowania
	 * (`FULFILLMENT_STATUS_CHANGED`/`BUYER_CANCELLED`/`AUTO_CANCELLED`) i toru `--full`.
	 *
	 * @param array<string,mixed> $form Zdekodowana zwrotka `GET /order/checkout-forms/{id}`.
	 * @return array{action:string,order_id:int,warnings:array<int,string>}
	 */
	public function upsert( array $form ): array {
		$warnings         = array();
		$checkout_form_id = OrderMapper::checkout_form_id( $form );

		if ( '' === $checkout_form_id ) {
			return array(
				'action'   => 'failed',
				'order_id' => 0,
				'warnings' => array( 'Zamówienie bez checkoutForm.id — pomijam (brak klucza idempotencji).' ),
			);
		}

		$target_status = OrderMapper::woo_status( $form );
		$existing_id   = $this->find_order_id( $checkout_form_id, $warnings );

		// --- Brak istniejącego zamówienia ---
		if ( null === $existing_id ) {
			// Tworzymy TYLKO dla progu opłacone/gotowe (D-6.3.1). Sama tranzycja
			// (SENT/CANCELLED/…) bez zaimportowanego zamówienia → skip: nie tworzymy
			// zamówień z połowy cyklu życia (P-6.5c).
			if ( ! OrderMapper::is_ready( $form ) ) {
				return array(
					'action'   => 'skipped-no-order',
					'order_id' => 0,
					'warnings' => $warnings,
				);
			}

			$order = new WC_Order();
			$this->apply( $order, $form, $warnings );
			// Nowe zamówienie READY: status z mappera; przy nierozpoznanym
			// `fulfillment` domyślny próg `processing` (zamówienie jest opłacone).
			$order->set_status( null !== $target_status ? $target_status : OrderMapper::WC_PROCESSING );
			$this->save_order( $order );

			return array(
				'action'   => 'created',
				'order_id' => $order->get_id(),
				'warnings' => $warnings,
			);
		}

		// --- Istniejące zamówienie ---
		$order = wc_get_order( $existing_id );

		if ( ! $order instanceof WC_Order ) {
			return array(
				'action'   => 'failed',
				'order_id' => $existing_id,
				'warnings' => array_merge( $warnings, array( sprintf( 'Zamówienie %d nie jest obiektem WC_Order — pomijam.', $existing_id ) ) ),
			);
		}

		// Kosz = świadome wycofanie przez człowieka (analogia D-6.2.1) — zero
		// zapisów, żadnego odtwarzania (jedyny wyjątek od D-6.5.2).
		if ( 'trash' === $order->get_status() ) {
			return array(
				'action'   => 'skipped-trashed',
				'order_id' => $existing_id,
				'warnings' => $warnings,
			);
		}

		// Oś TREŚCI (rewizja): zmiana `revision` = treść zamówienia się zmieniła (§8e).
		$incoming_revision = OrderMapper::revision( $form );
		$stored_revision   = (string) $order->get_meta( self::META_REVISION );
		$content_changed   = '' !== $incoming_revision && $incoming_revision !== $stored_revision;

		// Oś STATUSU: liczona z mappingu, stosowana NIEZALEŻNIE od rewizji (D-6.5.7 —
		// zmiana fulfillmentu NIE bumpuje `revision`, więc NO-OP po samej rewizji
		// przełknąłby tranzycję wysyłki). `null` = brak mapowania (RETURNED/nieznane)
		// → zostaw bieżący status + log (rozróżnienie w {@see self::note_unmapped_status()}).
		$status_changed = null !== $target_status && $target_status !== $order->get_status();

		if ( null === $target_status ) {
			$this->note_unmapped_status( $form, $warnings );
		}

		if ( ! $content_changed && ! $status_changed ) {
			return array(
				'action'   => 'unchanged',
				'order_id' => $existing_id,
				'warnings' => $warnings,
			);
		}

		if ( $content_changed ) {
			// Przebudowa pozycji od zera, żeby ponowny zapis nie duplikował pozycji.
			$this->remove_allegro_items( $order );
			$this->apply( $order, $form, $warnings );
		}

		if ( $status_changed ) {
			$order->set_status( $target_status );
		}

		$this->save_order( $order );

		return array(
			'action'   => $content_changed ? 'updated' : 'status-updated',
			'order_id' => $order->get_id(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Zapisuje `WC_Order`, gasząc na CZAS zapisu dwa skutki uboczne Woo, których pull
	 * NIE może wywołać (filtry zdejmowane w `finally`, żeby globalny stan wrócił do
	 * normy):
	 *  1) maile transakcyjne ({@see self::SUPPRESSED_EMAILS}) — outward-facing do
	 *     realnej osoby, poza zakresem (D-6.3.5 / P-6.4). Dotyczy KAŻDEJ tranzycji
	 *     statusu (utworzenie i pull SENT/PICKED_UP/CANCELLED — D-6.5.4);
	 *  2) automatyczne zdjęcie stanu magazynowego. Stan produktów z Allegro jest
	 *     OWNED przez pull `sync-stock` (D-6.G3), nie przez lokalną sprzedaż; Woo
	 *     `woocommerce_reduce_order_item_stock` mostkuje się w core na akcję, którą
	 *     `StockPushListener` PUSHUJE do Allegro — bez tej blokady zapis zdejmowałby
	 *     stan po raz drugi (podwójne dekrementowanie + pętla zwrotna).
	 *
	 * @param WC_Order $order Zamówienie do zapisania.
	 * @return void
	 */
	private function save_order( WC_Order $order ): void {
		$this->suppress_transactional_emails();
		add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 );

		try {
			$order->save();
		} finally {
			remove_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 );
			$this->restore_transactional_emails();
		}
	}

	/**
	 * Dokłada ostrzeżenie, gdy {@see OrderMapper::woo_status()} nie dał mapowania
	 * (`null`) dla istniejącego zamówienia — rozróżniając trzy przypadki: zwrot
	 * (D-6.5.3, poza zakresem P-6.5), udokumentowany `fulfillment = CANCELLED`
	 * (anulowanie łapie oś priorytetowa `status`, D-6.5.4) oraz wartość NIEROZPOZNANĄ
	 * (Allegro dodaje statusy z czasem). Pusty `fulfillment` (brak sygnału) → cicho.
	 *
	 * @param array<string,mixed> $form     Pełna zwrotka zamówienia.
	 * @param array<int,string>   $warnings Akumulator ostrzeżeń (przez referencję).
	 * @return void
	 */
	private function note_unmapped_status( array $form, array &$warnings ): void {
		$fulfillment = OrderMapper::fulfillment_status( $form );

		if ( OrderMapper::FULFILLMENT_RETURNED === $fulfillment ) {
			$warnings[] = 'fulfillment.status=RETURNED — zwrot poza zakresem P-6.5 (D-6.5.3): status bez zmiany, obsłuży osobny punkt.';

			return;
		}

		if ( OrderMapper::FULFILLMENT_CANCELLED === $fulfillment ) {
			// Udokumentowany enum — nie „nieznany". Anulowanie rozstrzyga oś
			// PRIORYTETOWA `status = CANCELLED` (D-6.5.4); na osi fulfillment bez zmiany.
			$warnings[] = 'fulfillment.status=CANCELLED bez status=CANCELLED — anulowanie steruje osią „status" (D-6.5.4): status bez zmiany.';

			return;
		}

		if ( '' !== $fulfillment ) {
			$warnings[] = sprintf( 'Nierozpoznany fulfillment.status=„%s" — status bez zmiany (D-6.5.4); Allegro mogło dodać nowy status realizacji.', $fulfillment );
		}
	}

	/**
	 * Zapisuje wszystkie pola zamówienia na obiekcie `WC_Order` (bez `save()` —
	 * wołający zapisuje raz). Dla przebiegu aktualizacji pozycje są już usunięte.
	 *
	 * @param WC_Order            $order    Nowy albo istniejący obiekt zamówienia.
	 * @param array<string,mixed> $form     Pełna zwrotka zamówienia.
	 * @param array<int,string>   $warnings Akumulator ostrzeżeń (przekazywany przez referencję).
	 * @return void
	 */
	private function apply( WC_Order $order, array $form, array &$warnings ): void {
		// Gość (D-6.3.5): bez konta klienta Woo.
		$order->set_customer_id( 0 );
		$order->set_created_via( self::CREATED_VIA );

		$this->apply_billing( $order, $form );
		$this->apply_shipping( $order, $form );
		$this->apply_payment( $order, $form );

		$created = OrderMapper::date_created( $form );

		if ( null !== $created ) {
			$order->set_date_created( $created );
		}

		$note = OrderMapper::customer_note( $form );

		if ( null !== $note ) {
			$order->set_customer_note( $note );
		}

		foreach ( OrderMapper::line_items( $form ) as $line_item ) {
			$this->add_line_item( $order, $line_item, $warnings );
		}

		$this->add_shipping_item( $order, $form );

		$total = OrderMapper::total( $form );

		if ( null !== $total ) {
			$order->set_total( $this->money( $total ) );
		}

		// UWAGA: status ustawia {@see self::upsert()} (oś statusu niezależna od treści,
		// D-6.5.7) — `apply()` buduje wyłącznie treść i meta.
		$this->apply_meta( $order, $form );
	}

	/**
	 * Billing z `buyer` (mapping §8c). `state` puste — Allegro nie niesie województwa
	 * kupującego (§8c).
	 *
	 * @param WC_Order            $order Zamówienie.
	 * @param array<string,mixed> $form  Pełna zwrotka zamówienia.
	 * @return void
	 */
	private function apply_billing( WC_Order $order, array $form ): void {
		$billing = OrderMapper::billing( $form );

		$order->set_billing_first_name( $billing['first_name'] );
		$order->set_billing_last_name( $billing['last_name'] );
		$order->set_billing_email( $billing['email'] );
		$order->set_billing_phone( $billing['phone'] );
		$order->set_billing_company( $billing['company'] );
		$order->set_billing_address_1( $billing['address_1'] );
		$order->set_billing_city( $billing['city'] );
		$order->set_billing_postcode( $billing['postcode'] );
		$order->set_billing_country( $billing['country'] );
	}

	/**
	 * Shipping z `delivery.address` (mapping §8c).
	 *
	 * @param WC_Order            $order Zamówienie.
	 * @param array<string,mixed> $form  Pełna zwrotka zamówienia.
	 * @return void
	 */
	private function apply_shipping( WC_Order $order, array $form ): void {
		$shipping = OrderMapper::shipping( $form );

		$order->set_shipping_first_name( $shipping['first_name'] );
		$order->set_shipping_last_name( $shipping['last_name'] );
		$order->set_shipping_company( $shipping['company'] );
		$order->set_shipping_address_1( $shipping['address_1'] );
		$order->set_shipping_city( $shipping['city'] );
		$order->set_shipping_postcode( $shipping['postcode'] );
		$order->set_shipping_country( $shipping['country'] );
		$order->set_shipping_phone( $shipping['phone'] );
	}

	/**
	 * Płatność (mapping §8c): stała metoda „allegro" + tytuł, id transakcji, data
	 * zapłaty.
	 *
	 * @param WC_Order            $order Zamówienie.
	 * @param array<string,mixed> $form  Pełna zwrotka zamówienia.
	 * @return void
	 */
	private function apply_payment( WC_Order $order, array $form ): void {
		$order->set_payment_method( self::PAYMENT_METHOD );
		$order->set_payment_method_title( OrderMapper::payment_title() );

		$transaction_id = OrderMapper::payment_transaction_id( $form );

		if ( null !== $transaction_id ) {
			$order->set_transaction_id( $transaction_id );
		}

		$date_paid = OrderMapper::payment_date_paid( $form );

		if ( null !== $date_paid ) {
			$order->set_date_paid( $date_paid );
		}
	}

	/**
	 * Dyskretne meta zamówienia (kontrakt §12.1) przez WC CRUD (D-6.3.4).
	 *
	 * @param WC_Order            $order Zamówienie.
	 * @param array<string,mixed> $form  Pełna zwrotka zamówienia.
	 * @return void
	 */
	private function apply_meta( WC_Order $order, array $form ): void {
		$order->update_meta_data( self::META_CHECKOUT_FORM_ID, OrderMapper::checkout_form_id( $form ) );

		$revision = OrderMapper::revision( $form );

		if ( '' !== $revision ) {
			$order->update_meta_data( self::META_REVISION, $revision );
		}

		// Punkt odbioru tylko gdy obecny (dostawa pod adres → brak meta, §12.1/§8f).
		$pickup_point = OrderMapper::pickup_point( $form );

		if ( null !== $pickup_point ) {
			$order->update_meta_data( self::META_PICKUP_POINT, $pickup_point );
		} else {
			$order->delete_meta_data( self::META_PICKUP_POINT );
		}

		$this->apply_attribution( $order );
	}

	/**
	 * Ustawia atrybucję Origin „Allegro" (kontrakt §12.6, D-6.6.1) — TYLKO gdy
	 * zamówienie jeszcze nie ma `source_type` (idempotentne; nie nadpisuje istniejącej
	 * realnej atrybucji, gdyby kiedyś powstała inną drogą niż ten import). WooCommerce
	 * liczy etykietę Origin z `source_type`+`utm_source`
	 * (`OrderAttributionMeta::get_origin_label()`); `referral` +
	 * {@see OrderMapper::payment_title()} („Allegro") dają „Referral: Allegro" — goły
	 * napis „Allegro" bez prefiksu nieosiągalny bez globalnego filtra warunkowanego
	 * per-zamówienie (odrzucone, kontrakt §12.6).
	 *
	 * Wołane z {@see self::apply_meta()} (nowe zamówienia i przebudowa treści) ORAZ
	 * przez {@see BackfillOrderAttributionCommand::__invoke()} (D-6.6.2 — jednorazowe
	 * uzupełnienie atrybucji na zamówieniach zaimportowanych PRZED P-6.6b).
	 *
	 * @param WC_Order $order Zamówienie.
	 * @return bool Czy meta zostały ustawione (`false` = zamówienie miało już atrybucję).
	 */
	public function apply_attribution( WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( self::META_ATTRIBUTION_SOURCE_TYPE ) ) {
			return false;
		}

		$order->update_meta_data( self::META_ATTRIBUTION_SOURCE_TYPE, OrderMapper::ATTRIBUTION_SOURCE_TYPE );
		$order->update_meta_data( self::META_ATTRIBUTION_UTM_SOURCE, OrderMapper::payment_title() );

		return true;
	}

	/**
	 * Wariant {@see self::apply_attribution()} z natychmiastowym zapisem — dla
	 * jednorazowego backfillu (D-6.6.2) na zamówieniach spoza bieżącego przebiegu
	 * `upsert()`. Zapis przez {@see self::save_order()} (te same gwarancje: bez maili
	 * transakcyjnych, bez zdjęcia stanu — choć backfill nie zmienia statusu/treści,
	 * jeden choke-point na WSZYSTKIE zapisy w tej klasie jest bezpieczniejszy niż
	 * wyjątek `$order->save()` wprost).
	 *
	 * @param WC_Order $order Zamówienie.
	 * @return bool Czy zamówienie zostało zaktualizowane (`false` = miało już atrybucję).
	 */
	public function backfill_attribution( WC_Order $order ): bool {
		if ( ! $this->apply_attribution( $order ) ) {
			return false;
		}

		$this->save_order( $order );

		return true;
	}

	/**
	 * Dodaje pozycję produktową (mapping §8c). Powiązanie z produktem Woo po
	 * `offer.id` (klucz §4a / kontrakt §10.1). Brak produktu (oferta nieimportowana)
	 * → pozycja BEZ powiązania (po nazwie i cenie z payloadu) + ostrzeżenie (D-6.3.2):
	 * realna opłacona sprzedaż nie może zniknąć.
	 *
	 * @param WC_Order                                                                                        $order     Zamówienie.
	 * @param array{offer_id:string,name:string,quantity:int,subtotal:float,total:float,line_item_id:string} $line_item Znormalizowana pozycja.
	 * @param array<int,string>                                                                               $warnings  Akumulator ostrzeżeń.
	 * @return void
	 */
	private function add_line_item( WC_Order $order, array $line_item, array &$warnings ): void {
		$item = new WC_Order_Item_Product();

		$name = '' !== $line_item['name'] ? $line_item['name'] : 'Pozycja Allegro';
		$item->set_name( $name );
		$item->set_quantity( $line_item['quantity'] );

		if ( '' !== $line_item['offer_id'] ) {
			$product_id = $this->find_product_id_by_offer( $line_item['offer_id'], $warnings );

			if ( null !== $product_id ) {
				$product = wc_get_product( $product_id );

				if ( $product instanceof WC_Product ) {
					$item->set_product_id( $product_id );
				} else {
					$warnings[] = sprintf( 'Oferta %s → produkt %d nie jest produktem Woo — pozycja bez powiązania.', $line_item['offer_id'], $product_id );
				}
			} else {
				$warnings[] = sprintf( 'Oferta %s bez produktu w Woo — pozycja bez powiązania (D-6.3.2).', $line_item['offer_id'] );
			}
		}

		// Totale WPROST z payloadu (brutto), bez rozbicia na VAT (patrz docblock klasy).
		$item->set_subtotal( $this->money( $line_item['subtotal'] ) );
		$item->set_total( $this->money( $line_item['total'] ) );
		$item->set_subtotal_tax( '0' );
		$item->set_total_tax( '0' );

		if ( '' !== $line_item['line_item_id'] ) {
			$item->update_meta_data( self::META_LINE_ITEM_ID, $line_item['line_item_id'] );
		}

		$order->add_item( $item );
	}

	/**
	 * Dodaje pozycję wysyłki z `delivery` (mapping §8c). Nazwa = `delivery.method.name`,
	 * koszt = `delivery.cost.amount`, id metody Allegro → meta (kontrakt §12.2).
	 * Pomijana, gdy payload nie niesie ani nazwy, ani kosztu.
	 *
	 * @param WC_Order            $order Zamówienie.
	 * @param array<string,mixed> $form  Pełna zwrotka zamówienia.
	 * @return void
	 */
	private function add_shipping_item( WC_Order $order, array $form ): void {
		$name = OrderMapper::delivery_method_name( $form );
		$cost = OrderMapper::delivery_cost( $form );

		if ( null === $name && null === $cost ) {
			return;
		}

		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( null !== $name ? $name : 'Dostawa Allegro' );
		// `method_id` Woo to slug metody wysyłki (np. „flat_rate"); UUID Allegro nie
		// jest metodą Woo, więc idzie do meta (§12.2), a slug oznaczamy stałą „allegro".
		$item->set_method_id( self::PAYMENT_METHOD );
		$item->set_total( $this->money( null !== $cost ? $cost : 0.0 ) );

		$method_id = OrderMapper::delivery_method_id( $form );

		if ( null !== $method_id ) {
			$item->update_meta_data( self::META_DELIVERY_METHOD_ID, $method_id );
		}

		$order->add_item( $item );
	}

	/**
	 * Usuwa pozycje produktowe i wysyłki przed przebudową zamówienia (aktualizacja
	 * po zmianie `revision`) — inaczej ponowny zapis zdublowałby pozycje.
	 *
	 * @param WC_Order $order Zamówienie.
	 * @return void
	 */
	private function remove_allegro_items( WC_Order $order ): void {
		foreach ( $order->get_items( array( 'line_item', 'shipping' ) ) as $item_id => $item ) {
			unset( $item );
			$order->remove_item( $item_id );
		}
	}

	/**
	 * Szuka zamówienia po kluczu idempotencji `_qutlet_allegro_checkout_form_id`
	 * (kontrakt §12.1). Statusy JAWNIE z koszem — trashowane zamówienie musi zostać
	 * ZNALEZIONE, żeby upsert je POMINĄŁ zamiast tworzyć duplikat (analogia do
	 * wyszukania produktu w `ProductWriter::find_product_id`, D-6.2.1).
	 *
	 * `wc_get_orders` (nie surowy `WP_Query`) — działa spójnie pod HPOS i legacy.
	 * Zwraca OBIEKTY (`->get_id()`), nie `return=>'ids'` — id z tej ścieżki są typu
	 * `WC_Order` w stubach Woo, więc obiekt jest jednocześnie type-safe i tani (limit 2).
	 *
	 * @param string            $checkout_form_id Id zamówienia Allegro.
	 * @param array<int,string> $warnings         Akumulator ostrzeżeń (duplikaty klucza).
	 * @return int|null Id zamówienia Woo albo null (brak → utworzenie).
	 */
	private function find_order_id( string $checkout_form_id, array &$warnings ): ?int {
		$found = wc_get_orders(
			array(
				'limit'      => 2,
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- klucz idempotencji importu zamówień (kontrakt §12.1); zamówień z tym kluczem są pojedyncze sztuki na przebieg.
					array(
						'key'   => self::META_CHECKOUT_FORM_ID,
						'value' => $checkout_form_id,
					),
				),
			)
		);

		if ( ! is_array( $found ) || array() === $found ) {
			return null;
		}

		$first = $found[0];

		if ( count( $found ) > 1 ) {
			$warnings[] = sprintf( 'Więcej niż jedno zamówienie z checkoutForm.id=%s — aktualizuję pierwsze (%d).', $checkout_form_id, $first->get_id() );
		}

		return $first->get_id();
	}

	/**
	 * Szuka produktu Woo po kluczu powiązania `_qutlet_allegro_offer_id` (stała core
	 * {@see AllegroLinkMeta::META_OFFER_ID}). Status `any` (bez kosza) — pozycję
	 * wiążemy tylko z żywym produktem; produkt w koszu = brak powiązania (D-6.3.2).
	 * Własne wyszukanie (nie współpracownik slice'a stanów) trzyma slice `OrderSync/`
	 * samowystarczalnym.
	 *
	 * @param string            $offer_id Id oferty Allegro.
	 * @param array<int,string> $warnings Akumulator ostrzeżeń (duplikaty klucza).
	 * @return int|null Id produktu albo null (brak → pozycja bez powiązania).
	 */
	private function find_product_id_by_offer( string $offer_id, array &$warnings ): ?int {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'meta_key'       => AllegroLinkMeta::META_OFFER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- klucz powiązania oferty (kontrakt §10.1); indeks pod to wyszukanie to decyzja FAZY 6.
				'meta_value'     => $offer_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( array() === $found ) {
			return null;
		}

		if ( count( $found ) > 1 ) {
			$warnings[] = sprintf( 'Więcej niż jeden produkt z offer_id=%s — wiążę pozycję z pierwszym (%d).', $offer_id, (int) $found[0] );
		}

		return (int) $found[0];
	}

	/**
	 * Wyłącza transakcyjne maile Woo na czas zapisu przez filtr
	 * `woocommerce_email_enabled_{id}` (sprawdzany w `WC_Email::is_enabled()` przy
	 * wysyłce). Filtr, nie `remove_action` na `WC()->mailer()` — jest odporny na to,
	 * które dokładnie hooki tranzycji podpina dana wersja Woo, i nie zależy od
	 * inicjalizacji mailera. Zdejmowany w {@see self::restore_transactional_emails()}.
	 *
	 * @return void
	 */
	private function suppress_transactional_emails(): void {
		foreach ( self::SUPPRESSED_EMAILS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false', 999 );
		}
	}

	/**
	 * Przywraca transakcyjne maile Woo po zapisie (zdejmuje filtry z
	 * {@see self::suppress_transactional_emails()}) — stan globalny wraca do normy,
	 * żeby import nie wyciszył maili dla reszty tego samego żądania/przebiegu crona.
	 *
	 * @return void
	 */
	private function restore_transactional_emails(): void {
		foreach ( self::SUPPRESSED_EMAILS as $email_id ) {
			remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false', 999 );
		}
	}

	/**
	 * Formatuje kwotę do stringa z kropką dziesiętną (jak `ProductWriter`): `(string)float`
	 * na PHP < 8.0 respektuje `LC_NUMERIC` (możliwe „161,1"), a Woo oczekuje kropki.
	 *
	 * @param float $value Kwota w PLN.
	 * @return string
	 */
	private function money( float $value ): string {
		return number_format( $value, 2, '.', '' );
	}
}
