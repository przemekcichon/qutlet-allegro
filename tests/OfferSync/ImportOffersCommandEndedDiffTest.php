<?php
/**
 * Testy jednostkowe `ImportOffersCommand::diff_ended_offer_ids()` (P-15.4,
 * D-15.7/D-15.13): czysta różnica zbiorów dla flagi `--mark-ended` — kierunek
 * ODWROTNY do `diff_new_offer_ids()` ({@see ImportOffersCommandDiffTest}).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use PHPUnit\Framework\TestCase;
use Qutlet\Allegro\OfferSync\ImportOffersCommand;

final class ImportOffersCommandEndedDiffTest extends TestCase {

	public function test_no_known_offers_yields_nothing(): void {
		$this->assertSame(
			array(),
			ImportOffersCommand::diff_ended_offer_ids( array(), array( '1', '2' ) )
		);
	}

	public function test_all_known_still_active_yields_nothing(): void {
		$this->assertSame(
			array(),
			ImportOffersCommand::diff_ended_offer_ids(
				array(
					'1' => 10,
					'2' => 20,
				),
				array( '1', '2' )
			)
		);
	}

	public function test_known_offer_missing_from_active_is_ended(): void {
		$this->assertSame(
			array( '2' ),
			ImportOffersCommand::diff_ended_offer_ids(
				array(
					'1' => 10,
					'2' => 20,
					'3' => 30,
				),
				array( '1', '3' )
			)
		);
	}

	public function test_no_active_offers_yields_all_known_as_ended(): void {
		$this->assertSame(
			array( '1', '2' ),
			ImportOffersCommand::diff_ended_offer_ids(
				array(
					'1' => 10,
					'2' => 20,
				),
				array()
			)
		);
	}

	/**
	 * Realne offer_id Allegro są numeryczne — PHP normalizuje takie klucze
	 * tablicy na `int`. Bez jawnego rzutu w produkcyjnym kodzie ta kolejność
	 * przeszłaby, ale wynik niósłby `int`, nie `string` (niespójne z resztą
	 * API klasy — `$active_offer_ids`/`diff_new_offer_ids()`).
	 */
	public function test_numeric_offer_ids_are_returned_as_strings(): void {
		$ended = ImportOffersCommand::diff_ended_offer_ids(
			array( '18780385602' => 10 ),
			array()
		);

		$this->assertSame( array( '18780385602' ), $ended );
		$this->assertIsString( $ended[0] );
	}

	public function test_extra_active_offer_ids_not_in_known_are_ignored(): void {
		// Zniknięcia patrzą WYŁĄCZNIE na znane offer_id — nowa oferta ACTIVE
		// bez powiązanego produktu to sprawa --new-only, nie --mark-ended.
		$this->assertSame(
			array(),
			ImportOffersCommand::diff_ended_offer_ids(
				array( '1' => 10 ),
				array( '1', '999' )
			)
		);
	}
}
