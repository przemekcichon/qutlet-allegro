<?php
/**
 * Testy jednostkowe OfferSync\OfferMapper (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\OfferSync\OfferMapper;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje transformacje mappingu FAZY 4 na kształtach z REALNYCH zwrotek
 * (próbka `GET_sale-product-offers.json`, P-3.1): rozdział parametrów offer-level
 * („Stan") od produktowych (marka/EAN/MPN/specyfikacja), fallback marki
 * `Marka ?? Producent` (D-4.1.3), auto-mapę stanu (D-4.1.1/D-6.1.4) z `null` dla
 * wartości nieznanej (bez cichego zgadywania) oraz formułę ceny D-4.1.2.
 *
 * Testy BEZ WordPressa — {@see OfferMapper} to czysta transformacja.
 */
final class OfferMapperTest extends TestCase {

	/**
	 * Oferta o kształcie realnej zwrotki (skrócona do pól używanych przez mapper).
	 *
	 * @return array<string,mixed>
	 */
	private function offer(): array {
		return array(
			'id'          => '18780385602',
			'name'        => 'Słuchawki testowe',
			'parameters'  => array(
				array(
					'id'     => '11323',
					'name'   => 'Stan',
					'values' => array( 'Używany' ),
				),
			),
			'productSet'  => array(
				array(
					'product' => array(
						'parameters' => array(
							array(
								'name'   => 'Kod producenta',
								'values' => array( 'A3982G12' ),
							),
							array(
								'name'   => 'Marka',
								'values' => array( ' Soundcore ' ),
							),
							array(
								'name'       => 'Pasmo przenoszenia',
								'values'     => array(),
								'rangeValue' => array(
									'from' => '20',
									'to'   => '20000',
								),
							),
							array(
								'name'   => 'Złącza',
								'values' => array( 'HDMI', 'DisplayPort' ),
							),
							array(
								'name'   => 'EAN (GTIN)',
								'values' => array( '194644131784' ),
							),
							array(
								'name'   => 'Puste',
								'values' => array(),
							),
						),
					),
				),
			),
			'sellingMode' => array(
				'format' => 'BUY_NOW',
				'price'  => array(
					'amount'   => '179.00',
					'currency' => 'PLN',
				),
			),
			'stock'       => array(
				'available' => 1,
				'unit'      => 'UNIT',
			),
			'images'      => array(
				'https://a.allegroimg.com/original/aa/1',
				'https://a.allegroimg.com/original/aa/2',
			),
			'description' => array(
				'sections' => array(
					array(
						'items' => array(
							array(
								'type'    => 'TEXT',
								'content' => '<p>Akapit pierwszy.</p>',
							),
						),
					),
					array(
						'items' => array(
							array(
								'type' => 'IMAGE',
								'url'  => 'https://a.allegroimg.com/original/aa/1',
							),
						),
					),
					array(
						'items' => array(
							array(
								'type'    => 'TEXT',
								'content' => '<p>Akapit drugi.</p>',
							),
						),
					),
				),
			),
			'category'    => array( 'id' => '85166' ),
			'taxSettings' => array(
				'rates' => array(
					array(
						'rate'        => '23.00',
						'countryCode' => 'PL',
					),
				),
			),
		);
	}

	public function test_condition_class_maps_known_value(): void {
		$this->assertSame( 'B', OfferMapper::condition_class( $this->offer() ) );
	}

	public function test_condition_class_unknown_value_returns_null_not_a_guess(): void {
		$offer                              = $this->offer();
		$offer['parameters'][0]['values'][0] = 'Zupełnie nowy stan Allegro';

		$this->assertNull( OfferMapper::condition_class( $offer ) );
		$this->assertSame( 'Zupełnie nowy stan Allegro', OfferMapper::condition_raw( $offer ) );
	}

	public function test_condition_reads_offer_level_not_product_level_parameters(): void {
		$offer = $this->offer();
		// „Stan" wśród parametrów PRODUKTU nie może być źródłem klasy stanu.
		unset( $offer['parameters'] );
		$offer['productSet'][0]['product']['parameters'][] = array(
			'name'   => 'Stan',
			'values' => array( 'Nowy' ),
		);

		$this->assertNull( OfferMapper::condition_class( $offer ) );
	}

	public function test_brand_prefers_marka_and_trims(): void {
		$this->assertSame( 'Soundcore', OfferMapper::brand( $this->offer() ) );
	}

	public function test_brand_falls_back_to_producent(): void {
		$offer = $this->offer();

		foreach ( $offer['productSet'][0]['product']['parameters'] as $i => $param ) {
			if ( 'Marka' === $param['name'] ) {
				unset( $offer['productSet'][0]['product']['parameters'][ $i ] );
			}
		}

		$offer['productSet'][0]['product']['parameters'][] = array(
			'name'   => 'Producent',
			'values' => array( 'Dell' ),
		);

		$this->assertSame( 'Dell', OfferMapper::brand( $offer ) );
	}

	public function test_brand_absent_returns_null(): void {
		$offer = $this->offer();
		$offer['productSet'][0]['product']['parameters'] = array();

		$this->assertNull( OfferMapper::brand( $offer ) );
	}

	public function test_mpn_and_gtin_read_product_parameters(): void {
		$offer = $this->offer();

		$this->assertSame( 'A3982G12', OfferMapper::mpn( $offer ) );
		$this->assertSame( '194644131784', OfferMapper::gtin( $offer ) );
	}

	public function test_price_amount_parses_string_and_handles_null(): void {
		$this->assertSame( 179.0, OfferMapper::price_amount( $this->offer() ) );

		$offer                = $this->offer();
		$offer['sellingMode'] = null;

		$this->assertNull( OfferMapper::price_amount( $offer ) );
	}

	public function test_shop_price_applies_discount_and_rounds_to_grosz(): void {
		$this->assertSame( 179.10, OfferMapper::shop_price( 199.00, 10.0 ) );
		$this->assertSame( 179.0, OfferMapper::shop_price( 179.00, 0.0 ) );
		// 33.33 × 0.885 = 29.49705 → 29.50 (zaokrąglenie do grosza w górę).
		$this->assertSame( 29.50, OfferMapper::shop_price( 33.33, 11.5 ) );
	}

	public function test_offer_url_is_environment_aware(): void {
		$this->assertSame(
			'https://allegro.pl/oferta/18780385602',
			OfferMapper::offer_url( Environment::PRODUCTION, '18780385602' )
		);
		$this->assertSame(
			'https://allegro.pl.allegrosandbox.pl/oferta/18780385602',
			OfferMapper::offer_url( Environment::SANDBOX, '18780385602' )
		);
	}

	public function test_description_raw_joins_text_sections_and_skips_images(): void {
		$this->assertSame(
			"<p>Akapit pierwszy.</p>\n<p>Akapit drugi.</p>",
			OfferMapper::description_raw( $this->offer() )
		);
	}

	public function test_description_raw_empty_when_offer_has_no_description(): void {
		$offer = $this->offer();
		unset( $offer['description'] );

		$this->assertSame( '', OfferMapper::description_raw( $offer ) );
	}

	public function test_specification_flattens_values_and_ranges_skips_empty(): void {
		$this->assertSame(
			array(
				array(
					'etykieta' => 'Kod producenta',
					'wartosc'  => 'A3982G12',
				),
				array(
					'etykieta' => 'Marka',
					'wartosc'  => 'Soundcore',
				),
				array(
					'etykieta' => 'Pasmo przenoszenia',
					'wartosc'  => '20–20000',
				),
				array(
					'etykieta' => 'Złącza',
					'wartosc'  => 'HDMI, DisplayPort',
				),
				array(
					'etykieta' => 'EAN (GTIN)',
					'wartosc'  => '194644131784',
				),
			),
			OfferMapper::specification( $this->offer() )
		);
	}

	public function test_image_urls_filters_non_strings(): void {
		$offer             = $this->offer();
		$offer['images'][] = null;
		$offer['images'][] = '';

		$this->assertSame(
			array(
				'https://a.allegroimg.com/original/aa/1',
				'https://a.allegroimg.com/original/aa/2',
			),
			OfferMapper::image_urls( $offer )
		);
	}

	public function test_vat_rate_normalizes_and_handles_null_tax_settings(): void {
		$this->assertSame( '23', OfferMapper::vat_rate( $this->offer() ) );

		$offer = $this->offer();
		$offer['taxSettings']['rates'][0]['rate'] = '8.50';
		$this->assertSame( '8.5', OfferMapper::vat_rate( $offer ) );

		// Zredagowana próbka P-3.1 miała `taxSettings: null` — realny wariant.
		$offer['taxSettings'] = null;
		$this->assertNull( OfferMapper::vat_rate( $offer ) );
	}

	public function test_stock_quantity_reads_available(): void {
		$this->assertSame( 1, OfferMapper::stock_quantity( $this->offer() ) );

		$offer = $this->offer();
		unset( $offer['stock'] );

		$this->assertNull( OfferMapper::stock_quantity( $offer ) );
	}
}
