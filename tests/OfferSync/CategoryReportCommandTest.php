<?php
/**
 * Testy jednostkowe czystych funkcji OfferSync\CategoryReportCommand (P-6.8a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\CategoryMapRules;
use Qutlet\Allegro\OfferSync\CategoryReportCommand;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje `build_row()` (rdzeń raportu: dopasowanie reguły vs stan DZIŚ
 * przypisany na produktach), `describe_path()` i `to_csv()` — wszystkie bez WordPressa
 * (żadna z nich nie dotyka `get_post_meta`/`WP_CLI`/bazy).
 */
final class CategoryReportCommandTest extends TestCase {

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	private function audio_exception_path(): array {
		return array(
			array(
				'id'   => '85166',
				'name' => 'Bezprzewodowe',
			),
			array(
				'id'   => '66887',
				'name' => 'RTV i AGD — poddział',
			),
			array(
				'id'   => '10',
				'name' => 'RTV i AGD',
			),
		);
	}

	public function test_build_row_ok_when_current_matches_leaf_exception(): void {
		$row = CategoryReportCommand::build_row( '85166', $this->audio_exception_path(), 32, array( 'audio' ) );

		$this->assertSame( '85166', $row['leaf_id'] );
		$this->assertSame( 'Bezprzewodowe', $row['leaf_name'] );
		$this->assertSame( 32, $row['imported_products'] );
		$this->assertSame( 'audio', $row['current_product_cat'] );
		$this->assertSame( 'leaf', $row['matched_rule_type'] );
		$this->assertSame( 'audio', $row['matched_rule_slug'] );
		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_build_row_flags_drift_when_current_differs_from_rule(): void {
		// Liść dziś w koszu `pozostale`, ale reguła gałęzi każe `laptopy` — do zmiany.
		$path = array(
			array(
				'id'   => '424242',
				'name' => 'Nowy liść',
			),
			array(
				'id'   => '2',
				'name' => 'Komputery',
			),
		);

		$row = CategoryReportCommand::build_row( '424242', $path, 5, array( CategoryMapRules::FALLBACK_SLUG ) );

		$this->assertSame( 'branch', $row['matched_rule_type'] );
		$this->assertSame( 'laptopy', $row['matched_rule_slug'] );
		$this->assertSame( CategoryMapRules::FALLBACK_SLUG, $row['current_product_cat'] );
		$this->assertSame( 'do-zmiany', $row['status'] );
	}

	public function test_build_row_no_rule_matches_expected_fallback(): void {
		$path = array(
			array(
				'id'   => '260556',
				'name' => 'Grille elektryczne',
			),
			array(
				'id'   => '10',
				'name' => 'RTV i AGD',
			),
		);

		$row = CategoryReportCommand::build_row( '260556', $path, 3, array( CategoryMapRules::FALLBACK_SLUG ) );

		$this->assertSame( 'brak', $row['matched_rule_type'] );
		$this->assertSame( CategoryMapRules::FALLBACK_SLUG, $row['matched_rule_slug'] );
		$this->assertSame( 'ok', $row['status'] ); // kosz -> kosz, zgodne.
	}

	public function test_build_row_unresolved_path_is_flagged_regardless_of_current(): void {
		$row = CategoryReportCommand::build_row( '999999', array(), 1, array( 'audio' ) );

		$this->assertSame( '(nierozwiązana)', $row['leaf_name'] );
		$this->assertSame( '(nierozwiązana — uruchom z --resolve-missing)', $row['path'] );
		$this->assertSame( 'nierozwiazana-sciezka', $row['status'] );
		// Reguły NIE dało się policzyć bez ścieżki — pokazanie fallbacku `pozostale` jako
		// „dopasowanej reguły" sugerowałoby wynik, którego realnie nie ma (recenzja P-6.8a).
		$this->assertSame( 'n/d', $row['matched_rule_type'] );
		$this->assertSame( '', $row['matched_rule_slug'] );
	}

	public function test_build_row_empty_leaf_id_shows_placeholder(): void {
		$row = CategoryReportCommand::build_row( '', array(), 1, array() );

		$this->assertSame( '(brak)', $row['leaf_id'] );
		$this->assertSame( '(brak)', $row['current_product_cat'] );
	}

	public function test_build_row_multiple_current_slugs_never_equals_single_expected(): void {
		// Produkty tego samego liścia z rozjechanym product_cat (ręczna ingerencja) —
		// zbiór wielowartościowy nigdy nie zrówna się z pojedynczym oczekiwanym slugiem,
		// więc zawsze wypada jako 'do-zmiany' (sygnał do przejrzenia przez kuratora).
		$row = CategoryReportCommand::build_row( '85166', $this->audio_exception_path(), 2, array( 'smartfony', 'audio' ) );

		$this->assertSame( 'audio|smartfony', $row['current_product_cat'] );
		$this->assertSame( 'do-zmiany', $row['status'] );
	}

	public function test_describe_path_reads_root_to_leaf(): void {
		$this->assertSame(
			'RTV i AGD (10) > RTV i AGD — poddział (66887) > Bezprzewodowe (85166)',
			CategoryReportCommand::describe_path( $this->audio_exception_path() )
		);
	}

	public function test_to_csv_empty_rows_returns_empty_string(): void {
		$this->assertSame( '', CategoryReportCommand::to_csv( array() ) );
	}

	public function test_to_csv_writes_header_and_rows(): void {
		$rows = array(
			array(
				'leaf_id' => '85166',
				'status'  => 'ok',
			),
			array(
				'leaf_id' => '424242',
				'status'  => 'do-zmiany',
			),
		);

		$csv = CategoryReportCommand::to_csv( $rows );

		$this->assertSame(
			"leaf_id,status\n85166,ok\n424242,do-zmiany\n",
			str_replace( "\r\n", "\n", substr( $csv, strlen( "\xEF\xBB\xBF" ) ) )
		);
	}

	public function test_to_csv_starts_with_utf8_bom(): void {
		// Warsztat pod arkusz (Excel): bez BOM-a polskie diakrytyki („Pozostałe") w
		// nazwach kategorii rozjeżdżają się przy domyślnym CP-1250 (recenzja P-6.8a).
		$csv = CategoryReportCommand::to_csv( array( array( 'leaf_name' => 'Pozostałe' ) ) );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( 'Pozostałe', $csv );
	}
}
