<?php
/**
 * Slice OfferSync — harmonogram WP-Cron dla synchronizacji stanów (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use WP_CLI;

/**
 * Harmonogram `sync-stock` (D-6.G1, ZREWIDOWANE 2026-07-24 — decyzja użytkownika).
 *
 * Pierwotne sformułowanie D-6.G1 („WP-Cron nie daje kadencji co 2 min") było zbyt
 * kategoryczne — WordPress pozwala zarejestrować własny interwał przez filtr
 * `cron_schedules`. Zamiast systemowego crona wołającego BEZPOŚREDNIO naszą komendę
 * WP-CLI, cała logika harmonogramu (interwały, hooki) mieszka w kodzie jako
 * `wp_schedule_event()` — wersjonowana, widoczna przez `wp cron event list`.
 * Systemowy cron tyka JEDNĄ, stałą linią (`wp cron event run --due-now`, ~1 min) —
 * konfiguracja crona na Local by Flywheel to nadal **handoff** (środowisko
 * izolowane), ale prościej niż poprzednia wersja (jedna linia, nie dwie o różnej
 * kadencji). Wymaga `DISABLE_WP_CRON=true` w `wp-config.php` (inaczej pageview-owy
 * pseudo-cron też próbowałby odpalać zdarzenia — nieszkodliwe dzięki `StockSyncLock`,
 * ale osłabia gwarancję „tyka dokładnie wtedy, gdy chcemy").
 *
 * Wzorzec identyczny z {@see \Qutlet\Allegro\Auth\RefreshScheduler}: self-healing
 * zaplanowanie na `init`, `wp_clear_scheduled_hook` przy dezaktywacji. Dwa zdarzenia:
 * przyrostowe (~2 min, {@see self::CRON_HOOK}) i pełna rekoncyliacja (raz dziennie
 * w nocy, {@see self::CRON_HOOK_FULL} — zmierzone: `--full` na 555 ofertach trwa
 * pojedyncze sekundy, w przeciwieństwie do pełnego importu P-6.1b).
 *
 * ## Dlaczego `WP_CLI::runcommand()`, nie bezpośrednie wywołanie `SyncStockCommand`
 * `wp cron event run --due-now` to pełny proces WP-CLI, więc `WP_CLI::error()`
 * wewnątrz {@see SyncStockCommand} DZIAŁA — ale jego domyślne zachowanie to
 * `exit()`, co ubiłoby CAŁY proces `cron event run`, w tym inne zdarzenia due w
 * tym samym tyknięciu (np. `Auth\RefreshScheduler`). `WP_CLI::runcommand()` z
 * `exit_error => false` uruchamia komendę W TYM SAMYM procesie (bez nowego
 * PHP — `launch => false`), ale zamienia `exit()` na zwykły powrót z kodem błędu.
 *
 * Cel produkcyjny: harmonogram celowo pracuje na `Environment::PRODUCTION` (D-6.G5
 * — sandbox zostaje ręcznym narzędziem deweloperskim, `wp qutlet-allegro
 * sync-stock` bez flagi).
 */
final class StockSyncScheduler {

	/**
	 * Nazwa zdarzenia WP-Cron: przyrostowy pull + ponowienie zaległych pushy.
	 */
	public const CRON_HOOK = 'qutlet_allegro_sync_stock';

	/**
	 * Nazwa zdarzenia WP-Cron: pełna rekoncyliacja katalogu (`--full`).
	 */
	public const CRON_HOOK_FULL = 'qutlet_allegro_sync_stock_full';

	/**
	 * Identyfikator własnego harmonogramu (filtr `cron_schedules`).
	 */
	private const SCHEDULE_INCREMENTAL = 'qutlet_allegro_two_minutes';

	/**
	 * Kadencja przyrostowa w sekundach (D-6.G1: cel „co ~2 min").
	 */
	private const INTERVAL_SECONDS = 2 * MINUTE_IN_SECONDS;

	/**
	 * Rekoncyliacja pełna: wbudowany harmonogram WP „daily" wystarcza (zmierzone
	 * — przebieg trwa pojedyncze sekundy, nie potrzeba częstszej kadencji).
	 */
	private const RECURRENCE_FULL = 'daily';

	/**
	 * Godzina (czas serwera) pierwszego uruchomienia pełnej rekoncyliacji — poza
	 * godzinami szczytu sklepu. Kolejne przebiegi „daily" liczą się od tego czasu.
	 */
	private const NIGHTLY_HOUR = 3;

	/**
	 * Wpina hooki: własny interwał, oba zdarzenia crona i samonaprawialne
	 * zaplanowanie. Wołane z `bootstrap()` (pod guardem `WP_CLI` — wystarczające,
	 * bo JEDYNY sposób odpalenia zdarzeń to `wp cron event run`, które i tak jest
	 * procesem WP-CLI; zwykły request HTTP i tak nic by tu nie odpalił przy
	 * `DISABLE_WP_CRON=true`).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- interwał krótszy niż wbudowane (D-6.G1); uzasadnione w docblocku klasy.
		add_action( self::CRON_HOOK, array( $this, 'run_incremental' ) );
		add_action( self::CRON_HOOK_FULL, array( $this, 'run_full' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Dokłada własny interwał ~2 min do harmonogramów WP (wbudowane kończą się na
	 * `daily`) — filtr `cron_schedules`.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Harmonogramy WP.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE_INCREMENTAL ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Co 2 minuty (qutlet-allegro sync-stock)', 'qutlet-allegro' ),
		);

		return $schedules;
	}

	/**
	 * Idempotentnie planuje oba zdarzenia, jeśli nie są jeszcze zaplanowane.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE_INCREMENTAL, self::CRON_HOOK );
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK_FULL ) ) {
			wp_schedule_event( self::next_nightly_run(), self::RECURRENCE_FULL, self::CRON_HOOK_FULL );
		}
	}

	/**
	 * Usuwa oba zdarzenia crona. Wołane przy dezaktywacji wtyczki.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK_FULL );
	}

	/**
	 * Callback: przyrostowy pull + ponowienie zaległych pushy.
	 *
	 * @return void
	 */
	public function run_incremental(): void {
		self::run_command( 'wp qutlet-allegro sync-stock --environment=' . Environment::PRODUCTION );
	}

	/**
	 * Callback: pełna rekoncyliacja katalogu.
	 *
	 * @return void
	 */
	public function run_full(): void {
		self::run_command( 'wp qutlet-allegro sync-stock --environment=' . Environment::PRODUCTION . ' --full' );
	}

	/**
	 * Uruchamia komendę w TYM SAMYM procesie WP-CLI, bez `exit()` na błędzie —
	 * patrz docblock klasy („Dlaczego `WP_CLI::runcommand()`").
	 *
	 * @param string $command Pełna komenda WP-CLI (bez wiodącego `wp `).
	 * @return void
	 */
	private static function run_command( string $command ): void {
		WP_CLI::runcommand(
			preg_replace( '/^wp\s+/', '', $command ),
			array(
				'launch'     => false,
				'exit_error' => false,
			)
		);
	}

	/**
	 * Najbliższe wystąpienie {@see self::NIGHTLY_HOUR} (czas serwera) — dziś, jeśli
	 * jeszcze nie minęła, inaczej jutro. Kolejne przebiegi „daily" liczą się od
	 * tego pierwszego zaplanowania.
	 *
	 * @return int Znacznik czasu (unix).
	 */
	private static function next_nightly_run(): int {
		$today = (int) strtotime( sprintf( 'today %02d:00', self::NIGHTLY_HOUR ) );

		return $today > time() ? $today : (int) strtotime( sprintf( 'tomorrow %02d:00', self::NIGHTLY_HOUR ) );
	}
}
