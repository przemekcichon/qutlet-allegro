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
 * przyrostowe (~1 min, {@see self::CRON_HOOK}) i pełna rekoncyliacja (~30 min,
 * {@see self::CRON_HOOK_FULL} — zmierzone na realnym sandboksie: `--full` na
 * 555 ofertach trwa pojedyncze sekundy, w przeciwieństwie do pełnego importu
 * P-6.1b; użytkownik wybrał 30 min zamiast pierwotnie rozważanej nocnej kadencji
 * właśnie dzięki tej niskiej cenie przebiegu). Kadencja przyrostowa zrewidowana
 * z ~2 min na 1 min (sesja 2026-07-24): systemowy tick i tak leci co 1 min
 * (handoff), więc dokładniejszy interwał zdarzenia jest „za darmo" — mniejsze
 * opóźnienie wykrycia zmiany, bez dodatkowego kosztu.
 *
 * ## Dlaczego OBA środowiska na każdym tyknięciu (poprawka po realnym teście)
 * Pierwsza wersja celowo pracowała TYLKO na `Environment::PRODUCTION` — założenie
 * było „sandbox zostaje ręcznym narzędziem deweloperskim". W praktyce (sesja
 * 2026-07-24, realny test na Local) to założenie okazało się mylące: deweloperskie
 * testowanie na sandboksie trwa równolegle z uruchomioną automatyzacją produkcyjną,
 * więc harmonogram MUSI obejmować oba środowiska — dokładnie jak
 * {@see \Qutlet\Allegro\Auth\RefreshScheduler}, który też leci po WSZYSTKICH
 * slotach (środowisko × rola), nie tylko produkcyjnych. Koszt jest pomijalny:
 * sandbox i produkcja to OSOBNE aplikacje/limity Allegro (D-2.G3), więc podwojenie
 * liczby wywołań nie zagraża żadnemu współdzielonemu rate limitowi.
 *
 * ## Dlaczego `WP_CLI::runcommand()`, nie bezpośrednie wywołanie `SyncStockCommand`
 * `wp cron event run --due-now` to pełny proces WP-CLI, więc `WP_CLI::error()`
 * wewnątrz {@see SyncStockCommand} DZIAŁA — ale jego domyślne zachowanie to
 * `exit()`, co ubiłoby CAŁY proces `cron event run`, w tym inne zdarzenia due w
 * tym samym tyknięciu (np. `Auth\RefreshScheduler`). `WP_CLI::runcommand()` z
 * `exit_error => false` uruchamia komendę W TYM SAMYM procesie (bez nowego
 * PHP — `launch => false`), ale zamienia `exit()` na zwykły powrót z kodem błędu.
 *
 * ## Samonaprawa OBEJMUJE zmianę interwału, nie tylko brak zaplanowania
 * {@see self::ensure_scheduled()} nie tylko planuje zdarzenie, gdy go brak — także
 * PRZEPLANOWUJE, gdy zdarzenie istnieje pod INNYM harmonogramem niż aktualnie
 * zarejestrowany (np. po tej właśnie rewizji kadencji z ~2 min na ~1 min). Bez
 * tego istniejące zdarzenie zostałoby na starym interwale aż do ręcznego
 * `wp cron event delete` — samonaprawa musi obejmować też zmiany w kodzie, nie
 * tylko brak wpisu.
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
	 * Identyfikator własnego harmonogramu przyrostowego (filtr `cron_schedules`).
	 */
	private const SCHEDULE_INCREMENTAL = 'qutlet_allegro_one_minute';

	/**
	 * Kadencja przyrostowa w sekundach — D-6.G1 celuje w „co ~2 min", ale
	 * dopasowana do kadencji systemowego ticku (~1 min, handoff) nie kosztuje nic
	 * ekstra (sesja 2026-07-24).
	 */
	private const INTERVAL_SECONDS = MINUTE_IN_SECONDS;

	/**
	 * Identyfikator własnego harmonogramu pełnej rekoncyliacji (filtr
	 * `cron_schedules` — wbudowane kończą się na `daily`, potrzebujemy częściej).
	 */
	private const SCHEDULE_FULL = 'qutlet_allegro_thirty_minutes';

	/**
	 * Kadencja pełnej rekoncyliacji w sekundach — decyzja użytkownika (sesja
	 * 2026-07-24) po zmierzeniu realnego przebiegu (pojedyncze sekundy na 555
	 * ofertach): 30 min zamiast pierwotnie rozważanej nocnej kadencji.
	 */
	private const INTERVAL_SECONDS_FULL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Oba środowiska — patrz docblock klasy („Dlaczego OBA środowiska…"), wzorzec
	 * z `Auth\RefreshScheduler::slots()` (tam też oba środowiska, każda oś osobno).
	 *
	 * @var array<int,string>
	 */
	private const ENVIRONMENTS = array( Environment::SANDBOX, Environment::PRODUCTION );

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
	 * Dokłada własne interwały (~1 min, ~30 min) do harmonogramów WP (wbudowane
	 * kończą się na `daily`) — filtr `cron_schedules`.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Harmonogramy WP.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE_INCREMENTAL ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Co minutę (qutlet-allegro sync-stock)', 'qutlet-allegro' ),
		);

		$schedules[ self::SCHEDULE_FULL ] = array(
			'interval' => self::INTERVAL_SECONDS_FULL,
			'display'  => __( 'Co 30 minut (qutlet-allegro sync-stock --full)', 'qutlet-allegro' ),
		);

		return $schedules;
	}

	/**
	 * Idempotentnie planuje oba zdarzenia — brakujące planuje od nowa, a
	 * zaplanowane pod NIEAKTUALNYM harmonogramem (np. po rewizji kadencji w
	 * kodzie) przeplanowuje pod aktualny (patrz docblock klasy „Samonaprawa
	 * OBEJMUJE zmianę interwału").
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		self::ensure_hook_schedule( self::CRON_HOOK, self::SCHEDULE_INCREMENTAL );
		self::ensure_hook_schedule( self::CRON_HOOK_FULL, self::SCHEDULE_FULL );
	}

	/**
	 * Planuje pojedynczy hook pod wskazanym harmonogramem — od nowa, jeśli
	 * nie istnieje, albo przeplanowuje, jeśli istnieje pod INNYM harmonogramem
	 * niż `$schedule` (kod się zmienił, zaplanowane zdarzenie jeszcze nie).
	 *
	 * @param string $hook     Nazwa zdarzenia crona.
	 * @param string $schedule Docelowy identyfikator harmonogramu (`cron_schedules`).
	 * @return void
	 */
	private static function ensure_hook_schedule( string $hook, string $schedule ): void {
		$scheduled = wp_get_scheduled_event( $hook );

		if ( false !== $scheduled && $schedule === $scheduled->schedule ) {
			return;
		}

		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( time(), $schedule, $hook );
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
	 * Callback: przyrostowy pull + ponowienie zaległych pushy, dla obu środowisk.
	 *
	 * @return void
	 */
	public function run_incremental(): void {
		foreach ( self::ENVIRONMENTS as $environment ) {
			self::run_command( 'wp qutlet-allegro sync-stock --environment=' . $environment );
		}
	}

	/**
	 * Callback: pełna rekoncyliacja katalogu, dla obu środowisk.
	 *
	 * @return void
	 */
	public function run_full(): void {
		foreach ( self::ENVIRONMENTS as $environment ) {
			self::run_command( 'wp qutlet-allegro sync-stock --environment=' . $environment . ' --full' );
		}
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
}
