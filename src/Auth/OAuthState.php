<?php
/**
 * Slice Auth — jednorazowy parametr `state` OAuth (CSRF + wiązanie slotu) (P-2.2).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Zarządza parametrem `state` przepływu Authorization Code. `state` pełni tu dwie
 * role (D-2.G4 + uwaga implementacyjna P-2.2):
 *
 * 1. **CSRF** — nieprzewidywalny, JEDNORAZOWY token. Bez ważnego `state` powrotu z
 *    Allegro nie da się „podrobić": callback konsumuje token i natychmiast go kasuje.
 * 2. **Nośnik slotu i inicjatora** — `state` NIE koduje w sobie pary (środowisko,
 *    rola); jest OPAQUE, a autorytatywne dane slotu (`environment`, `role`) oraz
 *    `user_id` inicjatora żyją po stronie serwera w transiencie. Callback nigdy nie
 *    ufa parametrom z URL-a — odczytuje slot z transientu (tamper-proof) i sprawdza,
 *    że powracający zalogowany użytkownik to ten sam, który autoryzację rozpoczął.
 *
 * Powód wiązania z użytkownikiem: callback to trasa REST, a cookie-auth REST bez
 * nonce `wp_rest` zeruje bieżącego użytkownika (`rest_cookie_check_errors()`), więc
 * `current_user_can()` w callbacku zawsze zwróci false. Powrót z Allegro to zwykła
 * nawigacja przeglądarki i nonce'a nie doniesie (redirect_uri musi pasować DOKŁADNIE).
 * Uprawnienie ustala więc {@see OAuthController} niezależnie od warstwy REST
 * (walidacja ciasteczka logowania), a `state` wiąże powrót z konkretnym inicjatorem.
 *
 * Nośnik: transient WP (`qutlet_allegro_oauth_state_{state}`), TTL {@see self::TTL}.
 */
final class OAuthState {

	/**
	 * Prefiks klucza transientu przechowującego kontekst `state`.
	 */
	private const PREFIX = 'qutlet_allegro_oauth_state_';

	/**
	 * Czas życia `state` (okno na dokończenie autoryzacji po stronie Allegro).
	 */
	private const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Wydaje nowy jednorazowy `state` i zapamiaduje jego kontekst po stronie serwera.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param int    $user_id     ID użytkownika rozpoczynającego autoryzację.
	 * @return string Opaque token `state` do przekazania w URL autoryzacji.
	 */
	public static function issue( string $environment, string $role, int $user_id ): string {
		$state = bin2hex( random_bytes( 16 ) ); // 32 znaki hex.

		set_transient(
			self::PREFIX . $state,
			array(
				'environment' => $environment,
				'role'        => $role,
				'user_id'     => $user_id,
			),
			self::TTL
		);

		return $state;
	}

	/**
	 * Konsumuje `state`: zwraca zapamiętany kontekst i NATYCHMIAST kasuje transient
	 * (jednorazowość). Zwraca null, gdy `state` ma zły format, wygasł, został już
	 * użyty lub jest uszkodzony.
	 *
	 * @param string $state Wartość `state` z callbacku.
	 * @return array{environment:string,role:string,user_id:int}|null
	 */
	public static function consume( string $state ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $state ) ) {
			return null;
		}

		$key  = self::PREFIX . $state;
		$data = get_transient( $key );

		// Jednorazowość: kasujemy niezależnie od dalszej walidacji zawartości.
		delete_transient( $key );

		if ( ! \is_array( $data )
			|| ! isset( $data['environment'], $data['role'], $data['user_id'] ) ) {
			return null;
		}

		return array(
			'environment' => (string) $data['environment'],
			'role'        => (string) $data['role'],
			'user_id'     => (int) $data['user_id'],
		);
	}
}
