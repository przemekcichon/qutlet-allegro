<?php
/**
 * Testy jednostkowe OfferSync\CategoryParameterUnits (P-21.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\CategoryParameterUnits;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje resolucję `id parametru → jednostka` (D-21.3.1, kontrakt §16)
 * na wstrzykniętym fetcherze — BEZ SIECI: ekstrakcję `unit` per `id` z kształtu
 * `GET /sale/categories/{id}/parameters` (zweryfikowanego w
 * `docs/allegro-api-samples/GET_sale-categories-id-parameters.json`), pominięcie
 * parametrów bez jednostki, cache per przebieg (D-6.G2, wzorem
 * {@see \Qutlet\Allegro\Tests\OfferSync\CategoryResolverTest}) i degradację
 * przy błędzie fetchera.
 */
final class CategoryParameterUnitsTest extends TestCase {

	/**
	 * Słownik testowy w kształcie zwrotki `GET /sale/categories/{id}/parameters`,
	 * wzorowany na próbce P-21.2a (kategoria `85166`, audio).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const DICTIONARIES = array(
		'85166' => array(
			'parameters' => array(
				array(
					'id'   => '223333',
					'name' => 'Szerokość produktu',
					'unit' => 'cm',
				),
				array(
					'id'   => '203709',
					'name' => 'Waga produktu',
					'unit' => 'g',
				),
				array(
					'id'   => '11323',
					'name' => 'Stan',
					'unit' => null,
				),
			),
		),
	);

	public function test_builds_id_to_unit_map_and_skips_parameters_without_unit(): void {
		$resolver = new CategoryParameterUnits(
			static function ( string $id ): ?array {
				return self::DICTIONARIES[ $id ] ?? null;
			}
		);

		$this->assertSame(
			array(
				'223333' => 'cm',
				'203709' => 'g',
			),
			$resolver->units_for_category( '85166' )
		);
	}

	public function test_caches_dictionary_within_run(): void {
		$calls    = array();
		$resolver = new CategoryParameterUnits(
			static function ( string $id ) use ( &$calls ): ?array {
				$calls[] = $id;

				return self::DICTIONARIES[ $id ] ?? null;
			}
		);

		$resolver->units_for_category( '85166' );
		$resolver->units_for_category( '85166' );

		$this->assertSame( array( '85166' ), $calls );
	}

	public function test_fetch_failure_returns_empty_map_and_is_cached(): void {
		$calls    = array();
		$resolver = new CategoryParameterUnits(
			static function ( string $id ) use ( &$calls ): ?array {
				$calls[] = $id;

				return null; // Błąd HTTP.
			}
		);

		$this->assertSame( array(), $resolver->units_for_category( '85166' ) );
		$this->assertSame( array(), $resolver->units_for_category( '85166' ) );
		$this->assertSame( array( '85166' ), $calls, 'Błąd zapamiętany — druga rundy nie ponawia żądania.' );
	}

	public function test_unknown_category_returns_empty_map(): void {
		$resolver = new CategoryParameterUnits(
			static function ( string $id ): ?array {
				unset( $id );

				return array( 'parameters' => array() );
			}
		);

		$this->assertSame( array(), $resolver->units_for_category( 'nieznana' ) );
	}
}
