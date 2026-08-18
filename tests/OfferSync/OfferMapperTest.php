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

	/**
	 * `weight_dimension_native_values()` (D-21.4.1, kontrakt §17) — floaty PO
	 * konwersji, gotowe do `$product->set_weight()`/`set_length()`/`set_width()`/
	 * `set_height()`; NIE parsuje stringów `weight_dimension_attributes()`.
	 */
	public function test_native_values_converts_and_maps_axes(): void {
		$result = OfferMapper::weight_dimension_native_values(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
			),
			'cm',
			'kg'
		);

		$this->assertSame(
			array(
				'weight' => 0.83,
				'length' => null,
				'width'  => 12.5,
				'height' => null,
			),
			$result['values']
		);
		$this->assertSame( array(), $result['degraded'] );
	}

	public function test_native_values_empty_when_no_candidates(): void {
		$result = OfferMapper::weight_dimension_native_values( $this->offer(), array(), 'cm', 'kg' );

		$this->assertSame(
			array(
				'weight' => null,
				'length' => null,
				'width'  => null,
				'height' => null,
			),
			$result['values']
		);
		$this->assertSame( array(), $result['degraded'] );
	}

	/**
	 * D-21.4.1: kandydat z nierozstrzygniętym `id` w słowniku kategorii jest
	 * pominięty W WYŚCIGU (jakby go nie było) — inaczej niż `degraded`, który
	 * niesie TYLKO kandydatów ze ZNANĄ, ale nierozpoznaną przez tabelę konwersji
	 * jednostką (patrz kolejny test); ten przypadek jest już zgłaszany przez
	 * istniejący mechanizm ostrzeżeń w `ProductWriter::upsert()` dla D-21.3.1 pkt 3.
	 */
	public function test_native_values_skips_candidate_with_unresolved_unit_without_marking_degraded(): void {
		$result = OfferMapper::weight_dimension_native_values(
			$this->offer_with_weight_dimension_params(),
			array( '223333' => 'cm' ),
			'cm',
			'kg'
		);

		$this->assertNull( $result['values']['weight'] );
		$this->assertSame( 12.5, $result['values']['width'] );
		$this->assertSame( array(), $result['degraded'], 'id nierozstrzygnięty w słowniku — pomijany bez oznaczenia jako degraded.' );
	}

	/**
	 * D-21.4.1 pkt 2: jednostka ZNANA, ale nierozpoznana przez tabelę konwersji —
	 * kandydat NIE MOŻE zasilić pola natywnego (wartość byłaby w oryginalnej
	 * jednostce Allegro, nie sklepu) — trafia do `degraded`, wynikowe pole zostaje
	 * `null`.
	 */
	public function test_native_values_marks_degraded_when_target_unit_unrecognized(): void {
		$result = OfferMapper::weight_dimension_native_values(
			$this->offer_with_weight_dimension_params(),
			array(
				'203709' => 'g',
				'223333' => 'cm',
			),
			'cm',
			'stone'
		);

		$this->assertNull( $result['values']['weight'] );
		$this->assertSame( array( 'Waga produktu' ), $result['degraded'] );
	}

	/**
	 * D-21.4.1: priorytet — „Szerokość produktu z podstawą” (priority 1) wygrywa
	 * nad „Szerokość produktu” (priority 3) na TEJ SAMEJ osi `width`, gdy oferta
	 * niesie oba naraz (realna kolizja w próbce §15, kategoria `260041`).
	 */
	public function test_native_values_prefers_base_variant_over_bare_product_on_same_axis(): void {
		$offer = $this->offer_with_weight_dimension_params();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206642',
			'name'   => 'Szerokość produktu z podstawą',
			'values' => array( '20.0' ),
		);

		$result = OfferMapper::weight_dimension_native_values(
			$offer,
			array(
				'203709' => 'g',
				'223333' => 'cm',
				'206642' => 'cm',
			),
			'cm',
			'kg'
		);

		$this->assertSame( 20.0, $result['values']['width'], '"z podstawą" (priority 1) wygrywa nad gołym "produktu" (priority 3).' );
	}

	/**
	 * D-21.4.1: gdy zwycięzca priorytetu („z podstawą”) trafia w degradację
	 * (jednostka nierozpoznana), priorytet SPADA na kolejnego kandydata tej
	 * samej osi zamiast zostawić pole `null` — kandydat zdegradowany jest
	 * pomijany w wyścigu, „jakby go nie było”.
	 */
	public function test_native_values_falls_back_to_next_priority_when_winner_degraded(): void {
		$offer = $this->offer_with_weight_dimension_params();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206642',
			'name'   => 'Szerokość produktu z podstawą',
			'values' => array( '20.0' ),
		);

		$result = OfferMapper::weight_dimension_native_values(
			$offer,
			array(
				'203709' => 'g',
				'223333' => 'cm',
				'206642' => 'stone', // Jednostka nierozpoznana przez LENGTH_TO_CM — degraduje zwycięzcę priorytetu.
			),
			'cm',
			'kg'
		);

		$this->assertSame( 12.5, $result['values']['width'], 'Zwycięzca priorytetu zdegradowany — priorytet spada na "Szerokość produktu".' );
		$this->assertSame( array( 'Szerokość produktu z podstawą' ), $result['degraded'] );
	}

	/**
	 * D-21.4.1 (kontrakt §17, recenzja PR): kolizja priorytetu potwierdzona na
	 * WSZYSTKICH trzech osiach wymiarowych naraz, nie tylko `width` — realne id
	 * z próbki §15, kategoria `260041` (akcesoria monitora), która niesie
	 * jednocześnie warianty „produktu" i „z podstawą" dla każdej z trzech osi.
	 */
	public function test_native_values_prefers_base_variant_on_every_dimension_axis(): void {
		$offer = $this->offer_with_weight_dimension_params();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '223329',
			'name'   => 'Wysokość produktu',
			'values' => array( '52.3' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '201321',
			'name'   => 'Głębokość produktu',
			'values' => array( '20' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206642',
			'name'   => 'Szerokość produktu z podstawą',
			'values' => array( '61.2' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206650',
			'name'   => 'Wysokość produktu z podstawą',
			'values' => array( '48.5' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206654',
			'name'   => 'Głębokość produktu z podstawą',
			'values' => array( '20.02' ),
		);

		$result = OfferMapper::weight_dimension_native_values(
			$offer,
			array(
				'203709' => 'g',
				'223333' => 'cm',
				'223329' => 'cm',
				'201321' => 'cm',
				'206642' => 'cm',
				'206650' => 'cm',
				'206654' => 'cm',
			),
			'cm',
			'kg'
		);

		$this->assertSame( 61.2, $result['values']['width'], '"Szerokość produktu z podstawą" wygrywa nad "Szerokość produktu".' );
		$this->assertSame( 48.5, $result['values']['height'], '"Wysokość produktu z podstawą" wygrywa nad "Wysokość produktu".' );
		$this->assertSame( 20.02, $result['values']['length'], '"Głębokość produktu z podstawą" wygrywa nad "Głębokość produktu" (oś `_length` = głębokość).' );
	}

	/**
	 * D-21.4.1 priorytet wagi — realna kolizja TRÓJSTRONNA w próbce §15,
	 * kategoria `260041`: `Waga z podstawą` (5.59 kg), `Waga produktu` (0.15 kg),
	 * `Waga produktu z opakowaniem jednostkowym` (8.61 kg) na TEJ SAMEJ ofercie.
	 * Wartości rosną w kolejności zgodnej z wybranym priorytetem (recenzja PR
	 * qutlet-meta#101 — dopisane jako ewidencja w kontrakcie §17).
	 */
	public function test_native_values_prefers_packaged_weight_over_base_and_bare_product(): void {
		$offer = $this->offer();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206662',
			'name'   => 'Waga z podstawą',
			'values' => array( '5.59' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '206686',
			'name'   => 'Waga produktu',
			'values' => array( '0.15' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '17448',
			'name'   => 'Waga produktu z opakowaniem jednostkowym',
			'values' => array( '8.61' ),
		);

		$result = OfferMapper::weight_dimension_native_values(
			$offer,
			array(
				'206662' => 'kg',
				'206686' => 'kg',
				'17448'  => 'kg',
			),
			'cm',
			'kg'
		);

		$this->assertSame( 8.61, $result['values']['weight'], '"Waga produktu z opakowaniem jednostkowym" wygrywa nad "Waga z podstawą" i "Waga produktu".' );
	}

	/**
	 * D-21.4.1 priorytet wagi — kolizja w kategorii grilli (`260556`, próbka
	 * §15): `Waga` (bez słowa „produktu", 6.9 kg) współwystępuje z `Waga
	 * produktu z opakowaniem jednostkowym` (10.2 kg) — ta sama zwyciężająca
	 * reguła musi działać także wobec najniżej priorytetowego kandydata `Waga`.
	 */
	public function test_native_values_prefers_packaged_weight_over_grill_waga(): void {
		$offer = $this->offer();

		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '5253',
			'name'   => 'Waga',
			'values' => array( '6.9' ),
		);
		$offer['productSet'][0]['product']['parameters'][] = array(
			'id'     => '17448',
			'name'   => 'Waga produktu z opakowaniem jednostkowym',
			'values' => array( '10.2' ),
		);

		$result = OfferMapper::weight_dimension_native_values(
			$offer,
			array(
				'5253'  => 'kg',
				'17448' => 'kg',
			),
			'cm',
			'kg'
		);

		$this->assertSame( 10.2, $result['values']['weight'] );
	}
}
