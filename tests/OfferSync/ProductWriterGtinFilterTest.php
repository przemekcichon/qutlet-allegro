<?php
/**
 * Testy jednostkowe OfferSync\ProductWriter::write_gtin() (P-6.7b, D-6.7.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\ProductWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Product;

/**
 * Charakteryzuje rozluźnienie unikalności `global_unique_id` (kontrakt §10.2,
 * D-6.7.1): filtr `wc_product_pre_has_global_unique_id` owinięty ściśle wokół
 * `set_global_unique_id()` w `write_gtin()` (`add_filter` przed, `remove_filter`
 * w `finally`), tak by ten sam GTIN mógł zapisać się na wielu produktach
 * (osobne egzemplarze tego samego modelu Allegro), a walidacja FORMATU GTIN
 * pozostała nienaruszona.
 *
 * Harness `qutlet-allegro` jest świadomie „bez WordPressa" (phpunit.xml) —
 * `tests/Stubs/wc-gtin-filter-stubs.php` dostarcza wierny rejestr hooków WP
 * (nie mock zwracający stałe) i przepisaną z Woo 10.9.4 (z dwiema świadomymi
 * podmianami, patrz docblok pliku stubów) logikę `wc_product_has_global_unique_id()`
 * / `WC_Product::set_global_unique_id()` (ground-truth cytowany w docblocku
 * `write_gtin()`). Metoda jest prywatna —
 * wołana przez Reflection, bo cel testu to DOKŁADNIE ta dyscyplina owijania
 * filtra, nie cały `upsert()` (który wymagałby pełnego stosu WP/Woo).
 *
 * WAŻNE (odwrócona wcześniejsza wersja kontraktu, sesja 2026-07-25): pierwszy
 * test w tym pliku ({@see self::test_pre_filter_semantics_matches_woo_source})
 * pinuje semantykę WC wprost ze źródła — `__return_true` na
 * `wc_product_pre_has_global_unique_id` DOZWALA zapis (krótkie spięcie do
 * `true`), `__return_false` WYMUSZAŁOBY błąd duplikatu przy KAŻDYM zapisie
 * GTIN. Regresja w tym miejscu cofnęłaby import do stanu gorszego niż przed
 * P-6.7b (0/524 zamiast 451/524).
 */
final class ProductWriterGtinFilterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_wp_filters']        = array();
		$GLOBALS['__test_wc_duplicate_gtins'] = array();
	}

	/**
	 * Wywołuje prywatny `ProductWriter::write_gtin()` przez Reflection.
	 *
	 * @param array<int,string> $warnings Akumulator ostrzeżeń (przekazywany przez referencję).
	 */
	private function write_gtin( WC_Product $product, ?string $gtin, array &$warnings ): void {
		$method = new ReflectionMethod( ProductWriter::class, 'write_gtin' );
		$method->setAccessible( true );
		$method->invokeArgs( new ProductWriter(), array( $product, $gtin, &$warnings ) );
	}

	// --- Pin semantyki WC (dublera skalibrowanego na źródle) ---

	public function test_pre_filter_semantics_matches_woo_source(): void {
		$GLOBALS['__test_wc_duplicate_gtins'] = array( '5901234123457' );

		$GLOBALS['__test_wp_filters']['wc_product_pre_has_global_unique_id'] = array(
			array( 'callback' => '__return_true', 'priority' => 10 ),
		);
		$this->assertTrue(
			wc_product_has_global_unique_id( 1, '5901234123457' ),
			'__return_true na wc_product_pre_has_global_unique_id musi DOZWALAĆ zapis (krótkie spięcie do true).'
		);

		$GLOBALS['__test_wp_filters']['wc_product_pre_has_global_unique_id'] = array(
			array( 'callback' => '__return_false', 'priority' => 10 ),
		);
		$this->assertFalse(
			wc_product_has_global_unique_id( 1, '5901234123457' ),
			'__return_false na wc_product_pre_has_global_unique_id wymusiłoby błąd duplikatu przy KAŻDYM zapisie GTIN — to jest odwrócona (błędna) wersja filtra.'
		);
	}

	public function test_duplicate_gtin_without_any_filter_is_rejected_by_woo_baseline(): void {
		$GLOBALS['__test_wc_duplicate_gtins'] = array( '5901234123457' );

		$product = new WC_Product( 42 );

		$this->expectException( \WC_Data_Exception::class );
		$this->expectExceptionMessage( 'Invalid or duplicated GTIN, UPC, EAN or ISBN.' );

		$product->set_global_unique_id( '5901234123457' );
	}

	// --- write_gtin(): zachowanie ProductWriter ---

	public function test_write_gtin_allows_duplicate_while_filter_active(): void {
		$GLOBALS['__test_wc_duplicate_gtins'] = array( '5901234123457' );

		$product  = new WC_Product( 42 );
		$warnings = array();

		$this->write_gtin( $product, '5901234123457', $warnings );

		$this->assertSame( array(), $warnings, 'Duplikat GTIN NIE powinien wygenerować ostrzeżenia — to sens D-6.7.1.' );
		$this->assertSame( '5901234123457', $product->get_global_unique_id() );
	}

	public function test_write_gtin_still_rejects_invalid_format(): void {
		$GLOBALS['__test_wc_duplicate_gtins'] = array();

		$product  = new WC_Product( 42 );
		$warnings = array();

		// X w środku (nie jako ostatni znak ISBN-10 checksum) — łamie FORMAT,
		// niezależnie od relaksacji unikalności (abstract-wc-product.php:896-902).
		$this->write_gtin( $product, '12X34', $warnings );

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'odrzucony przez Woo', $warnings[0] );
		$this->assertSame( '', $product->get_global_unique_id(), 'Format nieprawidłowy — GTIN nie powinien się zapisać.' );
	}

	public function test_write_gtin_removes_filter_after_success(): void {
		$product  = new WC_Product( 42 );
		$warnings = array();

		$this->write_gtin( $product, '5901234123457', $warnings );

		$this->assertFalse(
			has_filter( 'wc_product_pre_has_global_unique_id' ),
			'Filtr NIE może przeżyć poza oknem write_gtin() — inaczej relaksacja przecieka na ręczne tworzenie produktów w adminie.'
		);
	}

	public function test_write_gtin_removes_filter_after_format_exception(): void {
		$product  = new WC_Product( 42 );
		$warnings = array();

		$this->write_gtin( $product, '12X34', $warnings );

		$this->assertFalse(
			has_filter( 'wc_product_pre_has_global_unique_id' ),
			'finally musi odpiąć filtr NAWET gdy Woo rzuci wyjątkiem formatu.'
		);
	}

	public function test_write_gtin_skips_null_gtin_without_touching_filter(): void {
		$product  = new WC_Product( 42 );
		$warnings = array();

		$this->write_gtin( $product, null, $warnings );

		$this->assertSame( array(), $warnings );
		$this->assertSame( '', $product->get_global_unique_id() );
		$this->assertFalse( has_filter( 'wc_product_pre_has_global_unique_id' ) );
	}
}
