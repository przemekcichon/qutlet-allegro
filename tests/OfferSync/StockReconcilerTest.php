<?php
/**
 * Testy jednostkowe OfferSync\StockReconciler (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\StockReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje tabelę decyzyjną rekoncyliacji (D-6.G3 + D-6.2.4) — niezmiennik
 * „nigdy nadsprzedaż": obniżenie wprost, podniesienie tylko po świeżym
 * potwierdzeniu, zaległy push blokuje pull. Testy BEZ WordPressa.
 */
final class StockReconcilerTest extends TestCase {

	public function test_pending_push_always_wins(): void {
		// Marker zaległego pusha blokuje pull NIEZALEŻNIE od relacji stanów —
		// pull nadpisałby sprzedaż sklepu stanem sprzed niej (D-6.2.4).
		$this->assertSame( StockReconciler::PUSH_FIRST, StockReconciler::pull_action( 0, 1, true ) );
		$this->assertSame( StockReconciler::PUSH_FIRST, StockReconciler::pull_action( 1, 0, true ) );
		$this->assertSame( StockReconciler::PUSH_FIRST, StockReconciler::pull_action( 1, 1, true ) );
		$this->assertSame( StockReconciler::PUSH_FIRST, StockReconciler::pull_action( 1, null, true ) );
	}

	public function test_missing_woo_stock_initializes_from_allegro(): void {
		$this->assertSame( StockReconciler::APPLY, StockReconciler::pull_action( 1, null, false ) );
		$this->assertSame( StockReconciler::APPLY, StockReconciler::pull_action( 0, null, false ) );
	}

	public function test_equal_stocks_are_noop(): void {
		$this->assertSame( StockReconciler::NOOP, StockReconciler::pull_action( 1, 1, false ) );
		$this->assertSame( StockReconciler::NOOP, StockReconciler::pull_action( 0, 0, false ) );
	}

	public function test_decrease_applies_directly(): void {
		// Kierunek bezpieczny: sprzedaż na Allegro zdejmuje u nas — nigdy nadsprzedaż.
		$this->assertSame( StockReconciler::APPLY, StockReconciler::pull_action( 0, 1, false ) );
		$this->assertSame( StockReconciler::APPLY, StockReconciler::pull_action( 1, 3, false ) );
	}

	public function test_increase_requires_fresh_confirmation(): void {
		// Lista mogła powstać przed chwilowym pushem z Woo — podniesienie stanu
		// bez świeżego /parts przywróciłoby właśnie sprzedany egzemplarz.
		$this->assertSame( StockReconciler::CONFIRM_INCREASE, StockReconciler::pull_action( 1, 0, false ) );
		$this->assertSame( StockReconciler::CONFIRM_INCREASE, StockReconciler::pull_action( 5, 2, false ) );
	}
}
