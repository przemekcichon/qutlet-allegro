<?php
/**
 * Testy jednostkowe czystej logiki OrderSync\OrderSyncScheduler (P-6.9):
 * parser CSV środowisk i decyzja rozstrzygająca stałą `wp-config.php`.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OrderSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\OrderSync\OrderSyncScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje konfigurowalność środowisk harmonogramu zamówień (D-6.9.2) —
 * wzorzec 1:1 z `Tests\OfferSync\StockSyncSchedulerTest` (P-6.2c). Testy BEZ
 * WordPressa — obie metody są czystymi funkcjami; efekty uboczne (odczyt stałej,
 * `WP_CLI::warning`/`error`) żyją w cienkim `configured_environments()`, poza
 * zasięgiem tego harnessu (bootstrap = sam autoloader Composera).
 *
 * Kanoniczne literały środowisk bierzemy z {@see Environment} (nie z pamięci),
 * żeby test rozjechał się głośno, gdyby zmieniły się u źródła.
 */
final class OrderSyncSchedulerTest extends TestCase {

	// --- parse_environment_list(): czysty parser CSV ---

	public function test_parse_single_valid_environment(): void {
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION ),
				'invalid' => array(),
			),
			OrderSyncScheduler::parse_environment_list( Environment::PRODUCTION )
		);
	}

	public function test_parse_both_environments_reordered_to_canonical(): void {
		// Wejście sandbox-first → wynik produkcja-first (kolejność kanoniczna).
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION, Environment::SANDBOX ),
				'invalid' => array(),
			),
			OrderSyncScheduler::parse_environment_list( 'sandbox,production' )
		);
	}

	public function test_parse_trims_whitespace_and_ignores_trailing_comma(): void {
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION ),
				'invalid' => array(),
			),
			OrderSyncScheduler::parse_environment_list( '  production , ' )
		);
	}

	public function test_parse_normalizes_case(): void {
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION, Environment::SANDBOX ),
				'invalid' => array(),
			),
			OrderSyncScheduler::parse_environment_list( 'PRODUCTION,Sandbox' )
		);
	}

	public function test_parse_deduplicates_valid_and_invalid(): void {
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION ),
				'invalid' => array( 'foo' ),
			),
			OrderSyncScheduler::parse_environment_list( 'production,production,foo,foo' )
		);
	}

	public function test_parse_mixed_valid_and_invalid_tokens(): void {
		// „prdukcja" to literówka — prawidłowe zostaje, literówka trafia do invalid.
		$this->assertSame(
			array(
				'valid'   => array( Environment::PRODUCTION ),
				'invalid' => array( 'prdukcja' ),
			),
			OrderSyncScheduler::parse_environment_list( 'production,prdukcja' )
		);
	}

	public function test_parse_all_invalid(): void {
		$this->assertSame(
			array(
				'valid'   => array(),
				'invalid' => array( 'foo', 'bar' ),
			),
			OrderSyncScheduler::parse_environment_list( 'foo,bar' )
		);
	}

	public function test_parse_empty_and_whitespace_only_yield_nothing(): void {
		$empty = array(
			'valid'   => array(),
			'invalid' => array(),
		);
		$this->assertSame( $empty, OrderSyncScheduler::parse_environment_list( '' ) );
		$this->assertSame( $empty, OrderSyncScheduler::parse_environment_list( '   ' ) );
		$this->assertSame( $empty, OrderSyncScheduler::parse_environment_list( ' , , ' ) );
	}

	// --- plan_environments(): czysta decyzja rozstrzygająca stałą ---

	public function test_plan_undefined_constant_falls_back_to_both(): void {
		$plan = OrderSyncScheduler::plan_environments( false, null );

		$this->assertSame( array( Environment::PRODUCTION, Environment::SANDBOX ), $plan['environments'] );
		$this->assertNull( $plan['warning'] );
		$this->assertNull( $plan['error'] );
	}

	public function test_plan_undefined_ignores_any_raw_value(): void {
		// Gdy niezdefiniowana, wartość $raw jest bez znaczenia (fallback bezwarunkowy).
		$plan = OrderSyncScheduler::plan_environments( false, 'foo' );

		$this->assertSame( array( Environment::PRODUCTION, Environment::SANDBOX ), $plan['environments'] );
		$this->assertNull( $plan['error'] );
	}

	public function test_plan_valid_subset_runs_without_warning(): void {
		$plan = OrderSyncScheduler::plan_environments( true, 'production' );

		$this->assertSame( array( Environment::PRODUCTION ), $plan['environments'] );
		$this->assertNull( $plan['warning'] );
		$this->assertNull( $plan['error'] );
	}

	public function test_plan_mixed_runs_valid_subset_and_warns(): void {
		$plan = OrderSyncScheduler::plan_environments( true, 'production,prdukcja' );

		$this->assertSame( array( Environment::PRODUCTION ), $plan['environments'] );
		$this->assertNull( $plan['error'] );
		$this->assertIsString( $plan['warning'] );
		$this->assertStringContainsString( 'prdukcja', (string) $plan['warning'] );
	}

	public function test_plan_all_invalid_is_hard_error(): void {
		$plan = OrderSyncScheduler::plan_environments( true, 'foo,bar' );

		$this->assertSame( array(), $plan['environments'] );
		$this->assertNull( $plan['warning'] );
		$this->assertIsString( $plan['error'] );
		$this->assertStringContainsString( 'foo,bar', (string) $plan['error'] );
	}

	public function test_plan_empty_string_is_hard_error(): void {
		$plan = OrderSyncScheduler::plan_environments( true, '' );

		$this->assertSame( array(), $plan['environments'] );
		$this->assertIsString( $plan['error'] );
	}

	public function test_plan_non_string_value_is_hard_error(): void {
		$plan = OrderSyncScheduler::plan_environments( true, array( 'production' ) );

		$this->assertSame( array(), $plan['environments'] );
		$this->assertNull( $plan['warning'] );
		$this->assertIsString( $plan['error'] );
		$this->assertStringContainsString( 'array', (string) $plan['error'] );
	}
}
