<?php
/**
 * Slice OrderSync — blokada przeciw nakładaniu przebiegów importu zamówień (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

/**
 * Zamek wzajemnego wykluczania przebiegów `sync-orders` PER ŚRODOWISKO (D-6.3.6).
 * Bliźniak {@see \Qutlet\Allegro\OfferSync\StockSyncLock} — identyczny mechanizm
 * (atomowy `INSERT IGNORE` do tabeli `options`, `autoload = no`, łamanie
 * osieroconego zamka po timeoucie), ale OSOBNY literał klucza: przebieg zamówień i
 * przebieg stanów to różni konsumenci tego samego endpointu `GET /order/events`
 * (D-6.3.6 / kontrakt §12.3) — współdzielony zamek blokowałby je nawzajem bez
 * powodu.
 *
 * Świadomie NIE reużywamy `StockSyncLock` przez parametr klucza: powielenie wzorca
 * jest tanie i trzyma slice `OrderSync/` samowystarczalnym (granica vertical slice —
 * import zamówień nie sięga po współpracownika slice'a stanów). To ta sama decyzja,
 * którą `StockSyncLock` podjął wobec `Auth\RefreshLock`.
 *
 * Literał klucza opcji: kontrakt §12.3 (VERBATIM).
 */
final class OrderSyncLock {

	/**
	 * Prefiks klucza opcji-zamka. Klucz: `qutlet_allegro_order_sync_lock_{środowisko}`
	 * — kontrakt §12.3 (VERBATIM).
	 */
	private const OPTION_PREFIX = 'qutlet_allegro_order_sync_lock_';

	/**
	 * Po tylu sekundach zamek uznajemy za osierocony i wolno go złamać. Przyrostowy
	 * polling zamówień to sekundy (kilka stron `order/events` + pojedyncze
	 * `checkout-forms/{id}`), ale zapasowo dajemy ten sam próg co sync stanów —
	 * dłużej znaczy, że przebieg padł bez zwolnienia.
	 */
	private const RELEASE_TIMEOUT = 300;

	/**
	 * Próbuje zająć zamek środowiska (atomowo). True tylko dla przebiegu, który
	 * faktycznie go zajął; przy świeżym cudzym zamku — false. Osierocony zamek
	 * (starszy niż `$timeout`) jest łamany i zajmowany ponownie.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param int    $timeout     Próg osierocenia w sekundach (domyślnie {@see self::RELEASE_TIMEOUT}).
	 * @return bool True, gdy zamek zajęty przez TEN przebieg.
	 */
	public function acquire( string $environment, int $timeout = self::RELEASE_TIMEOUT ): bool {
		global $wpdb;

		$option = self::option_key( $environment );
		$now    = time();

		// Atomowe wstawienie: powiedzie się dla dokładnie jednego przebiegu.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro order sync lock */",
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
		$this->release( $environment );

		$reinserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro order sync lock */",
				$option,
				(string) time()
			)
		);

		return (bool) $reinserted;
	}

	/**
	 * Zwalnia zamek środowiska. Bezpieczne, gdy zamka nie ma.
	 *
	 * @param string $environment Środowisko.
	 * @return void
	 */
	public function release( string $environment ): void {
		delete_option( self::option_key( $environment ) );
	}

	/**
	 * Klucz opcji-zamka dla środowiska.
	 *
	 * @param string $environment Środowisko.
	 * @return string
	 */
	private static function option_key( string $environment ): string {
		return self::OPTION_PREFIX . $environment;
	}
}
