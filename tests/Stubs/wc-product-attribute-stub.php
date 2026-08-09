<?php
/**
 * Test-only dubler `WC_Product_Attribute` (+ zależne helpery WP/Woo) potrzebny do
 * charakteryzacji `ProductWriter::build_attributes()` (P-13.4a, D-13.G1) bez
 * ładowania WordPressa/WooCommerce. Harness `qutlet-allegro` jest świadomie „bez
 * WordPressa" (`phpunit.xml.dist`) — wzorzec jak `wc-gtin-filter-stubs.php` (P-6.7b).
 *
 * `WC_Product_Attribute` przepisana z realnego Woo 11.0.0 (ground-truth, sesja
 * 2026-08-09, `includes/class-wc-product-attribute.php:26-33,168-297`) — tylko
 * setter/gettery i `is_taxonomy()` faktycznie wołane przez `build_attributes()`
 * (id/name/options/position/visible/variation); reszta klasy (taksonomie,
 * `ArrayAccess`, extra data) POMINIĘTA jako nieużywana przez port testowany tu.
 *
 * `absint()`/`wc_string_to_bool()` — wierne minimalne dublery (WP core /
 * `wc-formatting-functions.php:26-29`), bo real setter na id/visible/variation ich
 * używa. `sanitize_text_field()` — ŚWIADOMIE uproszczony do `trim()` (nie 1:1 z WP
 * core, które dodatkowo strip-uje tagi/znaki kontrolne) — poprawność WP-owego
 * `sanitize_text_field()` to odpowiedzialność rdzenia WP, nie tego portu; test tu
 * ćwiczy WYŁĄCZNIE logikę `build_attributes()` (pomijanie pustych wierszy, kształt
 * atrybutu, pozycjonowanie), nie samą sanityzację.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

if ( ! function_exists( 'absint' ) ) {
	function absint( $number ) {
		return abs( (int) $number );
	}
}

if ( ! function_exists( 'wc_string_to_bool' ) ) {
	function wc_string_to_bool( $string ) {
		$string = $string ?? '';

		return is_bool( $string ) ? $string : ( 'yes' === strtolower( $string ) || 1 === $string || 'true' === strtolower( $string ) || '1' === $string );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! class_exists( 'WC_Product_Attribute' ) ) {
	/**
	 * 1:1 (wąski wycinek) z `includes/class-wc-product-attribute.php` (Woo 11.0.0).
	 */
	class WC_Product_Attribute {

		/**
		 * @var array
		 */
		protected $data = array(
			'id'        => 0,
			'name'      => '',
			'options'   => array(),
			'position'  => 0,
			'visible'   => false,
			'variation' => false,
		);

		public function is_taxonomy() {
			return 0 < $this->get_id();
		}

		public function set_id( $value ) {
			$this->data['id'] = absint( $value );
		}

		public function set_name( $value ) {
			$this->data['name'] = $value;
		}

		public function set_options( $value ) {
			$this->data['options'] = $value;
		}

		public function set_position( $value ) {
			$this->data['position'] = absint( $value );
		}

		public function set_visible( $value ) {
			$this->data['visible'] = wc_string_to_bool( $value );
		}

		public function set_variation( $value ) {
			$this->data['variation'] = wc_string_to_bool( $value );
		}

		public function get_id() {
			return $this->data['id'];
		}

		public function get_name() {
			return $this->data['name'];
		}

		public function get_options() {
			return $this->data['options'];
		}

		public function get_position() {
			return $this->data['position'];
		}

		public function get_visible() {
			return $this->data['visible'];
		}

		public function get_variation() {
			return $this->data['variation'];
		}
	}
}
