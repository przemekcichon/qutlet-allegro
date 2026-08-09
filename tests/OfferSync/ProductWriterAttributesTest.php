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
}
