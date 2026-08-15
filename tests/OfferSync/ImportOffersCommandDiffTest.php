<?php
/**
 * Testy jednostkowe `ImportOffersCommand::diff_new_offer_ids()` (P-15.2,
 * D-15.1/D-15.2): czysta różnica zbiorów dla flagi `--new-only`, bez WP —
 * wzorzec {@see \Qutlet\Allegro\OfferSync\StockSyncScheduler::plan_environments()}.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use PHPUnit\Framework\TestCase;
use Qutlet\Allegro\OfferSync\ImportOffersCommand;

final class ImportOffersCommandDiffTest extends TestCase {

	public function test_no_active_offers_yields_nothing(): void {
		$this->assertSame(
			array(),
			ImportOffersCommand::diff_new_offer_ids( array(), array( '1' => 10 ) )
		);
	}

	public function test_no_known_offers_yields_all_active(): void {
		$this->assertSame(
			array( '1', '2' ),
			ImportOffersCommand::diff_new_offer_ids( array( '1', '2' ), array() )
		);
	}

	public function test_known_subset_is_excluded(): void {
		$this->assertSame(
			array( '2' ),
			ImportOffersCommand::diff_new_offer_ids(
				array( '1', '2', '3' ),
				array(
					'1' => 10,
					'3' => 30,
				)
			)
		);
	}

	public function test_all_active_already_known_yields_nothing(): void {
		$this->assertSame(
			array(),
			ImportOffersCommand::diff_new_offer_ids(
				array( '1', '2' ),
				array(
					'1' => 10,
					'2' => 20,
				)
			)
		);
	}

	public function test_preserves_order_of_active_offers(): void {
		$this->assertSame(
			array( '3', '1' ),
			ImportOffersCommand::diff_new_offer_ids( array( '3', '2', '1' ), array( '2' => 20 ) )
		);
	}

	public function test_extra_known_offer_ids_not_in_active_are_ignored(): void {
		// known_offer_ids() niesie CAŁY zbiór znanych ofert (w tym te dawno
		// nie-ACTIVE) — różnica patrzy WYŁĄCZNIE na obecność w `$active_offer_ids`.
		$this->assertSame(
			array( '1' ),
			ImportOffersCommand::diff_new_offer_ids(
				array( '1' ),
				array(
					'999' => 1,
					'998' => 2,
				)
			)
		);
	}
}
