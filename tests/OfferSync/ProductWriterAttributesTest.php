<?php
/**
 * Testy jednostkowe OfferSync\ProductWriter::build_attributes() (P-13.4a, D-13.G1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\ProductWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Product_Attribute;

/**
 * Charakteryzuje port `build_attributes()` (1:1 z usuniętego
 * `Qutlet\Ai\AiRewrite\RewriteWriter::build_attributes()`, P-13.4b): pary
 * etykieta→wartość surowej specyfikacji Allegro ({@see \Qutlet\Allegro\OfferSync\OfferMapper::specification()})
 * → atrybuty WC lokalne (custom, nietaksonomiczne). Metoda jest prywatna —
 * wołana przez Reflection, jak `ProductWriterGtinFilterTest::write_gtin()`.
 */
final class ProductWriterAttributesTest extends TestCase {

	/**
	 * Wywołuje prywatny `ProductWriter::build_attributes()` przez Reflection.
	 *
	 * @param array<int, array{etykieta: string, wartosc: string}> $specification Pary etykieta→wartość.
	 * @return array<int, WC_Product_Attribute>
	 */
	private function build_attributes( array $specification ): array {
		$method = new ReflectionMethod( ProductWriter::class, 'build_attributes' );
		$method->setAccessible( true );

		return $method->invoke( new ProductWriter(), $specification );
	}

	public function test_builds_one_attribute_per_pair_with_expected_shape(): void {
		$attributes = $this->build_attributes(
			array(
				array( 'etykieta' => 'Marka', 'wartosc' => 'Soundcore' ),
				array( 'etykieta' => 'Kolor', 'wartosc' => 'Czarny' ),
			)
		);

		$this->assertCount( 2, $attributes );

		$this->assertSame( 0, $attributes[0]->get_id(), 'id=0 = atrybut lokalny, nie taksonomia globalna.' );
		$this->assertSame( 'Marka', $attributes[0]->get_name() );
		$this->assertSame( array( 'Soundcore' ), $attributes[0]->get_options() );
		$this->assertSame( 0, $attributes[0]->get_position() );
		$this->assertTrue( $attributes[0]->get_visible() );
		$this->assertFalse( $attributes[0]->get_variation() );

		$this->assertSame( 'Kolor', $attributes[1]->get_name() );
		$this->assertSame( array( 'Czarny' ), $attributes[1]->get_options() );
		$this->assertSame( 1, $attributes[1]->get_position(), 'Pozycja rośnie tylko dla wierszy faktycznie dodanych do wyniku.' );
	}

	public function test_skips_rows_with_empty_label_or_value(): void {
		$attributes = $this->build_attributes(
			array(
				array( 'etykieta' => 'OK', 'wartosc' => 'Wartość' ),
				array( 'etykieta' => '', 'wartosc' => 'Brak etykiety' ),
				array( 'etykieta' => 'Brak wartości', 'wartosc' => '' ),
			)
		);

		$this->assertCount( 1, $attributes );
		$this->assertSame( 'OK', $attributes[0]->get_name() );
	}

	public function test_position_skips_gaps_left_by_dropped_rows(): void {
		$attributes = $this->build_attributes(
			array(
				array( 'etykieta' => 'Pierwsza', 'wartosc' => 'A' ),
				array( 'etykieta' => '', 'wartosc' => 'Pomijana' ),
				array( 'etykieta' => 'Trzecia', 'wartosc' => 'C' ),
			)
		);

		$this->assertCount( 2, $attributes );
		$this->assertSame( 0, $attributes[0]->get_position() );
		$this->assertSame( 1, $attributes[1]->get_position(), 'Druga pozycja idzie do "Trzecia" — pominięty wiersz nie zostawia luki w numeracji.' );
	}

	public function test_empty_specification_yields_no_attributes(): void {
		$this->assertSame( array(), $this->build_attributes( array() ) );
	}

	/**
	 * Wywołuje prywatny `ProductWriter::apply_unit_overrides()` przez Reflection.
	 *
	 * @param array<int, array{etykieta: string, wartosc: string}> $specification Pary etykieta→wartość.
	 * @param array<string, string>                                $overrides     `etykieta => "wartość jednostka"`.
	 * @return array<int, array{etykieta: string, wartosc: string}>
	 */
	private function apply_unit_overrides( array $specification, array $overrides ): array {
		$method = new ReflectionMethod( ProductWriter::class, 'apply_unit_overrides' );
		$method->setAccessible( true );

		return $method->invoke( null, $specification, $overrides );
	}

	/**
	 * `apply_unit_overrides()` (D-21.3.1, kontrakt §16) — konsument
	 * `OfferMapper::weight_dimension_attributes()`: nakłada wartości
	 * przeliczone do jednostki sklepu na kopię specyfikacji, dopasowując po
	 * etykiecie; wiersze bez override zostają nietknięte.
	 */
	public function test_apply_unit_overrides_replaces_matching_rows_only(): void {
		$result = $this->apply_unit_overrides(
			array(
				array( 'etykieta' => 'Waga produktu', 'wartosc' => '830' ),
				array( 'etykieta' => 'Kolor', 'wartosc' => 'Czarny' ),
			),
			array( 'Waga produktu' => '0.83 kg' )
		);

		$this->assertSame(
			array(
				array( 'etykieta' => 'Waga produktu', 'wartosc' => '0.83 kg' ),
				array( 'etykieta' => 'Kolor', 'wartosc' => 'Czarny' ),
			),
			$result
		);
	}

	public function test_apply_unit_overrides_does_not_mutate_caller_array(): void {
		$specification = array(
			array( 'etykieta' => 'Waga produktu', 'wartosc' => '830' ),
		);

		$this->apply_unit_overrides( $specification, array( 'Waga produktu' => '0.83 kg' ) );

		$this->assertSame(
			'830',
			$specification[0]['wartosc'],
			'Warstwa surowa (już zapisana do postmeta przed wywołaniem) nie może zobaczyć konwersji.'
		);
	}

	public function test_apply_unit_overrides_empty_overrides_returns_specification_unchanged(): void {
		$specification = array(
			array( 'etykieta' => 'Kolor', 'wartosc' => 'Czarny' ),
		);

		$this->assertSame( $specification, $this->apply_unit_overrides( $specification, array() ) );
	}

	/**
	 * Wywołuje prywatny `ProductWriter::write_native_dimension()` przez Reflection
	 * i zwraca wołanie settera zamiast wywoływać je na realnym `WC_Product`
	 * (statyczna metoda pomocnicza, testowana z podwójnym mockiem — {@see \Mockery}
	 * niedostępny tu, więc anonimowa podklasa `WC_Product` przechwytująca wywołanie).
	 */
	private function write_native_dimension( ?float $value ): ?string {
		$product = new class() extends \WC_Product {
			public ?string $captured = null;

			public function set_weight( $weight = '' ) {
				$this->captured = '' === $weight ? null : (string) $weight;
			}
		};

		$method = new ReflectionMethod( ProductWriter::class, 'write_native_dimension' );
		$method->setAccessible( true );
		$method->invoke( null, $product, 'set_weight', $value );

		return $product->captured;
	}

	/**
	 * D-21.4.1 pkt 3: `null` (kandydat nierozstrzygnięty/zdegradowany) NIE woła
	 * settera w ogóle — pole natywne zostaje nietknięte, nie zerowane.
	 */
	public function test_write_native_dimension_skips_setter_when_value_is_null(): void {
		$this->assertNull( $this->write_native_dimension( null ) );
	}

	public function test_write_native_dimension_formats_with_dot_and_trims_trailing_zeros(): void {
		$this->assertSame( '0.83', $this->write_native_dimension( 0.83 ) );
		$this->assertSame( '3', $this->write_native_dimension( 3.0 ), 'Wartość całkowita — bez zbędnych ".000".' );
	}
}
