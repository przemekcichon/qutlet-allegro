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

		$this->assertSame( 'smartfony', CategoryMapRules::resolve_slug( $path ) );
	}

	public function test_no_rule_returns_null_never_fallback_slug(): void {
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
}
