<?php
/**
 * Slice OfferSync — blokada przeciw nakładaniu przebiegów sync stanów (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Zamek wzajemnego wykluczania przebiegów `sync-stock` PER ŚRODOWISKO (D-6.G2:
 * „chronimy przed nakładaniem przebiegów (lock)"). Kadencja systemowego crona to
 * ~2 min — przebieg, który się przeciągnie (rekoncyliacja pełnego katalogu, wolna
 * sieć), nie może zostać dogoniony przez następny: dwa równoległe przebiegi
 * przetwarzałyby ten sam kursor zdarzeń i wysyłały konkurencyjne PATCH-e.
 *
 * Mechanizm identyczny z {@see \Qutlet\Allegro\Auth\RefreshLock} (wzorzec
 * `WP_Upgrader::create_lock()`): ATOMOWY `INSERT IGNORE` do tabeli `options`
 * (unikalny klucz `option_name` = atomowość międzyprocesowa), `autoload = no`,
 * łamanie osieroconego zamka po {@see self::RELEASE_TIMEOUT}. Osobna klasa, nie
 * reużycie RefreshLock: tamten kluczuje po (środowisko × rola) z własnym
 * prefiksem i 30-sekundowym progiem skrojonym pod jedno żądanie HTTP — tu klucz
 * jest per środowisko, a próg musi pomieścić cały przebieg synca.
 *
 * Literał klucza opcji: kontrakt §10.5 (VERBATIM).
 */
final class StockSyncLock {

	/**
	 * Prefiks klucza opcji-zamka. Klucz: `qutlet_allegro_stock_sync_lock_{środowisko}`
	 * — kontrakt §10.5 (VERBATIM).
	 */
	private const OPTION_PREFIX = 'qutlet_allegro_stock_sync_lock_';

	/**
	 * Po tylu sekundach zamek uznajemy za osierocony i wolno go złamać. Przebieg
	 * przyrostowy to sekundy, rekoncyliacja pełnego katalogu (~555 ofert: ~6 stron
	 * listy + pojedyncze potwierdzenia `/parts` + PATCH-e) mieści się z zapasem
	 * w 5 minutach; dłużej znaczy, że przebieg padł bez zwolnienia.
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
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro stock sync lock */",
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
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') /* qutlet allegro stock sync lock */",
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
