<?php
/**
 * Slice OfferSync — side-load zdjęć oferty do biblioteki mediów (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Ściąga `images[]` oferty do biblioteki mediów WP i utrzymuje idempotencję po
 * URL-u źródłowym: każdy załącznik dostaje meta `_qutlet_source_url`, a kolejny
 * przebieg NIE pobiera ponownie obrazu, którego źródło już mamy (ponowny import
 * bez duplikatów — zakres P-6.1). Mapping §1: `images[0]` → miniatura, reszta →
 * galeria; zwrotka niesie URL-e, nie pliki, więc import musi je zaciągnąć.
 *
 * Wymaga plików admina (`media_sideload_image` żyje poza autoloadem frontu) —
 * dociągane w {@see self::ensure_admin_includes()}.
 */
final class ImageSideloader {

	/**
	 * `meta_key` załącznika ze źródłowym URL-em Allegro (klucz idempotencji zdjęć).
	 * Prefiks `_qutlet_` jak pozostałe nasze meta prywatne.
	 */
	public const META_SOURCE_URL = '_qutlet_source_url';

	/**
	 * Zapewnia produktowi komplet zdjęć oferty: brakujące pobiera, obecne (po
	 * `_qutlet_source_url`) tylko podpina. Zwraca id w KOLEJNOŚCI oferty.
	 *
	 * @param int               $product_id Id produktu (rodzic załączników).
	 * @param array<int,string> $urls       URL-e `images[]` w kolejności oferty.
	 * @return array{attachment_ids:array<int,int>,downloaded:int,reused:int,warnings:array<int,string>}
	 */
	public function sync( int $product_id, array $urls ): array {
		$ids        = array();
		$downloaded = 0;
		$reused     = 0;
		$warnings   = array();

		foreach ( $urls as $url ) {
			$existing = $this->find_by_source_url( $url );

			if ( null !== $existing ) {
				$ids[] = $existing;
				++$reused;

				continue;
			}

			$attachment_id = $this->sideload( $url, $product_id );

			if ( null === $attachment_id ) {
				$warnings[] = sprintf( 'Nie udało się pobrać zdjęcia: %s', $url );

				continue;
			}

			update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );
			$ids[] = $attachment_id;
			++$downloaded;
		}

		return array(
			'attachment_ids' => $ids,
			'downloaded'     => $downloaded,
			'reused'         => $reused,
			'warnings'       => $warnings,
		);
	}

	/**
	 * Szuka załącznika po źródłowym URL-u (idempotencja zdjęć).
	 *
	 * @param string $url Źródłowy URL Allegro.
	 * @return int|null Id załącznika albo null.
	 */
	private function find_by_source_url( string $url ): ?int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_SOURCE_URL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- klucz idempotencji; wolumen załączników lokalnego importu jest mały.
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( array() === $found ) {
			return null;
		}

		return (int) $found[0];
	}

	/**
	 * Pobiera obraz do biblioteki mediów jako załącznik produktu.
	 *
	 * @param string $url        Źródłowy URL.
	 * @param int    $product_id Rodzic załącznika.
	 * @return int|null Id załącznika albo null przy błędzie pobrania.
	 */
	private function sideload( string $url, int $product_id ): ?int {
		$this->ensure_admin_includes();

		$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		return (int) $attachment_id;
	}

	/**
	 * Dociąga pliki admina wymagane przez `media_sideload_image()` w kontekście
	 * WP-CLI (media/file/image nie są ładowane poza ekranami admina).
	 *
	 * @return void
	 */
	private function ensure_admin_includes(): void {
		if ( function_exists( 'media_sideload_image' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
