<?php
/**
 * Slice Auth — magazyn tokenów OAuth (cztery sloty: środowisko × rola) (P-2.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use InvalidArgumentException;

/**
 * Trwały magazyn tokenów. Przechowuje OSOBNO do czterech par (zrewidowane D-2.G1)
 * kluczowanych DWUWYMIAROWO — (środowisko × rola): `production/read`,
 * `production/write`, `sandbox/read`, `sandbox/write`. Każdy slot w odrębnej
 * opcji WP, każdy rotowany niezależnie. Pętla odczytu operuje wyłącznie na roli
 * `read` danego środowiska i nie ma dostępu do slotów `write`; operacja na
 * sandboxie nie sięga poświadczeń produkcji.
 *
 * Klucz opcji WP (schemat wyprowadzalny programowo):
 *   `qutlet_allegro_token_{środowisko}_{rola}`, np. `qutlet_allegro_token_production_read`.
 * Poprzednie klucze z P-2.1 (`qutlet_allegro_token_read` / `…_write`) WYCOFANE;
 * migracja niepotrzebna — nigdy nie zapisano do nich tokenów.
 *
 * Słownik slotu (środowiska + role) mieszka w {@see Environment} — magazyn referuje
 * do jego stałych (naturalny kierunek zależności storage → config, jedno źródło prawdy).
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
	 * Prefiks klucza opcji WP dla slotu tokenów.
	 */
	private const OPTION_PREFIX = 'qutlet_allegro_token_';

	/**
	 * Zapisuje (lub nadpisuje przy rotacji) parę tokenów danego slotu.
	 *
	 * @param string   $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string   $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param TokenSet $tokens      Zestaw tokenów do zapisania.
	 * @return bool True przy powodzeniu; false gdy szyfrowanie niedostępne lub
	 *              serializacja/zapis się nie powiodły.
	 *
	 * @throws InvalidArgumentException Gdy slot (środowisko/rola) spoza dozwolonego zbioru.
	 */
	public function save( string $environment, string $role, TokenSet $tokens ): bool {
		$option = self::option_key( $environment, $role );

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
	 * Odczytuje parę tokenów danego slotu.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @return TokenSet|null Null, gdy brak zapisanych tokenów albo są
	 *                       niedeszyfrowalne/uszkodzone.
	 *
	 * @throws InvalidArgumentException Gdy slot (środowisko/rola) spoza dozwolonego zbioru.
	 */
	public function get( string $environment, string $role ): ?TokenSet {
		$option = self::option_key( $environment, $role );

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
	 * Czy dla danego slotu istnieje zapisany (i deszyfrowalny) zestaw tokenów.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @return bool
	 *
	 * @throws InvalidArgumentException Gdy slot (środowisko/rola) spoza dozwolonego zbioru.
	 */
	public function has( string $environment, string $role ): bool {
		return null !== $this->get( $environment, $role );
	}

	/**
	 * Usuwa parę tokenów danego slotu (np. „Rozłącz" w P-2.2).
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @return bool True, gdy opcja została usunięta (lub jej nie było).
	 *
	 * @throws InvalidArgumentException Gdy slot (środowisko/rola) spoza dozwolonego zbioru.
	 */
	public function delete( string $environment, string $role ): bool {
		$option = self::option_key( $environment, $role );

		if ( false === get_option( $option, false ) ) {
			return true; // Nic do usunięcia — stan docelowy osiągnięty.
		}

		return delete_option( $option );
	}

	/**
	 * Waliduje slot (środowisko × rola) i zwraca klucz opcji WP.
	 *
	 * Nieznane środowisko/rola to błąd programisty (nie stan runtime) → wyjątek.
	 * Słownik obu osi pochodzi z {@see Environment} (jedno źródło prawdy).
	 *
	 * @param string $environment Środowisko do walidacji.
	 * @param string $role        Rola do walidacji.
	 * @return string Klucz opcji WP.
	 *
	 * @throws InvalidArgumentException Gdy środowisko lub rola spoza dozwolonego zbioru.
	 */
	private static function option_key( string $environment, string $role ): string {
		if ( Environment::SANDBOX !== $environment && Environment::PRODUCTION !== $environment ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznane środowisko Allegro: "%s".', $environment )
			);
		}

		if ( Environment::ROLE_READ !== $role && Environment::ROLE_WRITE !== $role ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznana rola tokenu Allegro: "%s".', $role )
			);
		}

		return self::OPTION_PREFIX . $environment . '_' . $role;
	}
}
