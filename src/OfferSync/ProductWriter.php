<?php
/**
 * Slice OfferSync — zapis oferty Allegro do produktu WooCommerce (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Core\AllegroLink\AllegroLinkMeta;
use Qutlet\Core\Pricing\DiscountRate;
use Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy;
use Qutlet\Core\ProductInfo\RawLayerMeta;
use WC_Data_Exception;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Tax;

/**
 * Tworzy/aktualizuje produkt Woo z pełnej zwrotki oferty wg mappingu FAZY 4.
 *
 * Idempotencja: kluczem powiązania jest meta `_qutlet_allegro_offer_id`
 * (kontrakt §10.1) — ponowny import znajduje istniejący produkt i AKTUALIZUJE go,
 * nigdy nie duplikuje.
 *
 * Podział własności pól (kto jest źródłem prawdy — kontrakt §2/§4/§9/§10/§11).
 * REWIZJA P-9.1 (zgłoszenie 2026-07-27): pierwsza wersja tego podziału nadpisywała
 * tytuł/`allegro_wlaczone`/galerię bezwarunkowo przy KAŻDYM przebiegu, także
 * update — gubiło to ręczne edycje admina; D-9.1a.1/D-9.1b.1/D-9.1d.1 zawężyły
 * trzy z tych pól, jak niżej. D-9.1c.1: kategoria/marka NIE są chronione — uznane
 * za teoretyczne ryzyko (P-6.8b: kuracja kategorii to zabieg jednorazowy), zostają
 * w pełni sync-owned.
 * - nadpisywane każdym przebiegiem (sync-owned): stock, `_price` (formuła
 *   D-4.1.2), `cena_allegro`, `allegro_url`, GTIN, VAT, warstwa surowa (verbatim
 *   JSON + pola parsowane — W TEJ SAMEJ operacji, z tej samej zwrotki, D-6.G4),
 *   pola `AllegroLink`, kategoria, marka (D-9.1c.1 — nie chronione),
 *   **atrybuty WC — specyfikacja tłumaczona 1:1 z surowych parametrów Allegro**
 *   (P-13.4a, D-13.G1 — REWIZJA wcześniejszej D-6.G4/D-5.1.1/D-5.1.2, które
 *   kierowały specyfikację przez AI i trzymały ten sync z dala od atrybutów WC;
 *   AI od P-13.4b przestało pisać atrybuty w ogóle, więc żadna ręczna edycja
 *   atrybutu nie przetrwa mimo woli — dane wprost z Allegro, bez ingerencji
 *   kuratora w grze, D-13.4a.1);
 * - ustawiane TYLKO przy tworzeniu (`$action === 'created'`), NIGDY przy update:
 *   tytuł (`post_title`, D-9.1a.1/P-9.1a.1 — po utworzeniu tytuł jest redakcyjny,
 *   ręczna edycja albo split tytuł/podnazwa przez `qutlet-ai` TitleGenerator/
 *   Writer, P-9.1a.2; warstwa surowa `META_NAME_RAW` niżej i tak zawsze niesie
 *   aktualną nazwę z Allegro), `allegro_wlaczone` (D-9.1b.1/P-9.1b — pełny
 *   re-import nie włącza z powrotem kanału ręcznie wyłączonego przez kuratora);
 * - ustawiane TYLKO gdy puste: `klasa_stanu` (D-6.1.4 — auto-mapa daje wartość
 *   domyślną, ręczna ocena egzemplarza nie jest nadpisywana; „puste" od
 *   P-12.2b/D-12.2.4 = brak relacji z taksonomią {@see ClassDefinitionsTaxonomy},
 *   nie pusty postmeta — patrz {@see self::upsert()});
 * - scalane, nie podmieniane: zdjęcia/galeria (D-9.1d.1/P-9.1d — zdjęcia z oferty
 *   sync-owned jak dotąd, ale ręcznie dołożone przez kuratora (bez
 *   {@see ImageSideloader::META_SOURCE_URL}) zostają dopisane na końcu, nigdy nie
 *   znikają; patrz {@see self::manual_image_ids()});
 * - NIGDY nie dotykane: warstwa przerobiona (`opis` — D-6.G4), `cena_rynkowa_nowego`,
 *   `zawartosc_zestawu`, `_qutlet_stawka_rabatu`;
 * - ustawiane TYLKO gdy rozstrzygnięte, nigdy zerowane: pola natywne wysyłki Woo
 *   `_weight`/`_length`/`_width`/`_height` (D-21.4.1, kontrakt §17 —
 *   {@see self::write_native_dimension()}) — brak kandydata (lub degradacja
 *   wszystkich kandydatów danej osi/wagi w danej ofercie) zostawia pole
 *   nietknięte, żeby nie skasować ręcznej korekty administratora.
 *
 * Literały meta bierzemy ze STAŁYCH klas core (twarda zależność) — jedno źródło
 * prawdy zamiast powtarzania stringów z kontraktu. Klucze pól ACF to literały
 * `field_qutlet_*` przepisane VERBATIM z rejestracji w core (P-1.2/P-1.3);
 * `update_field()` po kluczu zapisuje wartość ORAZ referencję pola.
 *
 * REWIZJA P-20.7a (FAZA 20, D-20.9): `cena_allegro`/`allegro_url` PRZESTAŁY iść
 * przez `update_field()` po kluczu ACF — teraz zwykły `update_post_meta()`/
 * `$product->update_meta_data()` po NAZWIE (ten sam wzorzec co core
 * `MarketPriceField::save()`/`ProductDiscountRateField::save()`). Powód:
 * `update_field()` wołane po kluczu (`field_qutlet_*`) wobec pola, które
 * przestałoby być zarejestrowane w ACF (core, P-20.7b), cicho zapisałoby
 * wartość pod BŁĘDNYM meta_key (dummy field, `acf_get_valid_field()`) —
 * niedopuszczalne dla pola odpowiedzialnego za cenę. `allegro_wlaczone`
 * (`ACF_KEY_ALLEGRO_ENABLED`) zostaje polem ACF bez zmian — poza zakresem tej
 * rewizji.
 */
final class ProductWriter {

	/**
	 * Klucz ACF pola `allegro_wlaczone` (VERBATIM z `AllegroChannelFields` w core).
	 *
	 * Publiczna — tego samego literału używa {@see OfferEndedMarker} (P-15.4,
	 * D-15.9) do zapisu `allegro_wlaczone = 0`/`1` w NOWYM miejscu kodu (poza
	 * `upsert()`, żeby nie kolidować z regułą D-9.1b.1 niżej) — jedno źródło
	 * literału zamiast duplikowania go w drugiej klasie.
	 */
	public const ACF_KEY_ALLEGRO_ENABLED = 'field_qutlet_allegro_wlaczone';

	/**
	 * Klucz ACF pola `klasa_stanu` (VERBATIM z `ProductConditionFields` w core).
	 */
	private const ACF_KEY_CONDITION = 'field_qutlet_klasa_stanu';

	/**
	 * `meta_key` (name) pola `cena_allegro` (P-20.7a, D-20.9: zwykła nazwa, NIE
	 * klucz ACF) — używana do odczytu bieżącej wartości przy lekkim syncu
	 * (P-6.2b) i do zapisu ({@see self::upsert()}/{@see self::apply_stock_and_price()}).
	 */
	private const ALLEGRO_PRICE_META = 'cena_allegro';

	/**
	 * `meta_key` (name) pola `allegro_url` (P-20.7a, D-20.9: zwykła nazwa, NIE
	 * klucz ACF).
	 */
	private const ALLEGRO_URL_META = 'allegro_url';

	/**
	 * Statusy posta przy wyszukiwaniu po kluczu powiązania. JAWNA lista zamiast
	 * `'any'`, bo `'any'` NIE obejmuje kosza (`trash` ma `exclude_from_search`),
	 * a produkt w koszu to świadome wycofanie (D-6.2.1) — MUSI zostać znaleziony,
	 * żeby import/sync mógł go POMINĄĆ, zamiast utworzyć duplikat od nowa.
	 *
	 * Publiczna — tej samej listy używa `SyncStockCommand` przy wyszukiwaniu
	 * produktów z markerem zaległego pusha (jedno źródło semantyki „z koszem").
	 *
	 * @var array<int,string>
	 */
	public const LINK_LOOKUP_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/**
	 * Stawka VAT mapowana na STANDARDOWĄ klasę podatkową Woo (pusty slug).
	 */
	private const STANDARD_VAT_RATE = '23';

	/**
	 * Side-loader zdjęć (idempotentny po URL-u źródłowym).
	 *
	 * @var ImageSideloader
	 */
	private $images;

	public function __construct() {
		$this->images = new ImageSideloader();
	}

	/**
	 * Tworzy/aktualizuje produkt z pełnej zwrotki oferty.
	 *
	 * @param array<string,mixed>                      $offer         Zdekodowana zwrotka `GET /sale/product-offers/{id}`.
	 * @param string                                   $verbatim_json Surowe body TEJ SAMEJ zwrotki (D-6.G4 — verbatim, bajt-w-bajt).
	 * @param string                                   $environment   Środowisko importu (`Environment::*` — buduje `allegro_url`).
	 * @param string                                   $category_slug Docelowy slug `product_cat` (reguła albo kosz D-6.1.2).
	 * @param array<int,array{id:string,name:string}>  $category_path Rozwiązana ścieżka liść→korzeń (może być pusta).
	 * @param string                                   $status        Status posta dla NOWEGO produktu (`pending`/`publish`/`draft`).
	 * @param bool                                     $skip_images   Pominięcie side-loadu zdjęć (przebiegi próbne).
	 * @param array<string,string>                     $category_units `id parametru => jednostka` ze słownika parametrów kategorii tej oferty (D-21.3.1, kontrakt §16; `[]` gdy oferta nie ma kandydatów wagowo-wymiarowych — {@see OfferMapper::weight_dimension_param_ids()}).
	 * @param string                                   $dimension_unit Ustawienie sklepu `woocommerce_dimension_unit` (D-21.3.1).
	 * @param string                                   $weight_unit    Ustawienie sklepu `woocommerce_weight_unit` (D-21.3.1).
	 * @return array{action:string,product_id:int,warnings:array<int,string>}
	 */
	public function upsert(
		array $offer,
		string $verbatim_json,
		string $environment,
		string $category_slug,
		array $category_path,
		string $status,
		bool $skip_images,
		array $category_units = array(),
		string $dimension_unit = 'cm',
		string $weight_unit = 'kg'
	): array {
		$offer_id = (string) ( $offer['id'] ?? '' );
		$warnings = array();

		$existing_id = $this->find_product_id( $offer_id, $warnings );

		// Produkt w koszu = świadome wycofanie przez kuratora (D-6.2.1): zero
		// zapisów, żadnego tworzenia od nowa — komenda pomija ofertę i loguje.
		if ( null !== $existing_id && 'trash' === get_post_status( $existing_id ) ) {
			return array(
				'action'     => 'skipped-trashed',
				'product_id' => $existing_id,
				'warnings'   => $warnings,
			);
		}

		$product = null !== $existing_id ? wc_get_product( $existing_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			$product = new WC_Product_Simple();
			$product->set_status( $status );
		}

		$action = null === $existing_id ? 'created' : 'updated';

		// Pola natywne (mapping §1). `post_title` (D-9.1a.1, P-9.1a.1) TYLKO przy
		// tworzeniu nowego produktu — po utworzeniu tytuł jest redakcyjny (ręczna
		// edycja albo split tytuł/podnazwa przez `qutlet-ai` TitleGenerator/Writer,
		// P-9.1a.2); pełny re-import NIE nadpisuje. Warstwa surowa (`META_NAME_RAW`
		// niżej) i tak zawsze niesie aktualną nazwę z Allegro niezależnie od tego.
		$name = $offer['name'] ?? null;

		if ( 'created' === $action && is_string( $name ) && '' !== $name ) {
			$product->set_name( $name );
		}

		$stock = OfferMapper::stock_quantity( $offer );

		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
		}

		// Ceny (mapping §2 + D-4.1.2): cena oferty → `cena_allegro`; `_price` liczona
		// z efektywnej stawki (nadpisanie per produkt ?? globalna — core P-6.1a).
		$cena_allegro = OfferMapper::price_amount( $offer );

		if ( null !== $cena_allegro ) {
			$rate = DiscountRate::effective_percent( $existing_id ?? 0 );
			$shop = OfferMapper::shop_price( $cena_allegro, $rate );

			// number_format, nie cast: (string)float na PHP < 8.0 respektuje
			// LC_NUMERIC (możliwe "161,1"), a Woo oczekuje kropki (recenzja P-6.1b).
			$shop_str = number_format( $shop, 2, '.', '' );

			$product->set_regular_price( $shop_str );
			$product->set_price( $shop_str );
		} else {
			$warnings[] = 'Oferta bez ceny (sellingMode.price) — pominięto cenę.';
		}

		// GTIN → natywne Woo `global_unique_id` (kontrakt §10.2, D-6.7.1).
		$gtin = OfferMapper::gtin( $offer );

		$this->write_gtin( $product, $gtin, $warnings );

		// VAT (D-6.1.3): stawka z `taxSettings` → klasa podatkowa produktu.
		$vat_rate = OfferMapper::vat_rate( $offer );

		if ( null !== $vat_rate ) {
			$product->set_tax_status( 'taxable' );
			$product->set_tax_class( $this->tax_class_for_rate( $vat_rate, $warnings ) );
		}

		$product->save();
		$product_id = $product->get_id();

		if ( 0 === $product_id ) {
			$warnings[] = 'Zapis produktu nie zwrócił id — pomijam resztę zapisu.';

			return array(
				'action'     => 'failed',
				'product_id' => 0,
				'warnings'   => $warnings,
			);
		}

		/*
		 * Warstwa surowa (D-6.G4): verbatim JSON i wszystkie pola parsowane zapisujemy
		 * w tej samej operacji, z TEJ SAMEJ zwrotki — pole parsowane nigdy nie może
		 * przeżyć JSON-a, z którego powstało. `wp_slash()` bo `update_post_meta()`
		 * unslashuje wejście, a verbatim ma zostać bajt-w-bajt (JSON niesie backslashe).
		 */
		update_post_meta( $product_id, RawLayerMeta::META_OFFER, wp_slash( $verbatim_json ) );
		update_post_meta( $product_id, RawLayerMeta::META_DESCRIPTION_RAW, wp_slash( OfferMapper::description_raw( $offer ) ) );

		$specification = OfferMapper::specification( $offer );

		update_post_meta( $product_id, RawLayerMeta::META_SPECIFICATION_RAW, wp_slash( $specification ) );

		// Jednostki wagowo-wymiarowe (D-21.3.1, kontrakt §16) — dotyczy WYŁĄCZNIE
		// warstwy przerobionej (atrybuty WC niżej); `$specification` zapisana wyżej
		// do warstwy surowej zostaje verbatim, konwersji nie widzi (D-6.G4).
		$unit_overrides = OfferMapper::weight_dimension_attributes( $offer, $category_units, $dimension_unit, $weight_unit );

		// Warning TYLKO dla przypadku, w którym `weight_dimension_attributes()`
		// nie mogła wyprodukować ŻADNEGO wiersza (id nieobecne w słowniku
		// kategorii albo wartość nienumeryczna) — jednostka ZNANA, ale
		// nierozpoznana przez tabelę konwersji dostaje własny wiersz z
		// oryginalną jednostką Allegro (patrz docblock `weight_dimension_attributes()`),
		// więc NIE trafia tu jako pominięcie.
		foreach ( OfferMapper::weight_dimension_param_ids( $offer ) as $param_name => $param_id ) {
			if ( ! isset( $unit_overrides[ $param_name ] ) ) {
				$warnings[] = sprintf(
					'Parametr wagowo-wymiarowy „%s" (id=%s) nie ma rozstrzygniętej jednostki w słowniku kategorii (błąd HTTP/id nieobecny) albo wartość jest nienumeryczna — atrybut zapisany bez konwersji (D-21.3.1).',
					$param_name,
					$param_id
				);
			}
		}

		// Pola natywne wysyłki Woo (D-21.4.1, kontrakt §17) — te SAME dane
		// źródłowe (parametry produktowe + słownik jednostek kategorii), osobny
		// mechanizm resolucji: priorytet między kandydatami tej samej osi/wagi +
		// wymóg udanej konwersji (`weight_dimension_attributes()` wyżej może
		// zostawić wartość w oryginalnej jednostce Allegro przy degradacji —
		// niebezpieczne dla pola bez etykiety jednostki, patrz docblock
		// `OfferMapper::weight_dimension_native_values()`).
		$native_dimensions = OfferMapper::weight_dimension_native_values( $offer, $category_units, $dimension_unit, $weight_unit );

		foreach ( $native_dimensions['degraded'] as $param_name ) {
			$warnings[] = sprintf(
				'Parametr wagowo-wymiarowy „%s" ma jednostkę nierozpoznaną przez tabelę konwersji — pominięto przy zapisie do natywnego pola wysyłki (D-21.4.1).',
				$param_name
			);
		}

		// Atrybuty WC (kontrakt §9.2, P-13.4a/D-13.G1) — tłumaczenie 1:1 z tej
		// samej specyfikacji surowej, BEZ udziału AI; nadpisywane każdym przebiegiem
		// (sync-owned, D-13.4a.1 — patrz docblock klasy). Wiersze wagowo-wymiarowe
		// dostają dopisaną jednostkę sklepu + konwersję liczby ($unit_overrides
		// wyżej, D-21.3.1) — TYLKO w tej kopii przekazanej do `build_attributes()`,
		// `$specification` (już zapisana do warstwy surowej) zostaje nietknięta.
		// Osobny `save()`, bo `set_attributes()` nie jest częścią żadnego z pól
		// ustawionych na `$product` przed pierwszym `save()` powyżej. UWAGA:
		// `set_attributes()` PODMIENIA cały zestaw atrybutów produktu, nie tylko
		// wiersze ze specyfikacji — dziś nieszkodliwe (P-13.4b: AI już nie pisze
		// atrybutów, nic innego też), ale gdyby w przyszłości jakiś inny mechanizm
		// zaczął dopisywać własne atrybuty WC do tych samych produktów (np.
		// globalne atrybuty taksonomiczne do filtrowania), ten sync je
		// bezwarunkowo skasuje przy najbliższym przebiegu.
		$product->set_attributes( self::build_attributes( self::apply_unit_overrides( $specification, $unit_overrides ) ) );

		// Zapis TYLKO gdy wartość rozstrzygnięta (D-21.4.1 pkt 3) — brak
		// kandydata (lub degradacja wszystkich kandydatów danej osi/wagi w tej
		// ofercie) zostawia pole NIETKNIĘTE, żeby nie skasować ewentualnej
		// ręcznej korekty administratora. W odróżnieniu od atrybutów WC wyżej
		// (cały zestaw sync-owned, bezwarunkowo nadpisywany) — pole natywne to
		// pojedynczy skalar dzielony z ręczną edycją w adminie.
		self::write_native_dimension( $product, 'set_weight', $native_dimensions['values']['weight'] );
		self::write_native_dimension( $product, 'set_length', $native_dimensions['values']['length'] );
		self::write_native_dimension( $product, 'set_width', $native_dimensions['values']['width'] );
		self::write_native_dimension( $product, 'set_height', $native_dimensions['values']['height'] );

		$product->save();

		// Nazwa oryginalna Allegro (P-13.2b, kontrakt §9.1) — RÓWNOLEGLE z `set_name()`
		// (post_title) powyżej, ten sam warunek/źródło (`$name`), zapamiętana osobno.
		if ( is_string( $name ) && '' !== $name ) {
			update_post_meta( $product_id, RawLayerMeta::META_NAME_RAW, wp_slash( $name ) );
		}

		// Pola AllegroLink (kontrakt §10.1) — nadpisywane każdym przebiegiem.
		update_post_meta( $product_id, AllegroLinkMeta::META_OFFER_ID, wp_slash( $offer_id ) );
		update_post_meta( $product_id, AllegroLinkMeta::META_CATEGORY_ID, wp_slash( (string) ( $offer['category']['id'] ?? '' ) ) );
		update_post_meta( $product_id, AllegroLinkMeta::META_CATEGORY_PATH, wp_slash( $category_path ) );

		$mpn = OfferMapper::mpn( $offer );

		if ( null !== $mpn ) {
			update_post_meta( $product_id, AllegroLinkMeta::META_MPN, wp_slash( $mpn ) );
		} else {
			delete_post_meta( $product_id, AllegroLinkMeta::META_MPN );
		}

		// Kanał Allegro (kontrakt §4) — `allegro_wlaczone` (D-9.1b.1) TYLKO przy
		// tworzeniu nowego produktu (pełny re-import NIE nadpisuje ręcznego
		// wyłączenia kanału przez kuratora, P-9.1b), jedyne z tej trójki nadal
		// przez ACF (`update_field()` po kluczu — pole zostaje zarejestrowane).
		// `allegro_url`/`cena_allegro` sync-owned ZAWSZE, ale od P-20.7a (D-20.9)
		// zwykły zapis po NAZWIE meta_key, NIE `update_field()` po kluczu ACF —
		// patrz docblock klasy.
		if ( 'created' === $action ) {
			update_field( self::ACF_KEY_ALLEGRO_ENABLED, 1, $product_id );
		}

		update_post_meta( $product_id, self::ALLEGRO_URL_META, OfferMapper::offer_url( $environment, $offer_id ) );

		if ( null !== $cena_allegro ) {
			// Zwykły update_post_meta(), symetrycznie do allegro_url wyżej — $product
			// już zapisany dwukrotnie powyżej (linie 232/269), doklejanie tu trzeciego
			// pełnego $product->save() tylko dla jednej meta byłoby zbędnym kosztem
			// (recenzja P-20.7a). number_format, nie cast — jak przy $shop_str wyżej
			// (LC_NUMERIC, recenzja P-6.1b).
			update_post_meta( $product_id, self::ALLEGRO_PRICE_META, number_format( $cena_allegro, 2, '.', '' ) );
		}

		// `klasa_stanu` (D-4.1.1/D-6.1.4, cutover P-12.2b): tylko gdy produkt NIE MA
		// jeszcze relacji z taksonomią — ręczna ocena egzemplarza jest źródłem prawdy
		// i nie wolno jej nadpisać. D-12.2.4: „puste" = brak relacji
		// (`ClassDefinitionsTaxonomy::for_product()`, czyta przez `get_the_terms()`),
		// NIE pusty postmeta — od P-12.2a ACF nadpisuje postmeta bezwarunkowo przy
		// każdym zapisie ekranu edycji, więc ten literał nie jest już wiarygodny.
		if ( null === ClassDefinitionsTaxonomy::for_product( $product_id ) ) {
			$kod       = OfferMapper::condition_class( $offer );
			$definicja = null !== $kod ? ClassDefinitionsTaxonomy::get( $kod ) : null;

			if ( null !== $definicja ) {
				// Pole ACF `taxonomy` (D-12.2.1) — `update_field()` z `term_id`
				// woła natywnie `wp_set_object_terms()` (save_terms=1), ten sam
				// mechanizm co edycja ręczna w adminie.
				update_field( self::ACF_KEY_CONDITION, $definicja['term_id'], $product_id );
			} elseif ( null !== $kod ) {
				$warnings[] = sprintf(
					'Kod klasy stanu „%s" (auto-mapa „Stan") nie ma jeszcze zdefiniowanego termu w taksonomii „Klasy stanu" — klasa_stanu nieustawiona (pole wymagane, uzupełnij ręcznie).',
					$kod
				);
			} else {
				$warnings[] = sprintf(
					'Nieznana wartość „Stan" („%s") — klasa_stanu nieustawiona (pole wymagane, uzupełnij ręcznie).',
					(string) OfferMapper::condition_raw( $offer )
				);
			}
		}

		// Kategoria: kuratorski term `product_cat` (D-4.2.1/D-4.2.2 + kosz D-6.1.2).
		$term_id = $this->ensure_product_cat_term( $category_slug );

		if ( null !== $term_id ) {
			$assigned = wp_set_object_terms( $product_id, array( $term_id ), 'product_cat', false );

			if ( is_wp_error( $assigned ) ) {
				$warnings[] = sprintf( 'Nie przypisano kategorii „%s": %s', $category_slug, $assigned->get_error_message() );
			}
		} else {
			$warnings[] = sprintf( 'Nie udało się utworzyć termu product_cat „%s".', $category_slug );
		}

		// Marka → natywna taksonomia `product_brand` (D-4.1.3); term z nazwy,
		// tworzony przy pierwszym użyciu.
		$brand = OfferMapper::brand( $offer );

		if ( null !== $brand ) {
			$assigned = wp_set_object_terms( $product_id, $brand, 'product_brand', false );

			if ( is_wp_error( $assigned ) ) {
				$warnings[] = sprintf( 'Nie przypisano marki „%s": %s', $brand, $assigned->get_error_message() );
			}
		}

		// Zdjęcia (mapping §1): images[0] → miniatura, reszta → galeria. Scalanie,
		// nie podmiana (D-9.1d.1, P-9.1d): zdjęcia dołożone ręcznie przez kuratora
		// (bez `ImageSideloader::META_SOURCE_URL` — nie pochodzą z URL-i oferty)
		// zostają dopisane PO zdjęciach z oferty, nigdy nie znikają z sync-u.
		if ( ! $skip_images ) {
			$sync = $this->images->sync( $product_id, OfferMapper::image_urls( $offer ) );

			foreach ( $sync['warnings'] as $warning ) {
				$warnings[] = $warning;
			}

			$ids = $sync['attachment_ids'];

			if ( array() !== $ids ) {
				$final_ids = array_merge( $ids, $this->manual_image_ids( $product, $ids ) );

				$product->set_image_id( $final_ids[0] );
				$product->set_gallery_image_ids( array_slice( $final_ids, 1 ) );
				$product->save();
			}
		}

		return array(
			'action'     => $action,
			'product_id' => $product_id,
			'warnings'   => $warnings,
		);
	}

	/**
	 * Lekki sync stanu i ceny (P-6.2b): zapisuje `_stock` oraz `cena_allegro` z
	 * przeliczeniem `_price` wg efektywnej stawki (formuła D-4.1.2, kontrakt §11)
	 * — bez dotykania reszty produktu (warstwy surowej, kategorii, zdjęć…).
	 *
	 * Zapisy są WARUNKOWE (tylko przy realnej różnicy), żeby rekoncyliacja
	 * pełnego katalogu nie robiła setek pustych `save()`. `_price` porównujemy
	 * z aktualną stawką przy KAŻDYM wywołaniu z ceną (kontrakt §11: przeliczane
	 * przy każdym sync) — zmiana samej stawki rabatu też propaguje się do ceny.
	 *
	 * Produkt w koszu = wycofany (D-6.2.1): zero zapisów, ostrzeżenie dla logu —
	 * obrona w głębi, niezależnie od filtrów wywołującego.
	 *
	 * @param int        $product_id   Id produktu Woo.
	 * @param int|null   $stock        Stan `stock.available` z Allegro (null = nie ruszaj stanu).
	 * @param float|null $cena_allegro Cena `sellingMode.price.amount` (null = nie ruszaj cen).
	 * @return array{stock_updated:bool,price_updated:bool,warnings:array<int,string>}
	 */
	public function apply_stock_and_price( int $product_id, ?int $stock, ?float $cena_allegro ): array {
		if ( 'trash' === get_post_status( $product_id ) ) {
			return array(
				'stock_updated' => false,
				'price_updated' => false,
				'warnings'      => array( sprintf( 'Produkt %d w koszu — wycofany (D-6.2.1), sync pominięty.', $product_id ) ),
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return array(
				'stock_updated' => false,
				'price_updated' => false,
				'warnings'      => array( sprintf( 'Produkt %d nie istnieje/nie jest produktem Woo — sync pominięty.', $product_id ) ),
			);
		}

		$warnings = array();

		$stock_updated = false;
		$price_updated = false;

		if ( null !== $stock && $product->get_stock_quantity() !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$stock_updated = true;
		}

		if ( null !== $cena_allegro ) {
			$rate = DiscountRate::effective_percent( $product_id );
			$shop = OfferMapper::shop_price( $cena_allegro, $rate );

			// number_format, nie cast — jak przy imporcie (LC_NUMERIC, recenzja P-6.1b).
			$shop_str = number_format( $shop, 2, '.', '' );

			if ( $product->get_regular_price() !== $shop_str ) {
				$product->set_regular_price( $shop_str );
				$product->set_price( $shop_str );
				$price_updated = true;
			}

			$current_allegro = number_format( (float) get_post_meta( $product_id, self::ALLEGRO_PRICE_META, true ), 2, '.', '' );
			$new_allegro     = number_format( $cena_allegro, 2, '.', '' );

			if ( $new_allegro !== $current_allegro ) {
				$product->update_meta_data( self::ALLEGRO_PRICE_META, $new_allegro );
				$price_updated = true;
			}
		}

		if ( $stock_updated || $price_updated ) {
			$product->save();
		}

		return array(
			'stock_updated' => $stock_updated,
			'price_updated' => $price_updated,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Szuka produktu po kluczu powiązania `_qutlet_allegro_offer_id` (idempotencja).
	 * Publiczna, bo używa jej też sync stanów (P-6.2b) — jedno miejsce z semantyką
	 * wyszukania (statusy z koszem włącznie — D-6.2.1, duplikaty z ostrzeżeniem).
	 *
	 * @param string             $offer_id  Id oferty Allegro.
	 * @param array<int,string>  $warnings  Akumulator ostrzeżeń (duplikaty klucza).
	 * @return int|null Id produktu albo null (brak → utworzenie).
	 */
	public function find_product_id( string $offer_id, array &$warnings ): ?int {
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => self::LINK_LOOKUP_STATUSES,
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'meta_key'       => AllegroLinkMeta::META_OFFER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- klucz idempotencji importu (kontrakt §10.1); indeks pod to wyszukanie to jawnie osobna decyzja FAZY 6.
				'meta_value'     => $offer_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( array() === $found ) {
			return null;
		}

		if ( count( $found ) > 1 ) {
			$warnings[] = sprintf( 'Więcej niż jeden produkt z offer_id=%s — aktualizuję pierwszy (%d).', $offer_id, (int) $found[0] );
		}

		return (int) $found[0];
	}

	/**
	 * Zbiorczy indeks powiązanych produktów: `offer_id => product_id`, JEDNYM
	 * zapytaniem (D-15.3, P-15.2) — dla WSZYSTKICH produktów z meta
	 * {@see AllegroLinkMeta::META_OFFER_ID}, ten sam zestaw statusów co
	 * {@see self::find_product_id()} ({@see self::LINK_LOOKUP_STATUSES} — w tym
	 * `trash`, żeby wycofane oferty (D-6.2.1) NIGDY nie liczyły się jako „nowe").
	 * Reużywane przez `--new-only` ({@see ImportOffersCommand}) do wyliczenia
	 * różnicy z indeksem ACTIVE zamiast N osobnych wywołań `find_product_id()`.
	 *
	 * Duplikat klucza (więcej niż jeden produkt z tym samym `offer_id` — patrz
	 * ostrzeżenie w {@see self::find_product_id()}) tu NIE jest sygnalizowany:
	 * indeks służy WYŁĄCZNIE do sprawdzenia „czy offer_id jest już znany"
	 * (obecność klucza) — który konkretnie `product_id` wygra przy duplikacie
	 * jest nieistotne dla tego użycia.
	 *
	 * @return array<string,int> `offer_id => product_id`.
	 */
	public function known_offer_ids(): array {
		global $wpdb;

		$statuses     = self::LINK_LOOKUP_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = 'SELECT pm.meta_value AS offer_id, pm.post_id AS product_id'
			. " FROM `{$wpdb->postmeta}` pm"
			. " INNER JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id"
			. ' WHERE pm.meta_key = %s'
			. " AND p.post_type = 'product'"
			. " AND p.post_status IN ( {$placeholders} )";

		$query = $wpdb->prepare( $sql, ...array_merge( array( AllegroLinkMeta::META_OFFER_ID ), $statuses ) );
		$rows  = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- zbiorczy odczyt bez odpowiednika w WP_Query (D-15.3); bez cache, bo to jednorazowy przebieg per import.

		$index = array();

		foreach ( (array) $rows as $row ) {
			$offer_id = (string) $row->offer_id;

			if ( '' === $offer_id ) {
				continue;
			}

			$index[ $offer_id ] = (int) $row->product_id;
		}

		return $index;
	}

	/**
	 * Zapewnia term `product_cat` o kuratorskim slugu (nazwa z tabeli reguł).
	 *
	 * Publiczna — tej samej logiki (utworzenie termu przy pierwszym użyciu) potrzebuje
	 * re-kategoryzacja `CategoryReportCommand::--apply` (P-6.8a), żeby nie duplikować
	 * tworzenia termu poza tym miejscem.
	 *
	 * @param string $slug Slug termu.
	 * @return int|null `term_id` albo null przy błędzie utworzenia.
	 */
	public function ensure_product_cat_term( string $slug ): ?int {
		$existing = term_exists( $slug, 'product_cat' );

		if ( is_array( $existing ) ) {
			return (int) $existing['term_id'];
		}

		$created = wp_insert_term(
			CategoryMapRules::term_name( $slug ),
			'product_cat',
			array( 'slug' => $slug )
		);

		if ( is_wp_error( $created ) ) {
			return null;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Zdjęcia z bieżącej galerii/miniatury produktu, które NIE pochodzą z sync-u
	 * (D-9.1d.1, P-9.1d) — dołożone ręcznie przez kuratora wprost w adminie, więc
	 * nie mają meta {@see ImageSideloader::META_SOURCE_URL} na załączniku. Zwracane
	 * w bieżącej kolejności (miniatura, potem galeria), z pominięciem duplikatów i
	 * id już obecnych w `$sync_ids` (świeżo pobrane/dopasowane z tego przebiegu).
	 *
	 * @param WC_Product        $product  Produkt PRZED nadpisaniem miniatury/galerii.
	 * @param array<int,int>    $sync_ids Id załączników z tego przebiegu (w kolejności oferty).
	 * @return array<int,int>
	 */
	private function manual_image_ids( WC_Product $product, array $sync_ids ): array {
		$current = array();
		$thumbnail_id = (int) $product->get_image_id();

		if ( 0 !== $thumbnail_id ) {
			$current[] = $thumbnail_id;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$current[] = (int) $gallery_id;
		}

		$manual = array();

		foreach ( array_unique( $current ) as $attachment_id ) {
			if ( in_array( $attachment_id, $sync_ids, true ) ) {
				continue;
			}

			if ( '' !== get_post_meta( $attachment_id, ImageSideloader::META_SOURCE_URL, true ) ) {
				continue;
			}

			$manual[] = $attachment_id;
		}

		return $manual;
	}

	/**
	 * Zapisuje GTIN na produkcie z rozluźnioną unikalnością (D-6.7.1, kontrakt §10.2).
	 * Woo domyślnie egzekwuje unikalność `global_unique_id` między produktami
	 * (`is_existing_global_unique_id`, `class-wc-product-data-store-cpt.php:1310`) —
	 * założenie sprzeczne z jednosztukowym outletem, gdzie ten sam model (ten sam EAN)
	 * legalnie występuje w wielu niezależnych produktach. Filtr
	 * `wc_product_pre_has_global_unique_id` (`wc-product-functions.php:1044-1080`)
	 * krótko spina `wc_product_has_global_unique_id()` do wartości zwróconej z filtra —
	 * `true` = „unikalne/OK" (pomija sprawdzenie data store, WYŁĄCZA błąd duplikatu),
	 * `false` OZNACZAŁOBY WYMUSZENIE błędu duplikatu przy KAŻDYM zapisie GTIN (odwrotność
	 * zamierzonego efektu — zweryfikowane bezpośrednio w źródle, sesja 2026-07-25,
	 * koryguje odwróconą wcześniejszą wersję kontraktu §10.2/planu). Dlatego callback to
	 * `__return_true`, NIE `__return_false`. Owinięty ściśle wokół WYŁĄCZNIE tego
	 * wywołania (`add_filter` tuż przed, `remove_filter` w `finally`) — relaksacja
	 * duplikatu nie przecieka poza to jedno zapisanie, niezależnie od tego, czy Woo
	 * rzuci wyjątkiem formatu. Walidacja FORMATU GTIN (`abstract-wc-product.php:896-902`)
	 * jest niezależna od unikalności (sprawdzana PRZED wywołaniem `wc_product_has_global_unique_id()`)
	 * i pozostaje nienaruszona.
	 *
	 * @param WC_Product        $product  Produkt, na którym zapisujemy GTIN.
	 * @param string|null       $gtin     Znormalizowany GTIN z oferty (null = brak, pomiń).
	 * @param array<int,string> $warnings Akumulator ostrzeżeń.
	 */
	private function write_gtin( WC_Product $product, ?string $gtin, array &$warnings ): void {
		if ( null === $gtin ) {
			return;
		}

		add_filter( 'wc_product_pre_has_global_unique_id', '__return_true' );

		try {
			$product->set_global_unique_id( $gtin );
		} catch ( WC_Data_Exception $e ) {
			$warnings[] = sprintf( 'GTIN „%s" odrzucony przez Woo: %s', $gtin, $e->getMessage() );
		} finally {
			remove_filter( 'wc_product_pre_has_global_unique_id', '__return_true' );
		}
	}

	/**
	 * Slug klasy podatkowej Woo dla stawki VAT (D-6.1.3): `23` → klasa standardowa
	 * (pusty slug); inne stawki → klasa `VAT <stawka>%` zakładana idempotentnie.
	 * Kwoty w tabelach stawek Woo konfiguruje człowiek (handoff) — my tylko
	 * przypisujemy produkt do klasy.
	 *
	 * @param string            $rate     Znormalizowana stawka (np. `"23"`, `"8"`).
	 * @param array<int,string> $warnings Akumulator ostrzeżeń.
	 * @return string Slug klasy podatkowej (pusty = standardowa).
	 */
	private function tax_class_for_rate( string $rate, array &$warnings ): string {
		if ( self::STANDARD_VAT_RATE === $rate ) {
			return '';
		}

		$name    = sprintf( 'VAT %s%%', $rate );
		$classes = WC_Tax::get_tax_classes();

		if ( in_array( $name, $classes, true ) ) {
			return sanitize_title( $name );
		}

		$created = WC_Tax::create_tax_class( $name );

		if ( is_wp_error( $created ) ) {
			$warnings[] = sprintf( 'Nie udało się utworzyć klasy podatkowej „%s": %s — użyto standardowej.', $name, $created->get_error_message() );

			return '';
		}

		return $created['slug'];
	}

	/**
	 * Nakłada `$overrides` (D-21.3.1, kontrakt §16 — wartości wagowo-wymiarowe
	 * przeliczone do jednostki sklepu, {@see OfferMapper::weight_dimension_attributes()})
	 * na KOPIĘ `$specification`, dopasowując po `etykieta`. Czysta funkcja —
	 * `$specification` przyjęta przez wartość, więc wołający (już zapisał TĘ
	 * SAMĄ tablicę do warstwy surowej, `RawLayerMeta::META_SPECIFICATION_RAW`)
	 * zostaje nietknięty.
	 *
	 * @param array<int, array{etykieta: string, wartosc: string}> $specification Pary etykieta→wartość ({@see OfferMapper::specification()}).
	 * @param array<string, string>                                $overrides     `etykieta => "wartość jednostka"` ({@see OfferMapper::weight_dimension_attributes()}).
	 * @return array<int, array{etykieta: string, wartosc: string}>
	 */
	private static function apply_unit_overrides( array $specification, array $overrides ): array {
		if ( array() === $overrides ) {
			return $specification;
		}

		foreach ( $specification as $index => $row ) {
			if ( isset( $overrides[ $row['etykieta'] ] ) ) {
				$specification[ $index ]['wartosc'] = $overrides[ $row['etykieta'] ];
			}
		}

		return $specification;
	}

	/**
	 * Woła jeden z setterów wymiarowych (`set_weight`/`set_length`/`set_width`/
	 * `set_height`) TYLKO gdy `$value` rozstrzygnięte (D-21.4.1 pkt 3) — `null`
	 * zostawia pole natywne nietknięte, nie zeruje go. Formatowanie przez
	 * `number_format`, NIE `(string)` cast: cast na PHP < 8.0 respektuje
	 * `LC_NUMERIC` (możliwy przecinek dziesiętny), a Woo oczekuje kropki (ten
	 * sam powód co `number_format( $shop, 2, ... )` przy cenie wyżej w tym pliku).
	 *
	 * @param WC_Product $product WC_Product_Simple| istniejący produkt.
	 * @param string     $setter  Nazwa settera (`set_weight`/`set_length`/`set_width`/`set_height`).
	 * @param float|null $value   Wartość PRZELICZONA do jednostki sklepu ({@see OfferMapper::weight_dimension_native_values()}).
	 */
	private static function write_native_dimension( WC_Product $product, string $setter, ?float $value ): void {
		if ( null === $value ) {
			return;
		}

		$formatted = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );

		$product->{$setter}( '' === $formatted ? '0' : $formatted );
	}

	/**
	 * Buduje listę atrybutów WC (custom, per-produkt — NIE taksonomia) z par
	 * etykieta→wartość surowej specyfikacji Allegro (P-13.4a, D-13.G1 — port
	 * jeden-do-jednego z `Qutlet\Ai\AiRewrite\RewriteWriter::build_attributes()`,
	 * skąd atrybuty pisała wcześniej AI zanim P-13.4b tę odpowiedzialność zabrało).
	 * Wiersze z pustą etykietą albo wartością (po sanityzacji) są pomijane — pusty
	 * atrybut nie niesie informacji.
	 *
	 * @param array<int, array{etykieta: string, wartosc: string}> $specification Pary etykieta→wartość ({@see OfferMapper::specification()}).
	 * @return array<int, WC_Product_Attribute>
	 */
	private static function build_attributes( array $specification ): array {
		$attributes = array();
		$position   = 0;

		foreach ( $specification as $row ) {
			$label = sanitize_text_field( $row['etykieta'] );
			$value = sanitize_text_field( $row['wartosc'] );

			if ( '' === $label || '' === $value ) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 ); // 0 = atrybut lokalny (custom), nie taksonomia globalna.
			$attribute->set_name( $label );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$attributes[] = $attribute;
			++$position;
		}

		return $attributes;
	}
}
