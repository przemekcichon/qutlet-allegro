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
		$this->assertSame( 'Używany', OfferMapper::condition_class( $this->offer() ) );
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

	/**
	 * REWIZJA dalsza względem D-12.1a.3/D-12.1c.1 (które mapowały „Nowy" na
	 * osobną klasę `Nowe`, `Powystawowy` na literę `A`): `CONDITION_MAP` jest
	 * dziś mapowaniem TOŻSAMOŚCIOWYM — kod = surowa wartość „Stan" verbatim,
	 * bez osobnych liter/słów-kodów.
	 */
	public function test_condition_class_maps_nowy_verbatim(): void {
		$offer                              = $this->offer();
		$offer['parameters'][0]['values'][0] = 'Nowy';

		$this->assertSame( 'Nowy', OfferMapper::condition_class( $offer ) );
	}

	public function test_condition_class_maps_powystawowy_verbatim(): void {
		$offer                              = $this->offer();
		$offer['parameters'][0]['values'][0] = 'Powystawowy';

		$this->assertSame( 'Powystawowy', OfferMapper::condition_class( $offer ) );
	}

	/**
	 * `condition_map()` (P-12.1c) — akcesor do odczytu dla strony informacyjnej
	 * `ConditionMapPage`; dokładna zawartość musi zostać w synchronizacji z
	 * `docs/mapping-allegro.md` D-4.1.1 — dziś mapowanie tożsamościowe (7
	 * wartości Allegro → te same 7 kodów, verbatim).
	 */
	public function test_condition_map_exposes_current_mapping_verbatim(): void {
		$this->assertSame(
			array(
				'Nowy'            => 'Nowy',
				'Powystawowy'     => 'Powystawowy',
				'Po zwrocie'      => 'Po zwrocie',
				'Używany'         => 'Używany',
				'Nowy z defektem' => 'Nowy z defektem',
				'Uszkodzony'      => 'Uszkodzony',
				'Na części'       => 'Na części',
			),
			OfferMapper::condition_map()
		);
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

	/**
	 * P-6.2b: mapper działa też na kształcie `/parts` (mapping §5 — te same
	 * klucze `stock.available` i `sellingMode.price.amount`, w tym wariant
	 * `price: null` dla oferty bez ceny — z realnej próbki
	 * `GET_sale-product-offers-parts.json`).
	 */
	public function test_parts_shape_yields_stock_and_price(): void {
		$parts = array(
			'id'          => '18780385602',
			'stock'       => array( 'available' => 1 ),
			'sellingMode' => array(
				'price' => array(
					'amount'   => '179.00',
					'currency' => 'PLN',
				),
			),
		);

		$this->assertSame( 1, OfferMapper::stock_quantity( $parts ) );
		$this->assertSame( 179.0, OfferMapper::price_amount( $parts ) );

		$sold_out = array(
			'id'          => '18757279235',
			'stock'       => array( 'available' => 0 ),
			'sellingMode' => array( 'price' => null ),
		);

		$this->assertSame( 0, OfferMapper::stock_quantity( $sold_out ) );
		$this->assertNull( OfferMapper::price_amount( $sold_out ) );
	}

	/**
	 * P-6.2b (D-6.2.2): pochodzenie środowiska z zapisanego `allegro_url` —
	 * odwrotność `offer_url()`, spójna w obie strony dla obu środowisk; URL
	 * spoza obu baz (produkt ręczny / brak importu) daje null, nigdy zgadywanie.
	 */
	public function test_environment_from_offer_url_roundtrip_and_unknown(): void {
		$this->assertSame(
			Environment::PRODUCTION,
			OfferMapper::environment_from_offer_url( OfferMapper::offer_url( Environment::PRODUCTION, '18780385602' ) )
		);
		$this->assertSame(
			Environment::SANDBOX,
			OfferMapper::environment_from_offer_url( OfferMapper::offer_url( Environment::SANDBOX, '18780385602' ) )
		);

		// Baza sandboxowa zaczyna się jak produkcyjna domena + kropka — prefiksy
		// nie mogą się mylić w żadną stronę.
		$this->assertSame(
			Environment::SANDBOX,
			OfferMapper::environment_from_offer_url( 'https://allegro.pl.allegrosandbox.pl/oferta/123' )
		);

		$this->assertNull( OfferMapper::environment_from_offer_url( '' ) );
		$this->assertNull( OfferMapper::environment_from_offer_url( 'https://example.com/oferta/123' ) );
	}

	/**
	 * Oferta bazowa {@see self::offer()} rozszerzona o parametry wagowo-wymiarowe
	 * (D-21.3.1, kontrakt §16) — `id`/`name`/`values` VERBATIM z próbki §15
	 * (kategoria `85166`, audio): `Waga produktu` (id `203709`, jednostka
	 * kategorii `g`) i `Szerokość produktu` (id `223333`, jednostka `cm`) —
	 * plus `Długość przewodu` (id `207838`, kategoria ładowarek w §15, tu
	 * WYŁĄCZNIE jako kontrprzykład „nie jest kandydatem mimo jednostki
	 * długości").
	 *
	 * @return array<string,mixed>
	 */
	private function offer_with_weight_dimension_params(): array {
		$offer = $this->offer();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '203709',
			'name'   => 'Waga produktu',
			'values' => array( '830' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '223333',
			'name'   => 'Szerokość produktu',
			'values' => array( '12.5' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '207838',
			'name'   => 'Długość przewodu',
			'values' => array( '0.5' ),
		);

		return $offer;
	}

	public function test_weight_dimension_param_ids_finds_only_curated_candidates(): void {
		$this->assertSame(
			array(
				'Waga produktu'      => '203709',
				'Szerokość produktu' => '223333',
			),
			OfferMapper::weight_dimension_param_ids( $this->offer_with_weight_dimension_params() )
		);
	}

	public function test_weight_dimension_param_ids_empty_when_offer_has_no_candidates(): void {
		$this->assertSame( array(), OfferMapper::weight_dimension_param_ids( $this->offer() ) );
	}

	public function test_weight_dimension_attributes_converts_when_unit_differs(): void {
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
			),
			'cm',
			'kg'
		);

		$this->assertSame( '0.83 kg', $overrides['Waga produktu'], '830 g → 0.83 kg (sklep w kg, Allegro w g dla tego id).' );
	}

	public function test_weight_dimension_attributes_appends_unit_without_conversion_when_same(): void {
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
			),
			'cm',
			'kg'
		);

		$this->assertSame( '12.5 cm', $overrides['Szerokość produktu'], 'Jednostka Allegro = jednostka sklepu — bez przeliczenia, tylko dopisana etykieta.' );
	}

	public function test_weight_dimension_attributes_skips_candidate_with_unresolved_unit(): void {
		// Słownik kategorii nie zna id `203709` (np. błąd HTTP przy pobraniu) —
		// kandydat pomijany w wyniku, D-21.3.1 pkt 3 (degradacja, nie zgadywanie).
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array( '223333' => 'cm' ),
			'cm',
			'kg'
		);

		$this->assertArrayNotHasKey( 'Waga produktu', $overrides );
		$this->assertSame( '12.5 cm', $overrides['Szerokość produktu'] );
	}

	public function test_weight_dimension_attributes_never_treats_cable_length_as_candidate(): void {
		// Kontrprzykład §15: „Długość przewodu" (m) NIE jest kandydatem mimo
		// jednostki długości w słowniku — nawet gdy jej id JEST rozstrzygnięty.
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
				'207838' => 'm',
			),
			'cm',
			'kg'
		);

		$this->assertArrayNotHasKey( 'Długość przewodu', $overrides );
	}

	public function test_weight_dimension_attributes_empty_when_no_category_units_needed(): void {
		$this->assertSame(
			array(),
			OfferMapper::weight_dimension_attributes( $this->offer(), array(), 'cm', 'kg' )
		);
	}

	/**
	 * Recenzja P-21.3: literał `lbs` (nie `lb`, {@see \Qutlet\Allegro\OfferSync\OfferMapper}
	 * `WEIGHT_TO_G`) był dotąd niepokryty żadnym testem — pokrywa też kierunek
	 * konwersji INNY niż g→kg (funty jako jednostka Allegro, sklep w kg).
	 */
	public function test_weight_dimension_attributes_converts_pounds_to_kilograms(): void {
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'lbs',
				'223333' => 'cm',
			),
			'cm',
			'kg'
		);

		// 830 „lbs” (wartość testowa, nie realna próbka) → 376.48 kg.
		$this->assertSame( '376.481 kg', $overrides['Waga produktu'] );
	}

	/**
	 * D-21.3.1 pkt 3, druga gałąź degradacji (recenzja P-21.3): jednostka
	 * Allegro ZNANA (`id` rozstrzygnięty w słowniku), ale docelowa jednostka
	 * sklepu nierozpoznana przez tabelę konwersji — wynik niesie ORYGINALNĄ
	 * wartość + ORYGINALNĄ jednostkę Allegro (nie pomija wiersza, nie
	 * przelicza na siłę), inaczej niż gdy `id` w ogóle nie ma jednostki w
	 * słowniku (poprzedni test, wciąż POMIJA wiersz).
	 */
	public function test_weight_dimension_attributes_keeps_original_unit_when_target_unrecognized(): void {
		$overrides = OfferMapper::weight_dimension_attributes(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
			),
			'cm',
			'stone' // Jednostka sklepu spoza WEIGHT_TO_G — nierealna dla WooCommerce, ale ćwiczy fallback.
		);

		$this->assertSame( '830 g', $overrides['Waga produktu'], 'Bez przeliczenia (jednostka docelowa nierozpoznana), ale z jednostką Allegro dopisaną — D-21.3.1 pkt 3.' );
	}
}
