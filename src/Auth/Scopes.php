<?php
/**
 * Slice Auth — zakresy (scope) OAuth Allegro per rola (P-2.2, D-2.G6).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use InvalidArgumentException;

/**
 * Jedno źródło prawdy dla zakresów OAuth przekazywanych przy autoryzacji
 * (manual Allegro: scope'y deklarowane przy rejestracji aplikacji ORAZ przekazywane
 * przy autoryzacji). Literały VERBATIM z `docs/plan.md` → D-2.G6 (potwierdzone na
 * realnych aplikacjach 2026-07-21) — NIE zgadujemy, NIE modyfikujemy bez decyzji.
 *
 * - rola `read`:  `allegro:api:sale:offers:read`, `allegro:api:orders:read`
 * - rola `write`: `allegro:api:sale:offers:read`, `allegro:api:sale:offers:write`,
 *                 `allegro:api:sale:settings:read`, `allegro:api:sale:settings:write`
 *
 * Rola `write` zawiera też `offers:read` (zapis oferty wymaga odczytu jej stanu
 * przed modyfikacją). Zestaw `sale:settings:*` służy WYŁĄCZNIE zasiewowi sandboxa
 * (FAZA 3A); przy rejestracji aplikacji `production/write` pomija się go (D-2.G6) —
 * ale to decyzja rejestracji aplikacji po stronie użytkownika, nie tego kodu:
 * tutaj rola `write` ma jeden, symetryczny zestaw scope'ów niezależnie od
 * środowiska. Allegro i tak przyznaje jedynie część wspólną z tym, co ma aplikacja.
 */
final class Scopes {

	/**
	 * Zakresy per rola (VERBATIM D-2.G6). Klucze = stałe ról z {@see Environment}.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const BY_ROLE = array(
		Environment::ROLE_READ  => array(
			'allegro:api:sale:offers:read',
			'allegro:api:orders:read',
		),
		Environment::ROLE_WRITE => array(
			'allegro:api:sale:offers:read',
			'allegro:api:sale:offers:write',
			'allegro:api:sale:settings:read',
			'allegro:api:sale:settings:write',
		),
	);

	/**
	 * Lista zakresów dla roli.
	 *
	 * @param string $role Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @return array<int,string>
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru (błąd programisty).
	 */
	public static function for_role( string $role ): array {
		if ( ! isset( self::BY_ROLE[ $role ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznana rola tokenu Allegro: "%s".', $role )
			);
		}

		return self::BY_ROLE[ $role ];
	}

	/**
	 * Zakresy jako pojedynczy string rozdzielony spacjami (format parametru
	 * `scope` w URL autoryzacji OAuth).
	 *
	 * @param string $role Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @return string
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru.
	 */
	public static function as_string( string $role ): string {
		return implode( ' ', self::for_role( $role ) );
	}
}
