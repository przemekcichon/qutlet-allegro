<?php
/**
 * Testy jednostkowe czystych funkcji OrderSync\SyncOrdersCommand (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OrderSync;

use Qutlet\Allegro\OrderSync\SyncOrdersCommand;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje parsery strumienia `GET /order/events` używane przez import
 * zamówień, na kształcie REALNEJ zredagowanej próbki
 * (`docs/allegro-api-samples/GET_order-events.json` w qutlet-meta): wybór
 * `checkoutForm.id` WYŁĄCZNIE zdarzeń typu `READY_FOR_PROCESSING` (D-6.3.1),
 * zliczenie zdarzeń nie-READY oraz kursor (id ostatniego zdarzenia strony).
 * Testy BEZ WordPressa — metody są czystymi funkcjami.
 */
final class SyncOrdersCommandTest extends TestCase {

	/**
	 * Zdarzenia o kształcie realnej próbki (skrócone do pól używanych przez parsery).
	 * Dwa różne zamówienia; każde przechodzi cykl FILLED_IN → BOUGHT →
	 * READY_FOR_PROCESSING → FULFILLMENT_STATUS_CHANGED.
	 *
	 * @return array<int,mixed>
	 */
	private function events(): array {
		return array(
			$this->event( '1779564216943152', 'FILLED_IN', '00000004-0000-11f1-8000-000000000004' ),
			$this->event( '1779564217213944', 'BOUGHT', '00000004-0000-11f1-8000-000000000004' ),
			$this->event( '1779564224470065', 'READY_FOR_PROCESSING', '00000004-0000-11f1-8000-000000000004' ),
			$this->event( '1779610068075980', 'FULFILLMENT_STATUS_CHANGED', '00000004-0000-11f1-8000-000000000004' ),
			$this->event( '1779637629298762', 'FILLED_IN', '00000001-0000-11f1-8000-000000000001' ),
			$this->event( '1779637629217665', 'BOUGHT', '00000001-0000-11f1-8000-000000000001' ),
			$this->event( '1779637645744927', 'READY_FOR_PROCESSING', '00000001-0000-11f1-8000-000000000001' ),
			$this->event( '1779692674215255', 'FULFILLMENT_STATUS_CHANGED', '00000001-0000-11f1-8000-000000000001' ),
		);
	}

	/**
	 * Buduje pojedyncze zdarzenie o kształcie próbki.
	 *
	 * @param string $id       `events[].id` (kursor).
	 * @param string $type     `events[].type`.
	 * @param string $form_id  `events[].order.checkoutForm.id`.
	 * @return array<string,mixed>
	 */
	private function event( string $id, string $type, string $form_id ): array {
		return array(
			'id'    => $id,
			'order' => array(
				'checkoutForm' => array( 'id' => $form_id ),
			),
			'type'  => $type,
		);
	}

	public function test_ready_ids_takes_only_ready_for_processing_deduplicated(): void {
		$this->assertSame(
			array(
				'00000004-0000-11f1-8000-000000000004',
				'00000001-0000-11f1-8000-000000000001',
			),
			SyncOrdersCommand::ready_checkout_form_ids_from_events( $this->events() )
		);
	}

	public function test_ready_ids_deduplicates_repeated_ready_events(): void {
		$events = array(
			$this->event( '1', 'READY_FOR_PROCESSING', 'AAA' ),
			$this->event( '2', 'READY_FOR_PROCESSING', 'AAA' ), // to samo zamówienie ponownie.
			$this->event( '3', 'READY_FOR_PROCESSING', 'BBB' ),
		);

		$this->assertSame( array( 'AAA', 'BBB' ), SyncOrdersCommand::ready_checkout_form_ids_from_events( $events ) );
	}

	public function test_ready_ids_empty_and_degenerate_input(): void {
		$this->assertSame( array(), SyncOrdersCommand::ready_checkout_form_ids_from_events( array() ) );
		$this->assertSame( array(), SyncOrdersCommand::ready_checkout_form_ids_from_events( array( 'nie-tablica', 42 ) ) );
		// READY bez checkoutForm.id — pomijane.
		$this->assertSame(
			array(),
			SyncOrdersCommand::ready_checkout_form_ids_from_events(
				array( array( 'type' => 'READY_FOR_PROCESSING', 'order' => array() ) )
			)
		);
	}

	public function test_non_ready_event_count(): void {
		// 8 zdarzeń, 2 typu READY_FOR_PROCESSING → 6 nie-READY.
		$this->assertSame( 6, SyncOrdersCommand::non_ready_event_count( $this->events() ) );
		$this->assertSame( 0, SyncOrdersCommand::non_ready_event_count( array() ) );
		$this->assertSame(
			0,
			SyncOrdersCommand::non_ready_event_count( array( $this->event( '1', 'READY_FOR_PROCESSING', 'AAA' ) ) )
		);
	}

	public function test_last_event_id_takes_last_entry(): void {
		$this->assertSame( '1779692674215255', SyncOrdersCommand::last_event_id( $this->events() ) );
	}

	public function test_last_event_id_handles_empty_and_malformed(): void {
		$this->assertSame( '', SyncOrdersCommand::last_event_id( array() ) );
		$this->assertSame( '', SyncOrdersCommand::last_event_id( array( array( 'order' => array() ) ) ) );
		$this->assertSame( '', SyncOrdersCommand::last_event_id( array( 'nie-tablica' ) ) );
	}
}
