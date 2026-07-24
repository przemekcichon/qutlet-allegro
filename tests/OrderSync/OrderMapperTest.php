<?php
/**
 * Testy jednostkowe czystych funkcji OrderSync\OrderMapper (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OrderSync;

use Qutlet\Allegro\OrderSync\OrderMapper;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje mapowanie `GET /order/checkout-forms/{id}` → model `WC_Order` na
 * kształcie REALNEJ zredagowanej próbki
 * (`docs/allegro-api-samples/GET_order-checkout-forms-id.json` w qutlet-meta).
 * Testy BEZ WordPressa — `OrderMapper` to czyste funkcje.
 *
 * Fixture świadomie używa RÓŻNYCH kodów pocztowych dla `buyer.address.postCode`
 * i `delivery.address.zipCode`, żeby asercje DOWODZIŁY czytania właściwego klucza
 * (pułapka §8f — te same „00-000" w próbce nie odróżniłyby błędu).
 */
final class OrderMapperTest extends TestCase {

	/**
	 * Zamówienie „kurier pod adres" (wariant [0] próbki): `pickupPoint = null`.
	 *
	 * @return array<string,mixed>
	 */
	private function order_courier(): array {
		return array(
			'id'              => '00000001-0000-11f1-8000-000000000001',
			'messageToSeller' => null,
			'buyer'           => array(
				'email'            => 'kupujacy1@example.com',
				'login'            => 'ukryty-login',
				'firstName'        => 'Jan',
				'lastName'         => 'Kowalski',
				'companyName'      => null,
				'personalIdentity' => null,
				'phoneNumber'      => '+48 500 100 200',
				'address'          => array(
					'street'      => 'Kwiatowa 1',
					'city'        => 'Warszawa',
					'postCode'    => '11-111',
					'countryCode' => 'PL',
				),
			),
			'payment'         => array(
				'id'         => '00000002-0000-11f1-8000-000000000002',
				'type'       => 'ONLINE',
				'provider'   => 'AF',
				'finishedAt' => '2026-05-24T15:47:05.187Z',
				'paidAmount' => array(
					'amount'   => '159.00',
					'currency' => 'PLN',
				),
			),
			'status'          => 'READY_FOR_PROCESSING',
			'delivery'        => array(
				'address'     => array(
					'firstName'   => 'Jan',
					'lastName'    => 'Kowalski',
					'street'      => 'Kwiatowa 1',
					'city'        => 'Warszawa',
					'zipCode'     => '22-222',
					'countryCode' => 'PL',
					'companyName' => null,
					'phoneNumber' => '+48500100200',
				),
				'method'      => array(
					'id'   => '0960fef9-cc88-4558-b2b2-62331a20b5b2',
					'name' => 'Allegro Kurier DHL (AD)',
				),
				'pickupPoint' => null,
				'cost'        => array(
					'amount'   => '0.00',
					'currency' => 'PLN',
				),
			),
			'lineItems'       => array(
				array(
					'id'            => '00000003-0000-11f1-8000-000000000003',
					'offer'         => array(
						'id'   => '18332458328',
						'name' => 'Słuchawki bezprzewodowe ANC Soundcore Life Q30 Upgraded',
					),
					'quantity'      => 1,
					'originalPrice' => array(
						'amount'   => '159.00',
						'currency' => 'PLN',
					),
					'price'         => array(
						'amount'   => '159.00',
						'currency' => 'PLN',
					),
					'tax'           => array(
						'rate' => '23.00',
					),
					'boughtAt'      => '2026-05-24T15:46:54.770Z',
				),
			),
			'summary'         => array(
				'totalToPay' => array(
					'amount'   => '159.00',
					'currency' => 'PLN',
				),
			),
			'revision'        => 'b3a81206',
		);
	}

	/**
	 * Zamówienie „paczkomat" (wariant [1] próbki): `pickupPoint` = pełny obiekt,
	 * `description` obecne. Skrócone do pól używanych przez mapper.
	 *
	 * @return array<string,mixed>
	 */
	private function order_pickup(): array {
		return array(
			'id'              => '00000004-0000-11f1-8000-000000000004',
			'messageToSeller' => 'Proszę o wcześniejszy kontakt.',
			'status'          => 'READY_FOR_PROCESSING',
			'delivery'        => array(
				'address'     => array(
					'street'  => 'Inna 2',
					'zipCode' => '33-333',
				),
				'method'      => array(
					'id'   => '2488f7b7-5d1c-4d65-b85c-4cbcf253fd93',
					'name' => 'Allegro Paczkomaty InPost',
				),
				'pickupPoint' => array(
					'id'          => 'POP-123',
					'name'        => 'Paczkomat WAW01',
					'description' => 'Przy sklepie',
					'address'     => array(
						'street'      => 'Odbiorcza 3',
						'zipCode'     => '44-444',
						'city'        => 'Warszawa',
						'countryCode' => 'PL',
					),
				),
				'cost'        => array(
					'amount'   => '0.00',
					'currency' => 'PLN',
				),
			),
			'lineItems'       => array(),
			'summary'         => array(
				'totalToPay' => array(
					'amount'   => '149.00',
					'currency' => 'PLN',
				),
			),
			'revision'        => '1ab823c2',
		);
	}

	public function test_is_ready_only_for_ready_for_processing_status(): void {
		$this->assertTrue( OrderMapper::is_ready( $this->order_courier() ) );
		$this->assertFalse( OrderMapper::is_ready( array( 'status' => 'BOUGHT' ) ) );
		$this->assertFalse( OrderMapper::is_ready( array( 'status' => 'FILLED_IN' ) ) );
		$this->assertFalse( OrderMapper::is_ready( array() ) );
	}

	public function test_identity_literals(): void {
		$this->assertSame( '00000001-0000-11f1-8000-000000000001', OrderMapper::checkout_form_id( $this->order_courier() ) );
		$this->assertSame( 'b3a81206', OrderMapper::revision( $this->order_courier() ) );
		$this->assertSame( '', OrderMapper::checkout_form_id( array() ) );
		$this->assertSame( '', OrderMapper::revision( array() ) );
	}

	public function test_billing_reads_buyer_and_postcode_not_zipcode(): void {
		$billing = OrderMapper::billing( $this->order_courier() );

		$this->assertSame( 'Jan', $billing['first_name'] );
		$this->assertSame( 'Kowalski', $billing['last_name'] );
		$this->assertSame( 'kupujacy1@example.com', $billing['email'] );
		$this->assertSame( '+48 500 100 200', $billing['phone'] );
		$this->assertSame( '', $billing['company'] ); // companyName null → ''.
		$this->assertSame( 'Kwiatowa 1', $billing['address_1'] );
		$this->assertSame( 'Warszawa', $billing['city'] );
		// Kupujący czytany z `postCode` (11-111), NIE z `zipCode` dostawy (22-222).
		$this->assertSame( '11-111', $billing['postcode'] );
		$this->assertSame( 'PL', $billing['country'] );
	}

	public function test_shipping_reads_delivery_and_zipcode_not_postcode(): void {
		$shipping = OrderMapper::shipping( $this->order_courier() );

		$this->assertSame( 'Jan', $shipping['first_name'] );
		$this->assertSame( 'Kowalski', $shipping['last_name'] );
		$this->assertSame( '', $shipping['company'] );
		$this->assertSame( 'Kwiatowa 1', $shipping['address_1'] );
		$this->assertSame( 'Warszawa', $shipping['city'] );
		// Odbiorca czytany z `zipCode` (22-222), NIE z `postCode` kupującego (11-111).
		$this->assertSame( '22-222', $shipping['postcode'] );
		$this->assertSame( 'PL', $shipping['country'] );
		$this->assertSame( '+48500100200', $shipping['phone'] );
	}

	public function test_pickup_point_null_for_courier_object_for_locker(): void {
		$this->assertNull( OrderMapper::pickup_point( $this->order_courier() ) );

		$point = OrderMapper::pickup_point( $this->order_pickup() );
		$this->assertIsArray( $point );
		$this->assertSame( 'POP-123', $point['id'] );
		$this->assertSame( '44-444', $point['address']['zipCode'] );
	}

	public function test_delivery_method_and_cost(): void {
		$this->assertSame( 'Allegro Kurier DHL (AD)', OrderMapper::delivery_method_name( $this->order_courier() ) );
		$this->assertSame( '0960fef9-cc88-4558-b2b2-62331a20b5b2', OrderMapper::delivery_method_id( $this->order_courier() ) );
		$this->assertSame( 0.0, OrderMapper::delivery_cost( $this->order_courier() ) );
		$this->assertNull( OrderMapper::delivery_cost( array() ) );
	}

	public function test_payment_fields(): void {
		$this->assertSame( 'Allegro', OrderMapper::payment_title() );
		$this->assertSame( '00000002-0000-11f1-8000-000000000002', OrderMapper::payment_transaction_id( $this->order_courier() ) );
		$this->assertSame( '2026-05-24T15:47:05.187Z', OrderMapper::payment_date_paid( $this->order_courier() ) );
		$this->assertNull( OrderMapper::payment_transaction_id( array() ) );
		$this->assertNull( OrderMapper::payment_date_paid( array() ) );
	}

	public function test_total_and_customer_note(): void {
		$this->assertSame( 159.0, OrderMapper::total( $this->order_courier() ) );
		$this->assertNull( OrderMapper::total( array() ) );

		// messageToSeller null (próbka) → null; niepusty string → zachowany.
		$this->assertNull( OrderMapper::customer_note( $this->order_courier() ) );
		$this->assertSame( 'Proszę o wcześniejszy kontakt.', OrderMapper::customer_note( $this->order_pickup() ) );
	}

	public function test_date_created_from_first_line_item_bought_at(): void {
		$this->assertSame( '2026-05-24T15:46:54.770Z', OrderMapper::date_created( $this->order_courier() ) );
		$this->assertNull( OrderMapper::date_created( $this->order_pickup() ) ); // brak lineItems.
	}

	public function test_line_items_amounts_as_floats_and_meta(): void {
		$items = OrderMapper::line_items( $this->order_courier() );

		$this->assertCount( 1, $items );
		$this->assertSame( '18332458328', $items[0]['offer_id'] );
		$this->assertSame( 'Słuchawki bezprzewodowe ANC Soundcore Life Q30 Upgraded', $items[0]['name'] );
		$this->assertSame( 1, $items[0]['quantity'] );
		$this->assertSame( 159.0, $items[0]['subtotal'] );
		$this->assertSame( 159.0, $items[0]['total'] );
		$this->assertSame( '00000003-0000-11f1-8000-000000000003', $items[0]['line_item_id'] );

		$this->assertSame( array(), OrderMapper::line_items( $this->order_pickup() ) );
	}

	public function test_line_items_quantity_fallback_and_missing_prices(): void {
		$form  = array(
			'lineItems' => array(
				array(
					'offer'    => array( 'id' => '999' ),
					// Brak quantity → fallback 1; brak price/originalPrice → 0.0.
				),
			),
		);
		$items = OrderMapper::line_items( $form );

		$this->assertSame( 1, $items[0]['quantity'] );
		$this->assertSame( 0.0, $items[0]['total'] );
		$this->assertSame( 0.0, $items[0]['subtotal'] );
		$this->assertSame( '999', $items[0]['offer_id'] );
		$this->assertSame( '', $items[0]['name'] ); // brak offer.name → ''.
		$this->assertSame( '', $items[0]['line_item_id'] ); // brak id pozycji → ''.
	}

	public function test_amount_parses_string_and_rejects_non_numeric(): void {
		$this->assertSame( 149.0, OrderMapper::amount( array( 'amount' => '149.00', 'currency' => 'PLN' ) ) );
		$this->assertSame( 179.0, OrderMapper::amount( array( 'amount' => '179.0' ) ) );
		$this->assertNull( OrderMapper::amount( array( 'currency' => 'PLN' ) ) );
		$this->assertNull( OrderMapper::amount( array( 'amount' => null ) ) );
		$this->assertNull( OrderMapper::amount( array( 'amount' => 'abc' ) ) );
		$this->assertNull( OrderMapper::amount( 'nie-tablica' ) );
	}
}
