<?php
/**
 * Test-only dublery WP hooków i wąskiego wycinka Woo 10.9.4 potrzebne do
 * charakteryzacji `ProductWriter::write_gtin()` (P-6.7b) bez ładowania
 * WordPressa/WooCommerce. Harness `qutlet-allegro` jest świadomie „bez
 * WordPressa" (phpunit.xml) — to jedyny plik, który zapełnia globalną
 * przestrzeń nazw funkcjami/klasami, których woła kod pod testem.
 *
 * Rejestr hooków (`add_filter`/`remove_filter`/`apply_filters`/`has_filter`)
 * jest WIERNĄ reimplementacją semantyki WP (priorytet, wielu subskrybentów,
 * `remove_filter` dopasowujący callback+priorytet) — nie mockiem zwracającym
 * ustalone wartości.
 *
 * `wc_product_has_global_unique_id()` i `WC_Product::set_global_unique_id()`
 * są 1:1 przepisane z realnego Woo 10.9.4 (ground-truth, sesja 2026-07-25):
 * - `wc-product-functions.php:1044-1080` (kolejność: pre-filtr → krótkie
 *   spięcie → data-store duplicate check → drugi filtr → return).
 * - `abstract-wc-product.php:892-915` (kolejność: format NAJPIERW,
 *   duplikat DOPIERO potem — niezależne sprawdzenia).
 * Jedyna świadoma podmiana: `is_existing_global_unique_id()` (realny data
 * store, SQL) zastąpiony rejestrem w `$GLOBALS['__test_wc_duplicate_gtins']`
 * (ustawianym przez test) — sama logika WOKÓŁ tego wywołania jest oryginalna.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

if ( ! function_exists( '__return_true' ) ) {
	function __return_true() {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_wp_filters'][ $hook ][] = array(
			'callback' => $callback,
			'priority' => $priority,
		);

		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['__test_wp_filters'][ $hook ] ) ) {
			return false;
		}

		foreach ( $GLOBALS['__test_wp_filters'][ $hook ] as $index => $registered ) {
			if ( $registered['callback'] === $callback && $registered['priority'] === $priority ) {
				unset( $GLOBALS['__test_wp_filters'][ $hook ][ $index ] );

				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $callback = false ) {
		if ( empty( $GLOBALS['__test_wp_filters'][ $hook ] ) ) {
			return false;
		}

		if ( false === $callback ) {
			return true;
		}

		foreach ( $GLOBALS['__test_wp_filters'][ $hook ] as $registered ) {
			if ( $registered['callback'] === $callback ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['__test_wp_filters'][ $hook ] ) ) {
			return $value;
		}

		$registered = $GLOBALS['__test_wp_filters'][ $hook ];

		usort(
			$registered,
			static function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			}
		);

		foreach ( $registered as $entry ) {
			$value = call_user_func( $entry['callback'], $value, ...$args );
		}

		return $value;
	}
}

/**
 * 1:1 z `wc-product-functions.php:1044-1080` (Woo 10.9.4) — jedyna podmiana:
 * `is_existing_global_unique_id()` (data store, SQL) → rejestr testowy.
 */
if ( ! function_exists( 'wc_product_has_global_unique_id' ) ) {
	function wc_product_has_global_unique_id( $product_id, $global_unique_id ) {
		$has_global_unique_id = apply_filters( 'wc_product_pre_has_global_unique_id', null, $product_id, $global_unique_id );

		if ( null !== $has_global_unique_id ) {
			return (bool) $has_global_unique_id;
		}

		$global_unique_id_found = in_array( $global_unique_id, $GLOBALS['__test_wc_duplicate_gtins'] ?? array(), true );

		if ( apply_filters( 'wc_product_has_global_unique_id', $global_unique_id_found, $product_id, $global_unique_id ) ) {
			return false;
		}

		return true;
	}
}

if ( ! class_exists( 'WC_Data_Exception' ) ) {
	class WC_Data_Exception extends Exception {

		/**
		 * @var string
		 */
		protected $error_code;

		public function __construct( $code, $message, $http_status_code = 400, $data = array() ) {
			$this->error_code = $code;

			parent::__construct( $message, $http_status_code );
		}

		public function getErrorCode() {
			return $this->error_code;
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/**
	 * `set_global_unique_id()` 1:1 z `abstract-wc-product.php:892-915` —
	 * format NAJPIERW (niezależny od unikalności), duplikat DOPIERO potem
	 * (przez `wc_product_has_global_unique_id()`, krótko spinalny filtrem).
	 */
	class WC_Product {

		/**
		 * @var int
		 */
		private $id;

		/**
		 * @var string
		 */
		private $global_unique_id = '';

		public function __construct( $id = 1 ) {
			$this->id = $id;
		}

		public function get_id() {
			return $this->id;
		}

		public function set_global_unique_id( $global_unique_id ) {
			$global_unique_id = preg_replace( '/[^0-9Xx\-]/', '', (string) $global_unique_id );

			if ( '' !== $global_unique_id && ! preg_match( '/^[0-9\-]*[0-9Xx]?$/', $global_unique_id ) ) {
				throw new WC_Data_Exception(
					'product_invalid_global_unique_id_format',
					'Invalid GTIN, UPC, EAN, or ISBN. The letter X is only valid as the final ISBN-10 check digit.'
				);
			}

			if ( '' !== $global_unique_id && ! wc_product_has_global_unique_id( $this->id, $global_unique_id ) ) {
				throw new WC_Data_Exception(
					'product_invalid_global_unique_id',
					'Invalid or duplicated GTIN, UPC, EAN or ISBN.'
				);
			}

			$this->global_unique_id = $global_unique_id;
		}

		public function get_global_unique_id() {
			return $this->global_unique_id;
		}
	}
}
