<?php
/**
 * Testy jednostkowe OfferSync\ProductWriter::manual_image_ids() (P-9.1d, D-9.1d.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\ImageSideloader;
use Qutlet\Allegro\OfferSync\ProductWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WC_Product;

/**
 * Charakteryzuje wykrywanie zdjęć dołożonych ręcznie przez kuratora (BEZ meta
 * {@see ImageSideloader::META_SOURCE_URL}) — te NIE mogą zniknąć z galerii przy
 * kolejnym sync-u (scalanie, nie podmiana, D-9.1d.1). Metoda jest prywatna —
 * wołana przez Reflection, jak `ProductWriterGtinFilterTest::write_gtin()`.
 */
final class ProductWriterManualImagesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__test_post_meta'] = array();
	}

	/**
	 * @param array<int,int> $sync_ids Id załączników z bieżącego przebiegu (kolejność oferty).
	 * @return array<int,int>
	 */
	private function manual_image_ids( WC_Product $product, array $sync_ids ): array {
		$method = new ReflectionMethod( ProductWriter::class, 'manual_image_ids' );
		$method->setAccessible( true );

		return $method->invoke( new ProductWriter(), $product, $sync_ids );
	}

	private function mark_synced( int $attachment_id, string $url = 'https://a.allegroimg.com/original/x' ): void {
		$GLOBALS['__test_post_meta'][ $attachment_id ][ ImageSideloader::META_SOURCE_URL ] = $url;
	}

	public function test_no_manual_images_when_gallery_matches_sync_ids(): void {
		$product = new WC_Product( 1 );
		$product->set_image_id( 10 );
		$product->set_gallery_image_ids( array( 11, 12 ) );

		$this->mark_synced( 10 );
		$this->mark_synced( 11 );
		$this->mark_synced( 12 );

		$this->assertSame( array(), $this->manual_image_ids( $product, array( 10, 11, 12 ) ) );
	}

	public function test_returns_thumbnail_and_gallery_images_without_source_url_meta(): void {
		$product = new WC_Product( 1 );
		$product->set_image_id( 99 ); // Ręcznie ustawiona miniatura — brak META_SOURCE_URL.
		$product->set_gallery_image_ids( array( 11, 50 ) ); // 50 = ręcznie dołożone zdjęcie wady egzemplarza.

		$this->mark_synced( 11 );

		$this->assertSame( array( 99, 50 ), $this->manual_image_ids( $product, array( 11 ) ) );
	}

	public function test_orphaned_synced_image_no_longer_in_offer_is_not_treated_as_manual(): void {
		$product = new WC_Product( 1 );
		$product->set_image_id( 10 );
		$product->set_gallery_image_ids( array( 20 ) ); // Zdjęcie usunięte z oferty Allegro — sync-owned, nie ręczne.

		$this->mark_synced( 10 );
		$this->mark_synced( 20 );

		$this->assertSame(
			array(),
			$this->manual_image_ids( $product, array( 10 ) ),
			'Zdjęcie sync-owned, którego już nie ma w ofercie, nie jest "ręczne" — po prostu znika, jak dotąd.'
		);
	}

	public function test_deduplicates_thumbnail_repeated_in_gallery(): void {
		$product = new WC_Product( 1 );
		$product->set_image_id( 99 );
		$product->set_gallery_image_ids( array( 99, 50 ) );

		$this->assertSame( array( 99, 50 ), $this->manual_image_ids( $product, array() ) );
	}

	public function test_no_thumbnail_set_yields_only_manual_gallery_images(): void {
		$product = new WC_Product( 1 );
		$product->set_gallery_image_ids( array( 50 ) );

		$this->assertSame( array( 50 ), $this->manual_image_ids( $product, array() ) );
	}
}
