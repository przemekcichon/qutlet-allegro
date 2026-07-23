<?php
/**
 * Testy jednostkowe OfferSync\CategoryResolver (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\CategoryResolver;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje traversal drzewa (mapping §7b) na wstrzykniętym fetcherze —
 * BEZ SIECI: budowę ścieżki liść→korzeń, cache per przebieg (to samo id NIE
 * jest pobierane dwa razy — D-6.G2), częściowe ścieżki przy błędzie API oraz
 * bezpiecznik cyklu.
 */
final class CategoryResolverTest extends TestCase {

	/**
	 * Drzewo testowe w kształcie zwrotki `GET /sale/categories/{id}` (VERBATIM
	 * klucze `id`/`name`/`parent`), wzorowane na próbce P-3.2.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const TREE = array(
		'85166' => array(
			'id'     => '85166',
			'name'   => 'Bezprzewodowe',
			'parent' => array( 'id' => '66887' ),
		),
		'66887' => array(
			'id'     => '66887',
			'name'   => 'Słuchawki',
			'parent' => array( 'id' => '42540aec-367a-4e5e-b411-17c09b08e41f' ),
		),
		'42540aec-367a-4e5e-b411-17c09b08e41f' => array(
			'id'     => '42540aec-367a-4e5e-b411-17c09b08e41f',
			'name'   => 'Elektronika',
			'parent' => null,
		),
	);

	public function test_builds_leaf_to_root_path(): void {
		$resolver = new CategoryResolver(
			static function ( string $id ): ?array {
				return self::TREE[ $id ] ?? null;
			}
		);

		$this->assertSame(
			array(
				array(
					'id'   => '85166',
					'name' => 'Bezprzewodowe',
				),
				array(
					'id'   => '66887',
					'name' => 'Słuchawki',
				),
				array(
					'id'   => '42540aec-367a-4e5e-b411-17c09b08e41f',
					'name' => 'Elektronika',
				),
			),
			$resolver->path( '85166' )
		);
	}

	public function test_caches_nodes_within_run(): void {
		$calls    = array();
		$resolver = new CategoryResolver(
			static function ( string $id ) use ( &$calls ): ?array {
				$calls[] = $id;

				return self::TREE[ $id ] ?? null;
			}
		);

		// Dwa liście dzielące przodków: wspólne węzły pobrane tylko raz.
		$resolver->path( '85166' );
		$resolver->path( '85166' );
		$resolver->path( '66887' );

		$this->assertSame( array( '85166', '66887', '42540aec-367a-4e5e-b411-17c09b08e41f' ), $calls );
	}

	public function test_fetch_failure_midway_returns_partial_path(): void {
		$resolver = new CategoryResolver(
			static function ( string $id ): ?array {
				if ( '66887' === $id ) {
					return null; // Błąd HTTP w połowie drzewa.
				}

				return self::TREE[ $id ] ?? null;
			}
		);

		$this->assertSame(
			array(
				array(
					'id'   => '85166',
					'name' => 'Bezprzewodowe',
				),
			),
			$resolver->path( '85166' )
		);
	}

	public function test_unresolvable_leaf_returns_empty_path(): void {
		$resolver = new CategoryResolver(
			static function ( string $id ): ?array {
				unset( $id );

				return null;
			}
		);

		$this->assertSame( array(), $resolver->path( '85166' ) );
	}

	public function test_cycle_is_stopped_by_depth_guard(): void {
		$resolver = new CategoryResolver(
			static function ( string $id ): ?array {
				// Węzeł wskazujący na samego siebie — uszkodzone dane nie mogą zapętlić importu.
				return array(
					'id'     => $id,
					'name'   => 'Cykl',
					'parent' => array( 'id' => $id ),
				);
			}
		);

		$path = $resolver->path( 'x' );

		$this->assertNotEmpty( $path );
		$this->assertLessThanOrEqual( 20, count( $path ) );
	}
}
