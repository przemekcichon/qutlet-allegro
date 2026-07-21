<?php
/**
 * Slice Auth — blokada przeciw nakładaniu się odświeżeń tokenów (P-2.3).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Blokada wzajemnego wykluczania na POZIOMIE SLOTU (środowisko × rola), chroniąca
 * przed jednoczesnym odświeżaniem tego samego slotu przez dwa przebiegi (on-demand
 * + cron zabezpieczający, albo dwa równoległe żądania). Zakres z planu P-2.3:
 * „zabezpieczenie przed nakładaniem przebiegów (lock)".
 *
 * Dlaczego to ważne przy tokenach Allegro: refresh token jest JEDNORAZOWY — po
 * użyciu Allegro zwraca nową parę i unieważnia poprzedni refresh (okno tolerancji
 * 60 s, patrz {@see TokenRefresher}). Dwa równoległe odświeżenia bez blokady
 * zużyłyby ten sam refresh dwa razy i po 60 s jedno z nich dostałoby `invalid_grant`.
 * Blokada sprawia, że w obrębie tej instalacji odświeża naraz tylko jeden przebieg;
 * okno 60 s Allegro jest jedynie siatką bezpieczeństwa na resztkowy wyścig
 * międzyprocesowy, którego blokada nie obejmuje.
 *
 * Mechanizm (wzorowany na `WP_Upgrader::create_lock()` z rdzenia WP): ATOMOWY
 * `INSERT IGNORE` do tabeli `options`. Unikalny klucz `option_name` gwarantuje, że
 * wstawienie powiedzie się dla dokładnie jednego przebiegu — to jest atomowość
 * międzyprocesowa, której zwykłe `get_option()`+`add_option()` nie daje (wyścig
 * TOCTOU). Opcja ma `autoload = no`. Zblokowany (osierocony) zamek starszy niż
 * {@see self::RELEASE_TIMEOUT} jest łamany (przebieg mógł paść przed zwolnieniem).
 */
final class RefreshLock {

	/**
	 * Prefiks klucza opcji-zamka. Klucz: `qutlet_allegro_refresh_lock_{środowisko}_{rola}`.
	 */
	private const OPTION_PREFIX = 'qutlet_allegro_refresh_lock_';

	/**
	 * Po tylu sekundach zamek uznajemy za osierocony (przebieg padł bez zwolnienia)
	 * i wolno go złamać. Odświeżenie tokenu to jedno żądanie HTTP z timeoutem 15 s
	 * ({@see TokenClient}) — 30 s daje bezpieczny zapas.
	 */
	private const RELEASE_TIMEOUT = 30;

	/**
	 * Próbuje zająć zamek slotu (atomowo). Zwraca true tylko dla przebiegu, który
	 * faktycznie go zajął; przy zajętym (świeżym) zamku — false. Osierocony zamek
	 * (starszy niż `$timeout`) jest łamany i zajmowany ponownie.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param int    $timeout     Próg osierocenia w sekundach (domyślnie {@see self::RELEASE_TIMEOUT}).
	 * @return bool True, gdy zamek zajęty przez TEN przebieg.
	 */
	public function acquire( string $environment, string $role, int $timeout = self::RELEASE_TIMEOUT ): bool {
		global $wpdb;

		$option = self::option_key( $environment, $role );
		$now    = time();

		// Atomowe wstawienie: powiedzie się dla dokładnie jednego przebiegu.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro refresh lock */",
				$option,
				(string) $now
			)
		);

		if ( $inserted ) {
			return true;
		}

		// Zamek istnieje — sprawdź, czy nie jest osierocony. Czytamy prosto z bazy
		// (nie `get_option`), bo świeży `INSERT IGNORE` omija cache opcji WP.
		$locked_at = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $option )
		);

		if ( $locked_at > 0 && ( $now - $locked_at ) < $timeout ) {
			return false; // Świeży zamek trzymany przez inny przebieg.
		}

		// Osierocony (albo znikł tuż po odczycie) — złam i spróbuj zająć ponownie.
		$this->release( $environment, $role );

		$reinserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro refresh lock */",
				$option,
				(string) time()
			)
		);

		return (bool) $reinserted;
	}

	/**
	 * Zwalnia zamek slotu. Bezpieczne do wywołania, gdy zamka nie ma.
	 *
	 * @param string $environment Środowisko slotu.
	 * @param string $role        Rola slotu.
	 * @return void
	 */
	public function release( string $environment, string $role ): void {
		delete_option( self::option_key( $environment, $role ) );
	}

	/**
	 * Klucz opcji-zamka dla slotu.
	 *
	 * @param string $environment Środowisko slotu.
	 * @param string $role        Rola slotu.
	 * @return string
	 */
	private static function option_key( string $environment, string $role ): string {
		return self::OPTION_PREFIX . $environment . '_' . $role;
	}
}
