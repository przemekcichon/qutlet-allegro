<?php
/**
 * Slice OrderSync — czyste funkcje mapujące zamówienie Allegro na model `WC_Order` (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

/**
 * Ekstrakcja i transformacja danych z pełnej zwrotki
 * `GET /order/checkout-forms/{checkoutFormId}` według mappingu FAZY 4
 * (`docs/mapping-allegro.md` §8) — bez żadnych zapisów. Wszystkie klucze JSON
 * VERBATIM z realnych (zredagowanych) próbek P-3.3; zapis do `WC_Order` robi
 * {@see OrderWriter}, orkiestrację {@see SyncOrdersCommand}.
 *
 * Klasa celowo BEZ wywołań WP — czysta transformacja, testowalna PHPUnitem bez
 * środowiska WordPressa (jak `OfferSync\OfferMapper`).
 *
 * ## Pułapki kształtu (mapping §8f — czytane z próbki, nie z pamięci)
 * - Kwoty i `tax.rate` to STRINGI (`"149.00"`, `"23.00"`) → `(float)` po naszej
 *   stronie ({@see self::amount()}), nigdy porównanie stringów.
 * - Kod pocztowy KUPUJĄCEGO to `buyer.address.postCode`, ODBIORCY —
 *   `delivery.address.zipCode`: RÓŻNE nazwy klucza dla tego samego pojęcia.
 * - `delivery.pickupPoint` bywa `null` (kurier pod adres) albo pełnym obiektem
 *   (paczkomat/punkt) — {@see self::pickup_point()} znosi oba.
 * - Autorytatywny status zamówienia to `status` (nie `events[].type`) — próg
 *   tworzenia `WC_Order` gate'uje {@see self::is_ready()} (D-6.3.1).
 * - Status Woo liczymy z DWÓCH osi (`status` + `fulfillment.status`) w
 *   {@see self::woo_status()} (D-6.5.4) — oś `status = CANCELLED` ma priorytet,
 *   nierozpoznany `fulfillment` daje „bez zmiany".
 */
final class OrderMapper {

	/**
	 * Jedyny status zamówienia, dla którego tworzymy `WC_Order` (D-6.3.1): opłacone
	 * i gotowe do realizacji. VERBATIM z pola `status` próbki (mapping §8c).
	 */
	public const STATUS_READY = 'READY_FOR_PROCESSING';

	/**
	 * Terminalny status osi `status` (mapping §8c): zamówienie anulowane. Ma
	 * PRIORYTET nad osią `fulfillment.status` (D-6.5.4). Wartość VERBATIM z enumów
	 * dokumentacji Allegro (event `BUYER_CANCELLED`/`AUTO_CANCELLED`).
	 */
	private const STATUS_CANCELLED = 'CANCELLED';

	/**
	 * Wartości osi `fulfillment.status` (mapping §8c, enumy z dokumentacji Allegro)
	 * mające odwzorowanie w Woo (D-6.5.4). VERBATIM, case-sensitive.
	 */
	private const FULFILLMENT_NEW               = 'NEW';
	private const FULFILLMENT_PROCESSING        = 'PROCESSING';
	private const FULFILLMENT_READY_FOR_SHIPMENT = 'READY_FOR_SHIPMENT';
	private const FULFILLMENT_SENT              = 'SENT';
	private const FULFILLMENT_READY_FOR_PICKUP  = 'READY_FOR_PICKUP';
	private const FULFILLMENT_PICKED_UP         = 'PICKED_UP';

	/**
	 * `fulfillment.status = RETURNED` — zwrot; POZA zakresem P-6.5 (D-6.5.3): zmienia
	 * się automatycznie i żyje na osobnych endpointach (`/order/customer-returns`,
	 * `/payments/refunds`). {@see self::woo_status()} zwraca dla niego `null` (log +
	 * skip po stronie wołającego); stała nazwana, by odróżnić go od wartości NIEZNANEJ.
	 */
	public const FULFILLMENT_RETURNED = 'RETURNED';

	/**
	 * `fulfillment.status = CANCELLED` — udokumentowana wartość osi realizacji (§8c),
	 * ale anulowanie łapie oś PRIORYTETOWA `status = CANCELLED` (D-6.5.4), więc na osi
	 * fulfillment mapuje się na „bez zmiany" (`null`). Stała nazwana, by wołający NIE
	 * mylił jej z wartością NIEZNANĄ (rozróżnienie w logu — {@see OrderWriter::note_unmapped_status()}).
	 */
	public const FULFILLMENT_CANCELLED = 'CANCELLED';

	/**
	 * Docelowe slugi statusów `WC_Order` BEZ prefiksu `wc-` (forma, jaką zwraca
	 * `WC_Order::get_status()` i przyjmuje `set_status()` — prefiks normalizuje sam
	 * Woo). VERBATIM z instalacji WooCommerce oraz kontraktu §12.5:
	 * - `wc-processing`/`wc-completed`/`wc-cancelled` = natywne statusy Woo;
	 * - `wc-shipped` (unprefixed `shipped`) = własny status rejestrowany przez
	 *   `qutlet-core` ({@see \Qutlet\Core\OrderSync\OrderStatuses::STATUS_UNPREFIXED},
	 *   D-6.5.5). Powtórzony tu jako literał (nie referencja do core), bo `OrderMapper`
	 *   to czysta klasa testowana bez autoloadera core — jedno źródło literału to
	 *   kontrakt §12.5, cytowany po obu stronach.
	 */
	public const WC_PROCESSING = 'processing';
	public const WC_SHIPPED    = 'shipped';
	public const WC_COMPLETED  = 'completed';
	public const WC_CANCELLED  = 'cancelled';

	/**
	 * Czy zamówienie jest opłacone i gotowe do realizacji (D-6.3.1 — próg tworzenia
	 * `WC_Order`). Sprawdzamy autorytatywny `status` z checkout-form, nie typ
	 * zdarzenia (mapping §8d: zdarzenie tylko sygnalizuje, prawdę daje checkout-form).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka `GET /order/checkout-forms/{id}`.
	 * @return bool
	 */
	public static function is_ready( array $form ): bool {
		return self::STATUS_READY === self::str( $form['status'] ?? null );
	}

	/**
	 * Wartość osi realizacji `fulfillment.status` (mapping §8c) — pusty string, gdy
	 * brak (np. wariant zdarzenia bez sekcji `fulfillment`). Odczyt VERBATIM.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka `GET /order/checkout-forms/{id}`.
	 * @return string
	 */
	public static function fulfillment_status( array $form ): string {
		$fulfillment = is_array( $form['fulfillment'] ?? null ) ? $form['fulfillment'] : array();

		return self::str( $fulfillment['status'] ?? null );
	}

	/**
	 * Kolaps DWÓCH osi stanu Allegro (`status` + `fulfillment.status`) na JEDEN slug
	 * statusu `WC_Order` (BEZ prefiksu `wc-`, D-6.5.4). Czysta funkcja — pełny kontrakt
	 * mapowania w jednym miejscu, testowany PHPUnitem bez WordPressa.
	 *
	 * Reguła (priorytet z góry na dół):
	 * 1. `status = CANCELLED` → `cancelled` — oś `status` jest TERMINALNA i ma
	 *    priorytet nad `fulfillment` (anulowanie wygrywa z każdą realizacją).
	 * 2. `fulfillment = SENT`/`READY_FOR_PICKUP` → `shipped` — wysłane / czeka na
	 *    odbiór. `READY_FOR_PICKUP` jest PO `SENT` w cyklu paczkomatu
	 *    (`SENT → READY_FOR_PICKUP → PICKED_UP`), więc mapowanie na `processing`
	 *    cofnęłoby status — oba = „wysłane".
	 * 3. `fulfillment = PICKED_UP` → `completed` — odebrane = zrealizowane.
	 * 4. `fulfillment = NEW`/`PROCESSING`/`READY_FOR_SHIPMENT` → `processing`, ale
	 *    TYLKO gdy `status = READY_FOR_PROCESSING` (próg opłacone/gotowe, D-6.3.1);
	 *    inaczej brak mapowania (`null`).
	 * 5. `fulfillment = RETURNED` (D-6.5.3) oraz KAŻDA nierozpoznana wartość →
	 *    `null` = „bez zmiany" (Allegro dodaje nowe statusy z czasem; nieznanej
	 *    wartości NIE mapujemy na `processing`, bo cofnęłaby już-wysłane zamówienie).
	 *    Wołający loguje `null` wg {@see self::fulfillment_status()} (RETURNED vs nieznane).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka `GET /order/checkout-forms/{id}`.
	 * @return string|null Slug statusu Woo (bez `wc-`) albo `null` = zostaw bieżący.
	 */
	public static function woo_status( array $form ): ?string {
		// (1) Oś `status` terminalna ma priorytet nad `fulfillment` (D-6.5.4).
		if ( self::STATUS_CANCELLED === self::str( $form['status'] ?? null ) ) {
			return self::WC_CANCELLED;
		}

		switch ( self::fulfillment_status( $form ) ) {
			case self::FULFILLMENT_SENT:
			case self::FULFILLMENT_READY_FOR_PICKUP:
				return self::WC_SHIPPED;

			case self::FULFILLMENT_PICKED_UP:
				return self::WC_COMPLETED;

			case self::FULFILLMENT_NEW:
			case self::FULFILLMENT_PROCESSING:
			case self::FULFILLMENT_READY_FOR_SHIPMENT:
				// Próg tworzenia/opłacone: `processing` tylko przy autorytatywnym
				// `status = READY_FOR_PROCESSING` (D-6.3.1); inaczej brak zmiany.
				return self::is_ready( $form ) ? self::WC_PROCESSING : null;

			default:
				// RETURNED (poza zakresem, D-6.5.3) i wartości nieznane: bez zmiany.
				return null;
		}
	}

	/**
	 * Id zamówienia Allegro = klucz idempotencji importu (`checkoutForm.id`,
	 * mapping §8c/§8e, kontrakt §12.1). Time UUID, traktowany jako string opaque.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string Pusty string, gdy brak `id`.
	 */
	public static function checkout_form_id( array $form ): string {
		return self::str( $form['id'] ?? null );
	}

	/**
	 * Rewizja treści zamówienia (`revision`, mapping §8c/§8e, kontrakt §12.1) —
	 * zmiana wartości = treść zamówienia się zmieniła (wykrycie przy pollingu).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string Pusty string, gdy brak `revision`.
	 */
	public static function revision( array $form ): string {
		return self::str( $form['revision'] ?? null );
	}

	/**
	 * Adres rozliczeniowy (billing) z `buyer` + `buyer.address` (mapping §8c).
	 * UWAGA: kod pocztowy pod `postCode` (kupujący), nie `zipCode` (§8f).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return array{first_name:string,last_name:string,email:string,phone:string,company:string,address_1:string,city:string,postcode:string,country:string}
	 */
	public static function billing( array $form ): array {
		$buyer   = is_array( $form['buyer'] ?? null ) ? $form['buyer'] : array();
		$address = is_array( $buyer['address'] ?? null ) ? $buyer['address'] : array();

		return array(
			'first_name' => self::str( $buyer['firstName'] ?? null ),
			'last_name'  => self::str( $buyer['lastName'] ?? null ),
			'email'      => self::str( $buyer['email'] ?? null ),
			'phone'      => self::str( $buyer['phoneNumber'] ?? null ),
			'company'    => self::str( $buyer['companyName'] ?? null ),
			'address_1'  => self::str( $address['street'] ?? null ),
			'city'       => self::str( $address['city'] ?? null ),
			'postcode'   => self::str( $address['postCode'] ?? null ),
			'country'    => self::str( $address['countryCode'] ?? null ),
		);
	}

	/**
	 * Adres wysyłkowy (shipping) z `delivery.address` (mapping §8c).
	 * UWAGA: kod pocztowy pod `zipCode` (odbiorca), nie `postCode` (§8f).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return array{first_name:string,last_name:string,company:string,address_1:string,city:string,postcode:string,country:string,phone:string}
	 */
	public static function shipping( array $form ): array {
		$delivery = is_array( $form['delivery'] ?? null ) ? $form['delivery'] : array();
		$address  = is_array( $delivery['address'] ?? null ) ? $delivery['address'] : array();

		return array(
			'first_name' => self::str( $address['firstName'] ?? null ),
			'last_name'  => self::str( $address['lastName'] ?? null ),
			'company'    => self::str( $address['companyName'] ?? null ),
			'address_1'  => self::str( $address['street'] ?? null ),
			'city'       => self::str( $address['city'] ?? null ),
			'postcode'   => self::str( $address['zipCode'] ?? null ),
			'country'    => self::str( $address['countryCode'] ?? null ),
			'phone'      => self::str( $address['phoneNumber'] ?? null ),
		);
	}

	/**
	 * Punkt odbioru / paczkomat (`delivery.pickupPoint`, kontrakt §12.1) — cały
	 * obiekt ALBO `null` (dostawa pod adres). Zwracamy tablicę do serializacji jako
	 * dyskretne meta zamówienia tylko wtedy, gdy obiekt jest obecny (§8f).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return array<string,mixed>|null
	 */
	public static function pickup_point( array $form ): ?array {
		$delivery = is_array( $form['delivery'] ?? null ) ? $form['delivery'] : array();
		$point    = $delivery['pickupPoint'] ?? null;

		return is_array( $point ) ? $point : null;
	}

	/**
	 * Nazwa metody dostawy (`delivery.method.name`, mapping §8c) — nazwa natywnej
	 * pozycji wysyłki `WC_Order_Item_Shipping`.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function delivery_method_name( array $form ): ?string {
		$name = $form['delivery']['method']['name'] ?? null;

		return is_string( $name ) && '' !== $name ? $name : null;
	}

	/**
	 * Id metody dostawy Allegro (`delivery.method.id`, kontrakt §12.2) — UUID do
	 * meta pozycji wysyłki (dopasowanie/etykieta metody).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function delivery_method_id( array $form ): ?string {
		$id = $form['delivery']['method']['id'] ?? null;

		return is_string( $id ) && '' !== $id ? $id : null;
	}

	/**
	 * Koszt dostawy (`delivery.cost.amount`, mapping §8c) jako float. `0.00` w całej
	 * próbce (Smart/darmowa), ale nie zakładamy tego.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return float|null
	 */
	public static function delivery_cost( array $form ): ?float {
		$delivery = is_array( $form['delivery'] ?? null ) ? $form['delivery'] : array();

		return self::amount( $delivery['cost'] ?? null );
	}

	/**
	 * Tytuł metody płatności (mapping §8c). Payload nie niesie gotowego sluga metody
	 * Woo — ustala import: metoda „allegro" ({@see OrderWriter}), tytuł stały
	 * „Allegro". Szczegóły `payment.type`/`provider` nie są przechowywane (§12.4).
	 *
	 * @return string
	 */
	public static function payment_title(): string {
		return 'Allegro';
	}

	/**
	 * Identyfikator transakcji Allegro (`payment.id`, mapping §8c → `transaction_id`).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function payment_transaction_id( array $form ): ?string {
		$id = $form['payment']['id'] ?? null;

		return is_string( $id ) && '' !== $id ? $id : null;
	}

	/**
	 * Znacznik zapłaty (`payment.finishedAt`, ISO-8601, mapping §8c → `date_paid`).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function payment_date_paid( array $form ): ?string {
		$at = $form['payment']['finishedAt'] ?? null;

		return is_string( $at ) && '' !== $at ? $at : null;
	}

	/**
	 * Suma zamówienia (`summary.totalToPay.amount`, mapping §8c → `_order_total`)
	 * jako float.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return float|null
	 */
	public static function total( array $form ): ?float {
		$summary = is_array( $form['summary'] ?? null ) ? $form['summary'] : array();

		return self::amount( $summary['totalToPay'] ?? null );
	}

	/**
	 * Notatka kupującego (`messageToSeller`, mapping §8c → `customer_note`).
	 * `null` w całej próbce.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function customer_note( array $form ): ?string {
		$note = $form['messageToSeller'] ?? null;

		return is_string( $note ) && '' !== $note ? $note : null;
	}

	/**
	 * Czas utworzenia zamówienia — `lineItems[0].boughtAt` (ISO-8601, mapping §8c:
	 * kandydat na `date_created`). Bierzemy z pierwszej pozycji; brak → null (Woo
	 * ustawi bieżący czas).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return string|null
	 */
	public static function date_created( array $form ): ?string {
		foreach ( self::raw_line_items( $form ) as $item ) {
			$at = $item['boughtAt'] ?? null;

			if ( is_string( $at ) && '' !== $at ) {
				return $at;
			}
		}

		return null;
	}

	/**
	 * Znormalizowane pozycje zamówienia (`lineItems[]`, mapping §8c). Pętla po
	 * WSZYSTKICH pozycjach i dowolne `quantity` (cała próbka ma 1 pozycję ×
	 * `quantity:1`, ale wielopozycyjne/wielosztukowe to luka próbki, nie założenie —
	 * §8f). Ceny (STRINGI) → float; `originalPrice` = subtotal, `price` = total
	 * (w próbce równe, brak rabatów).
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return array<int,array{offer_id:string,name:string,quantity:int,subtotal:float,total:float,line_item_id:string}>
	 */
	public static function line_items( array $form ): array {
		$items = array();

		foreach ( self::raw_line_items( $form ) as $item ) {
			$total    = self::amount( $item['price'] ?? null ) ?? 0.0;
			$subtotal = self::amount( $item['originalPrice'] ?? null ) ?? $total;

			$items[] = array(
				'offer_id'     => self::str( $item['offer']['id'] ?? null ),
				'name'         => self::str( $item['offer']['name'] ?? null ),
				'quantity'     => self::quantity( $item['quantity'] ?? null ),
				'subtotal'     => $subtotal,
				'total'        => $total,
				'line_item_id' => self::str( $item['id'] ?? null ),
			);
		}

		return $items;
	}

	/**
	 * Kwota z węzła `{amount, currency}` (mapping §8b — amount jest STRINGIEM) jako
	 * float. Null, gdy węzła brak albo `amount` nie jest numerycznym stringiem.
	 *
	 * @param mixed $money Węzeł kwoty albo cokolwiek innego.
	 * @return float|null
	 */
	public static function amount( $money ): ?float {
		if ( ! is_array( $money ) ) {
			return null;
		}

		$amount = $money['amount'] ?? null;

		if ( ! is_string( $amount ) || ! is_numeric( $amount ) ) {
			return null;
		}

		return (float) $amount;
	}

	/**
	 * Surowa lista `lineItems[]` (tablica tablic), odporna na brak/zły typ.
	 *
	 * @param array<string,mixed> $form Pełna zwrotka zamówienia.
	 * @return array<int,array<string,mixed>>
	 */
	private static function raw_line_items( array $form ): array {
		$items = $form['lineItems'] ?? null;

		if ( ! is_array( $items ) ) {
			return array();
		}

		$out = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Ilość pozycji jako dodatni int; brak/niepoprawne → 1 (pozycja istnieje, więc
	 * ma co najmniej jedną sztukę — nie gubimy sprzedaży przez zerową ilość).
	 *
	 * @param mixed $value Surowa wartość `quantity`.
	 * @return int
	 */
	private static function quantity( $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}

		if ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) {
			return (int) $value;
		}

		return 1;
	}

	/**
	 * Bezpieczny odczyt wartości jako string (pusty string dla nie-stringów, w tym
	 * `null`). Kwoty NIE przechodzą przez ten helper — te idą przez {@see self::amount()}.
	 *
	 * @param mixed $value Dowolna wartość z payloadu.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
