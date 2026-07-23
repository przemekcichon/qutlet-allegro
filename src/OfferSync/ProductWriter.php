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
use Qutlet\Core\ProductInfo\RawLayerMeta;
use WC_Data_Exception;
use WC_Product;
use WC_Product_Simple;
use WC_Tax;

/**
 * Tworzy/aktualizuje produkt Woo z pełnej zwrotki oferty wg mappingu FAZY 4.
 *
 * Idempotencja: kluczem powiązania jest meta `_qutlet_allegro_offer_id`
 * (kontrakt §10.1) — ponowny import znajduje istniejący produkt i AKTUALIZUJE go,
 * nigdy nie duplikuje.
 *
 * Podział własności pól (kto jest źródłem prawdy — kontrakt §2/§4/§9/§10/§11):
 * - nadpisywane każdym przebiegiem (sync-owned): tytuł, stock, `_price` (formuła
 *   D-4.1.2), `cena_allegro`, `allegro_url`, `allegro_wlaczone`, GTIN, VAT,
 *   warstwa surowa (verbatim JSON + pola parsowane — W TEJ SAMEJ operacji, z tej
 *   samej zwrotki, D-6.G4), pola `AllegroLink`, kategoria, marka, zdjęcia;
 * - ustawiane TYLKO gdy puste: `klasa_stanu` (D-6.1.4 — auto-mapa daje wartość
 *   domyślną, ręczna ocena egzemplarza nie jest nadpisywana);
 * - NIGDY nie dotykane: warstwa przerobiona (`opis`, atrybuty WC — D-6.G4),
 *   `cena_rynkowa_nowego`, `zawartosc_zestawu`, `_qutlet_stawka_rabatu`.
 *
 * Literały meta bierzemy ze STAŁYCH klas core (twarda zależność) — jedno źródło
 * prawdy zamiast powtarzania stringów z kontraktu. Klucze pól ACF to literały
 * `field_qutlet_*` przepisane VERBATIM z rejestracji w core (P-1.2/P-1.3);
 * `update_field()` po kluczu zapisuje wartość ORAZ referencję pola.
 */
final class ProductWriter {

	/**
	 * Klucz ACF pola `allegro_wlaczone` (VERBATIM z `AllegroChannelFields` w core).
	 */
	private const ACF_KEY_ALLEGRO_ENABLED = 'field_qutlet_allegro_wlaczone';

	/**
	 * Klucz ACF pola `allegro_url` (VERBATIM z `AllegroChannelFields` w core).
	 */
	private const ACF_KEY_ALLEGRO_URL = 'field_qutlet_allegro_url';

	/**
	 * Klucz ACF pola `cena_allegro` (VERBATIM z `AllegroChannelFields` w core).
	 */
	private const ACF_KEY_ALLEGRO_PRICE = 'field_qutlet_cena_allegro';

	/**
	 * Klucz ACF pola `klasa_stanu` (VERBATIM z `ProductConditionFields` w core).
	 */
	private const ACF_KEY_CONDITION = 'field_qutlet_klasa_stanu';

	/**
	 * `meta_key` (name) pola `klasa_stanu` — do odczytu bieżącej wartości.
	 */
	private const CONDITION_META = 'klasa_stanu';

	/**
	 * `meta_key` (name) pola `cena_allegro` — do odczytu bieżącej wartości przy
	 * lekkim syncu (P-6.2b); zapis idzie przez klucz ACF jak przy imporcie.
	 */
	private const ALLEGRO_PRICE_META = 'cena_allegro';

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
	 * @param string                                   $status        Status posta dla NOWEGO produktu (`publish`/`draft`).
	 * @param bool                                     $skip_images   Pominięcie side-loadu zdjęć (przebiegi próbne).
	 * @return array{action:string,product_id:int,warnings:array<int,string>}
	 */
	public function upsert(
		array $offer,
		string $verbatim_json,
		string $environment,
		string $category_slug,
		array $category_path,
		string $status,
		bool $skip_images
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

		// Pola natywne (mapping §1).
		$name = $offer['name'] ?? null;

		if ( is_string( $name ) && '' !== $name ) {
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

		// GTIN → natywne Woo `global_unique_id` (kontrakt §10.2). Woo waliduje format
		// i rzuca wyjątkiem — nieprawidłowy EAN nie może wywrócić całego importu.
		$gtin = OfferMapper::gtin( $offer );

		if ( null !== $gtin ) {
			try {
				$product->set_global_unique_id( $gtin );
			} catch ( WC_Data_Exception $e ) {
				$warnings[] = sprintf( 'GTIN „%s" odrzucony przez Woo: %s', $gtin, $e->getMessage() );
			}
		}

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
		 * Warstwa surowa (D-6.G4): verbatim JSON i OBA pola parsowane zapisujemy w tej
		 * samej operacji, z TEJ SAMEJ zwrotki — pole parsowane nigdy nie może przeżyć
		 * JSON-a, z którego powstało. `wp_slash()` bo `update_post_meta()` unslashuje
		 * wejście, a verbatim ma zostać bajt-w-bajt (JSON niesie backslashe).
		 */
		update_post_meta( $product_id, RawLayerMeta::META_OFFER, wp_slash( $verbatim_json ) );
		update_post_meta( $product_id, RawLayerMeta::META_DESCRIPTION_RAW, wp_slash( OfferMapper::description_raw( $offer ) ) );
		update_post_meta( $product_id, RawLayerMeta::META_SPECIFICATION_RAW, wp_slash( OfferMapper::specification( $offer ) ) );

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

		// Pola ACF kanału Allegro (kontrakt §4) — sync-owned.
		update_field( self::ACF_KEY_ALLEGRO_ENABLED, 1, $product_id );
		update_field( self::ACF_KEY_ALLEGRO_URL, OfferMapper::offer_url( $environment, $offer_id ), $product_id );

		if ( null !== $cena_allegro ) {
			update_field( self::ACF_KEY_ALLEGRO_PRICE, $cena_allegro, $product_id );
		}

		// `klasa_stanu` (D-4.1.1/D-6.1.4): tylko gdy pole PUSTE — ręczna ocena
		// egzemplarza jest źródłem prawdy i nie wolno jej nadpisać.
		$current_condition = get_post_meta( $product_id, self::CONDITION_META, true );

		if ( '' === $current_condition || null === $current_condition || false === $current_condition ) {
			$condition = OfferMapper::condition_class( $offer );

			if ( null !== $condition ) {
				update_field( self::ACF_KEY_CONDITION, $condition, $product_id );
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

		// Zdjęcia (mapping §1): images[0] → miniatura, reszta → galeria.
		if ( ! $skip_images ) {
			$sync = $this->images->sync( $product_id, OfferMapper::image_urls( $offer ) );

			foreach ( $sync['warnings'] as $warning ) {
				$warnings[] = $warning;
			}

			$ids = $sync['attachment_ids'];

			if ( array() !== $ids ) {
				$product->set_image_id( $ids[0] );
				$product->set_gallery_image_ids( array_slice( $ids, 1 ) );
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

			if ( number_format( $cena_allegro, 2, '.', '' ) !== $current_allegro ) {
				update_field( self::ACF_KEY_ALLEGRO_PRICE, $cena_allegro, $product_id );
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
	 * Zapewnia term `product_cat` o kuratorskim slugu (nazwa z tabeli reguł).
	 *
	 * @param string $slug Slug termu.
	 * @return int|null `term_id` albo null przy błędzie utworzenia.
	 */
	private function ensure_product_cat_term( string $slug ): ?int {
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
}
