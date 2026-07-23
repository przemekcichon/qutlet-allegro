<?php
/**
 * Testy jednostkowe czystych funkcji OfferSync\SyncStockCommand (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\SyncStockCommand;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje parsery strumienia `GET /order/events` na kształcie REALNEJ
 * zredagowanej próbki (`docs/allegro-api-samples/GET_order-events.json` w
 * qutlet-meta): ekstrakcję id ofert z `events[].order.lineItems[].offer.id`
 * (deduplikacja, odporność na wpisy zdegenerowane) i kursor (id ostatniego
 * zdarzenia strony). Testy BEZ WordPressa — metody są czystymi funkcjami.
 */
final class SyncStockCommandTest extends TestCase {

	/**
	 * Zdarzenia o kształcie realnej próbki (skrócone do pól używanych przez parser).
	 *
	 * @return array<int,mixed>
	 */
	private function events(): array {
		return array(
			array(
				'id'    => '1779564216943152',
				'order' => array(
					'lineItems' => array(
						array(
							'offer'    => array( 'id' => '18561896289' ),
							'quantity' => 1,
						),
					),
				),
				'type'  => 'FILLED_IN',
			),
			array(
				// To samo id oferty w kolejnym zdarzeniu — deduplikacja.
				'id'    => '1779564217213944',
				'order' => array(
					'lineItems' => array(
						array( 'offer' => array( 'id' => '18561896289' ) ),
						array( 'offer' => array( 'id' => '18780385602' ) ),
					),
				),
				'type'  => 'READY_FOR_PROCESSING',
			),
			array(
				// Wpis zdegenerowany (bez lineItems) — pomijany bez błędu.
				'id'    => '1779564217999999',
				'order' => array( 'checkoutForm' => array( 'id' => 'x' ) ),
				'type'  => 'BOUGHT',
			),
		);
	}

	public function test_offer_ids_from_events_deduplicates_and_survives_degenerate_entries(): void {
		$this->assertSame(
			array( '18561896289', '18780385602' ),
			SyncStockCommand::offer_ids_from_events( $this->events() )
		);
	}

	public function test_offer_ids_from_events_empty_input(): void {
		$this->assertSame( array(), SyncStockCommand::offer_ids_from_events( array() ) );
		$this->assertSame( array(), SyncStockCommand::offer_ids_from_events( array( 'nie-tablica', 42 ) ) );
	}

	public function test_last_event_id_takes_last_entry(): void {
		$this->assertSame( '1779564217999999', SyncStockCommand::last_event_id( $this->events() ) );
	}

	public function test_last_event_id_handles_empty_and_malformed(): void {
		$this->assertSame( '', SyncStockCommand::last_event_id( array() ) );
		$this->assertSame( '', SyncStockCommand::last_event_id( array( array( 'order' => array() ) ) ) );
		$this->assertSame( '', SyncStockCommand::last_event_id( array( 'nie-tablica' ) ) );
	}
}
