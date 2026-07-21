<?php
/**
 * Slice Auth — magazyn tokenów OAuth (osobno para read i write) (P-2.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use InvalidArgumentException;

/**
 * Trwały magazyn tokenów. Przechowuje OSOBNO dwie pary (D-2.G1): `read`
 * (tylko-odczyt) i `write` (zapis stanu magazynowego) — każda w odrębnej opcji
 * WP, każda rotowana niezależnie. Pętla odczytu operuje wyłącznie na roli `read`
 * i nie ma dostępu do pary `write`.
 *
 * Zapis: `TokenSet` → JSON → szyfrowanie ({@see TokenCipher}) → opcja WP z
 * `autoload = false` (tokeny nie są potrzebne na każdym żądaniu). Gdy szyfrowanie
 * jest niedostępne (brak stałej `QUTLET_ALLEGRO_TOKEN_KEY` lub libsodium), zapis
 * NIE degraduje do jawnego — zwraca false (świadomie, D-2.1.1).
 *
 * Rotacja (jednorazowy refresh, D-2.G1 / manual): odświeżenie tokenów to zwykłe
 * {@see self::save()} nową parą — nadpisuje poprzednią (w tym zużyty refresh).
 * Koordynacja równoległych przebiegów i okno 60 s to zakres P-2.3 (lock), nie
 * magazynu.
 */
final class TokenStore {

	/**
	 * Rola: para tokenów tylko-odczyt.
	 */
	public const ROLE_READ = 'read';

	/**
	 * Rola: para tokenów z prawem zapisu.
	 */
	public const ROLE_WRITE = 'write';

	/**
	 * Mapa rola → klucz opcji WP.
	 *
	 * @var array<string,string>
	 */
	private const OPTION_KEYS = array(
		self::ROLE_READ  => 'qutlet_allegro_token_read',
		self::ROLE_WRITE => 'qutlet_allegro_token_write',
	);

	/**
	 * Zapisuje (lub nadpisuje przy rotacji) parę tokenów danej roli.
	 *
	 * @param string   $role   Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @param TokenSet $tokens Zestaw tokenów do zapisania.
	 * @return bool True przy powodzeniu; false gdy szyfrowanie niedostępne lub
	 *              serializacja/zapis się nie powiodły.
	 */
	public function save( string $role, TokenSet $tokens ): bool {
		$option = self::option_key( $role );

		$json = wp_json_encode( $tokens->to_array() );

		if ( false === $json ) {
			return false;
		}

		$encrypted = TokenCipher::encrypt( $json );

		if ( null === $encrypted ) {
			return false;
		}

		return update_option( $option, $encrypted, false );
	}

	/**
	 * Odczytuje parę tokenów danej roli.
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return TokenSet|null Null, gdy brak zapisanych tokenów albo są
	 *                       niedeszyfrowalne/uszkodzone.
	 */
	public function get( string $role ): ?TokenSet {
		$option = self::option_key( $role );

		$stored = get_option( $option, '' );

		if ( ! \is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$json = TokenCipher::decrypt( $stored );

		if ( null === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		if ( ! \is_array( $data ) ) {
			return null;
		}

		return TokenSet::from_array( $data );
	}

	/**
	 * Czy dla danej roli istnieje zapisany (i deszyfrowalny) zestaw tokenów.
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return bool
	 */
	public function has( string $role ): bool {
		return null !== $this->get( $role );
	}

	/**
	 * Usuwa parę tokenów danej roli (np. „Rozłącz" w P-2.2).
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return bool True, gdy opcja została usunięta (lub jej nie było).
	 */
	public function delete( string $role ): bool {
		$option = self::option_key( $role );

		if ( false === get_option( $option, false ) ) {
			return true; // Nic do usunięcia — stan docelowy osiągnięty.
		}

		return delete_option( $option );
	}

	/**
	 * Waliduje rolę i zwraca klucz opcji WP.
	 *
	 * Nieznana rola to błąd programisty (nie stan runtime) → wyjątek.
	 *
	 * @param string $role Rola do walidacji.
	 * @return string Klucz opcji WP.
	 *
	 * @throws InvalidArgumentException Gdy rola spoza `self::ROLE_READ`/`self::ROLE_WRITE`.
	 */
	private static function option_key( string $role ): string {
		if ( ! isset( self::OPTION_KEYS[ $role ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznana rola tokenu Allegro: "%s".', $role )
			);
		}

		return self::OPTION_KEYS[ $role ];
	}
}
