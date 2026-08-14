<?php
/**
 * Test-only dubler `get_post_meta()` (P-9.1d) — rejestr w `$GLOBALS['__test_post_meta']`
 * (`[post_id][meta_key] => wartość`), zamiast realnej bazy WP. Potrzebny przez
 * `ProductWriter::manual_image_ids()` (czyta `ImageSideloader::META_SOURCE_URL`
 * na załącznikach), charakteryzowany w `ProductWriterManualImagesTest`.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		return $GLOBALS['__test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}
