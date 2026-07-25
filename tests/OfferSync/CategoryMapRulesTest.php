<?php
/**
 * Testy jednostkowe OfferSync\CategoryMapRules (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\OfferSync;

use Qutlet\Allegro\OfferSync\CategoryMapRules;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje priorytet hybrydowego kluczowania D-4.2.2: wyjątek per-liść >
 * reguła gałęzi > reguła gałęzi wyższej, oraz zachowanie „brak reguły → null"
 * (fallback do kosza i log to decyzja wywołującego, D-6.1.2 — reguły nie mogą
 * po cichu udawać dopasowania).
 */
final class CategoryMapRulesTest extends TestCase {

	public function test_leaf_exception_beats_branch_rule(): void {
		// Liść 85166 („Bezprzewodowe") leży ilustracyjnie w gałęzi z regułą
		// `smartfony` — wyjątek per-liść MUSI wygrać (→ audio, mapping §7d pkt 3).
		$path = array(
			array(
				'id'   => '85166',
				'name' => 'Bezprzewodowe',
			),
			array(
				'id'   => '4',
				'name' => 'Telefony i Akcesoria',
			),
		);

		$this->assertSame( 'audio', CategoryMapRules::resolve_slug( $path ) );
	}

	public function test_nearest_branch_rule_wins_over_farther_one(): void {
		// Dwie reguły na ścieżce: bliższa (gaming) i dalsza (laptopy) — wygrywa bliższa.
		$path = array(
			array(
				'id'   => '999001',
				'name' => 'Pady',
			),
			array(
				'id'   => '122233',
				'name' => 'Konsole i automaty',
			),
			array(
				'id'   => '2',
				'name' => 'Komputery',
			),
		);

		$this->assertSame( 'gaming', CategoryMapRules::resolve_slug( $path ) );
	}

	public function test_unknown_leaf_in_known_branch_maps_automatically(): void {
		// Sedno D-4.2.2: nowy, niewidziany liść w znanej gałęzi nie gubi produktu.
		$path = array(
			array(
				'id'   => '424242',
				'name' => 'Nowy liść',
			),
			array(
				'id'   => '66887',
				'name' => 'Węzeł pośredni bez reguły',
			),
			array(
				'id'   => '4',
				'name' => 'Telefony i Akcesoria',
			),
		);

		$this->assertSame( 'telefony-akcesoria', CategoryMapRules::resolve_slug( $path ) );
	}

	public function test_no_rule_returns_null_never_fallback_slug(): void {
		// Gałąź celowo spoza tabeli (P-6.8b pokrywa realne gałęzie z raportu 120 liści) —
		// sprawdza, że BRAK reguły zwraca null, nie kosz `pozostale` po cichu.
		$path = array(
			array(
				'id'   => '900001',
				'name' => 'Fikcyjny liść bez reguły',
			),
			array(
				'id'   => '900000',
				'name' => 'Fikcyjna gałąź bez reguły',
			),
		);

		$this->assertNull( CategoryMapRules::resolve_slug( $path ) );
	}

	public function test_empty_path_returns_null(): void {
		$this->assertNull( CategoryMapRules::resolve_slug( array() ) );
	}

	public function test_term_names_cover_all_rule_slugs_and_fallback(): void {
		$this->assertSame( 'Pozostałe', CategoryMapRules::term_name( CategoryMapRules::FALLBACK_SLUG ) );
		$this->assertSame( 'Audio', CategoryMapRules::term_name( 'audio' ) );
		$this->assertSame( 'Peryferia', CategoryMapRules::term_name( 'peryferia' ) );
		// Slug spoza słownika dostaje czytelny fallback zamiast pustki.
		$this->assertSame( 'Nieznany', CategoryMapRules::term_name( 'nieznany' ) );
	}

	/**
	 * {@see CategoryMapRules::resolve()} (P-6.8a) — jak resolve_slug(), ale ujawnia TYP
	 * dopasowania i id węzła reguły, potrzebne raportowi kuratora do rozróżnienia
	 * wyjątku per-liść od reguły gałęzi.
	 */
	public function test_resolve_returns_leaf_type_for_exception(): void {
		$path = array(
			array(
				'id'   => '85166',
				'name' => 'Bezprzewodowe',
			),
			array(
				'id'   => '4',
				'name' => 'Telefony i Akcesoria',
			),
		);

		$this->assertSame(
			array(
				'slug'    => 'audio',
				'type'    => 'leaf',
				'rule_id' => '85166',
			),
			CategoryMapRules::resolve( $path )
		);
	}

	public function test_resolve_returns_branch_type_for_nearest_ancestor_rule(): void {
		$path = array(
			array(
				'id'   => '999001',
				'name' => 'Pady',
			),
			array(
				'id'   => '122233',
				'name' => 'Konsole i automaty',
			),
			array(
				'id'   => '2',
				'name' => 'Komputery',
			),
		);

		$this->assertSame(
			array(
				'slug'    => 'gaming',
				'type'    => 'branch',
				'rule_id' => '122233',
			),
			CategoryMapRules::resolve( $path )
		);
	}

	public function test_resolve_returns_null_when_no_rule_matches(): void {
		$path = array(
			array(
				'id'   => '900001',
				'name' => 'Fikcyjny liść bez reguły',
			),
			array(
				'id'   => '900000',
				'name' => 'Fikcyjna gałąź bez reguły',
			),
		);

		$this->assertNull( CategoryMapRules::resolve( $path ) );
	}

	public function test_resolve_returns_null_for_empty_path(): void {
		$this->assertNull( CategoryMapRules::resolve( array() ) );
	}

	public function test_resolve_slug_stays_consistent_with_resolve(): void {
		// resolve_slug() teraz deleguje do resolve() — regresja spójności obu metod.
		$path = array(
			array(
				'id'   => '4575',
				'name' => 'Myszy',
			),
		);

		$this->assertSame( 'peryferia', CategoryMapRules::resolve_slug( $path ) );
		$this->assertSame( 'peryferia', CategoryMapRules::resolve( $path )['slug'] ?? null );
	}
}
