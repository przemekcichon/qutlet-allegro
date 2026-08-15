<?php
/**
 * Testy jednostkowe `ProductWriter::known_offer_ids()` (P-15.2, D-15.3): bulk
 * lookup „znane offer_id" JEDNYM zapytaniem, z rejestrem test-only `FakeWpdb`
 * ({@see \Qutlet\Allegro\Tests\Stubs\FakeWpdb}) zamiast realnej bazy.
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use PHPUnit\Framework\TestCase;
use Qutlet\Allegro\OfferSync\ProductWriter;
use Qutlet\Allegro\Tests\Stubs\FakeWpdb;
use Qutlet\Core\AllegroLink\AllegroLinkMeta;

/**
 * `known_offer_ids()` samo w sobie nie dotyka WP poza globalnym `$wpdb` —
 * `$GLOBALS['wpdb']` podstawiamy testowym dublerem w `setUp()`, świeżym na
 * każdy test (rejestr zapytań/wierszy nie przecieka między testami).
 */
final class ProductWriterKnownOfferIdsTest extends TestCase {

	/**
	 * @var FakeWpdb
	 */
	private $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_maps_offer_id_to_product_id(): void {
		$this->wpdb->rows = array(
			(object) array(
				'offer_id'   => '111',
				'product_id' => '5',
			),
			(object) array(
				'offer_id'   => '222',
				'product_id' => '9',
			),
		);

		$writer = new ProductWriter();

		$this->assertSame(
			array(
				'111' => 5,
				'222' => 9,
			),
			$writer->known_offer_ids()
		);
	}

	public function test_empty_result_yields_empty_index(): void {
		$this->wpdb->rows = array();

		$writer = new ProductWriter();

		$this->assertSame( array(), $writer->known_offer_ids() );
	}

	public function test_skips_row_with_empty_offer_id(): void {
		$this->wpdb->rows = array(
			(object) array(
				'offer_id'   => '',
				'product_id' => '5',
			),
			(object) array(
				'offer_id'   => '333',
				'product_id' => '7',
			),
		);

		$writer = new ProductWriter();

		$this->assertSame( array( '333' => 7 ), $writer->known_offer_ids() );
	}

	public function test_query_filters_by_meta_key_and_product_post_type(): void {
		$writer = new ProductWriter();
		$writer->known_offer_ids();

		$this->assertStringContainsString( "'" . AllegroLinkMeta::META_OFFER_ID . "'", $this->wpdb->last_query );
		$this->assertStringContainsString( "p.post_type = 'product'", $this->wpdb->last_query );
	}

	public function test_query_includes_all_link_lookup_statuses_including_trash(): void {
		$writer = new ProductWriter();
		$writer->known_offer_ids();

		foreach ( ProductWriter::LINK_LOOKUP_STATUSES as $status ) {
			$this->assertStringContainsString( "'" . $status . "'", $this->wpdb->last_query );
		}

		$this->assertStringContainsString( 'trash', $this->wpdb->last_query );
	}
}
