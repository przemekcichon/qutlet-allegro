<?php
/**
 * Slice OrderSync — harmonogram WP-Cron dla synchronizacji zamówień (P-6.9).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

use Qutlet\Allegro\Auth\Environment;
use WP_CLI;

/**
 * Harmonogram `sync-orders` (P-6.9, realizuje odłożone D-6.3.3).
 *
 * `sync-orders` była dotąd komendą WYŁĄCZNIE ręczną — auto-polling świadomie
 * odłożono w D-6.3.3 (poza zakresem P-6.3b). Po P-6.5c JEDNA komenda robi
 * import (`READY_FOR_PROCESSING`) ORAZ synchronizację statusów (tor eventowy +
 * `--full`), więc jeden scheduler pokrywa oba. Wzorzec 1:1 z
 * {@see \Qutlet\Allegro\OfferSync\StockSyncScheduler} (P-6.2b/P-6.2c):
 * self-healing zaplanowanie na `init` (z przeplanowaniem przy zmianie
 * interwału), `wp_clear_scheduled_hook` przy dezaktywacji, izolacja błędów per
 * środowisko przez `WP_CLI::runcommand()`, konfigurowalna lista środowisk przez
 * stałą `wp-config.php`. Odpala się przez ten sam systemowy tick
 * `wp cron event run --due-now` (D-6.G1) — bez nowej linii crona.
 *
 * ## Kadencje różne od `StockSyncScheduler` (D-6.9.1, decyzja użytkownika,
 * sesja 2026-07-25)
 * Zamówienia NIE mają ryzyka nadsprzedaży (to domena stanu magazynowego, D-6.G3),
 * więc przyrostowy tor idzie co {@see self::INTERVAL_SECONDS} (~5 min) — rzadziej
 * niż stanowe ~1 min, mimo że oba tory czytają ten sam tani endpoint
 * `GET /order/events` (osobne kursory, kontrakt §12.3 — D-6.3.6).
 *
 * `--full` idzie co {@see self::INTERVAL_SECONDS_FULL} (~4h) — ZNACZNIE rzadziej
 * niż stanowe ~30 min. Powód: `sync-orders --full` (D-6.5.6) NIE jest tanią
 * jedną listą jak `sync-stock --full` (`GET /sale/offers`) — iteruje WSZYSTKIE
 * zaimportowane `WC_Order` w stanie nieterminalnym i dla KAŻDEGO robi
 * `GET /order/checkout-forms/{id}` osobno (N żądań rosnące z backlogiem).
 * 4h wystarcza jako siatka bezpieczeństwa na dryf/zaległości (tor przyrostowy
 * co 5 min i tak łapie bieżące zdarzenia), bez ryzyka dla rate limitu Allegro
 * przy większym wolumenie zamówień.
 *
 * ## Osobna stała środowisk (D-6.9.2, decyzja użytkownika, sesja 2026-07-25)
 * {@see self::ENVIRONMENTS_CONSTANT} to `QUTLET_ALLEGRO_SYNC_ORDERS_ENVIRONMENTS`
 * — ŚWIADOMIE osobna od `QUTLET_ALLEGRO_SYNC_STOCK_ENVIRONMENTS` (P-6.2c), NIE
 * współdzielona. Auto-polling zamówień ma inny profil ryzyka/kosztu niż stan
 * magazynowy (patrz kadencje wyżej) i operator musi móc go wyłączyć na
 * sandboksie niezależnie od harmonogramu stanu. Format/fallback/walidacja
 * identyczne z P-6.2c ({@see self::plan_environments()}): stała niezdefiniowana
 * → oba środowiska; ≥1 prawidłowe → podzbiór + warning na literówki; zero
 * prawidłowych → twardy `WP_CLI::error()`.
 *
 * ## Nakładanie z `sync-stock` na tym samym ticku (D-6.9.3 — bez zmian kodu)
 * `OrderSyncLock` i `StockSyncLock` to niezależne locki (świadomie NIE
 * reużywane między slice'ami — patrz docblock `OrderSyncLock`), więc przebiegi
 * obu komend nigdy się nawzajem nie blokują. {@see self::run_command()} łapie
 * `\Throwable` PER komenda w pętli `foreach` środowisk (ten sam wzorzec co
 * `StockSyncScheduler`), więc awaria/przedłużenie jednej komendy nie ubija
 * pozostałych w tym samym tyknięciu `wp cron event run --due-now`.
 */
final class OrderSyncScheduler {

	/**
	 * Nazwa zdarzenia WP-Cron: przyrostowy tor eventowy (import + statusy).
	 */
	public const CRON_HOOK = 'qutlet_allegro_sync_orders';

	/**
	 * Nazwa zdarzenia WP-Cron: pełna rekoncyliacja statusów (`--full`).
	 */
	public const CRON_HOOK_FULL = 'qutlet_allegro_sync_orders_full';

	/**
	 * Identyfikator własnego harmonogramu przyrostowego (filtr `cron_schedules`).
	 */
	private const SCHEDULE_INCREMENTAL = 'qutlet_allegro_five_minutes';

	/**
	 * Kadencja przyrostowa w sekundach (D-6.9.1) — rzadziej niż stan magazynowy
	 * (~1 min): zamówienia nie mają ryzyka nadsprzedaży.
	 */
	private const INTERVAL_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Identyfikator własnego harmonogramu pełnej rekoncyliacji (filtr
	 * `cron_schedules` — wbudowane kończą się na `daily`, potrzebujemy częściej).
	 */
	private const SCHEDULE_FULL = 'qutlet_allegro_four_hours';

	/**
	 * Kadencja pełnej rekoncyliacji w sekundach (D-6.9.1) — znacznie rzadziej niż
	 * stan magazynowy (~30 min): `--full` zamówień kosztuje N żądań
	 * `GET /order/checkout-forms/{id}` (po jednym na nieterminalne zamówienie),
	 * nie jedną tanią listę jak `sync-stock --full`.
	 */
	private const INTERVAL_SECONDS_FULL = 4 * HOUR_IN_SECONDS;

	/**
	 * Kanoniczny słownik prawidłowych środowisk ORAZ fallback, gdy stała
	 * {@see self::ENVIRONMENTS_CONSTANT} jest niezdefiniowana — wzorzec 1:1 z
	 * `OfferSync\StockSyncScheduler::ENVIRONMENTS` (P-6.2c).
	 *
	 * @var array<int,string>
	 */
	private const ENVIRONMENTS = array( Environment::PRODUCTION, Environment::SANDBOX );

	/**
	 * Nazwa stałej `wp-config.php` przesłaniającej listę środowisk harmonogramu
	 * zamówień (D-6.9.2) — OSOBNA od `StockSyncScheduler::ENVIRONMENTS_CONSTANT`.
	 * Wartość: string CSV, np. `"production"` albo `"sandbox,production"`.
	 */
	private const ENVIRONMENTS_CONSTANT = 'QUTLET_ALLEGRO_SYNC_ORDERS_ENVIRONMENTS';

	/**
	 * Wpina hooki: własny interwał, oba zdarzenia crona i samonaprawialne
	 * zaplanowanie. Wołane z `bootstrap()` pod guardem `WP_CLI` (wzorzec
	 * `StockSyncScheduler::register()` — jedyny sposób odpalenia zdarzeń to
	 * `wp cron event run`, które i tak jest procesem WP-CLI).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- interwały spoza wbudowanych (jak StockSyncScheduler), uzasadnione w docblocku klasy.
		add_action( self::CRON_HOOK, array( $this, 'run_incremental' ) );
		add_action( self::CRON_HOOK_FULL, array( $this, 'run_full' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Dokłada własne interwały (~5 min, ~4h) do harmonogramów WP (wbudowane
	 * kończą się na `daily`) — filtr `cron_schedules`.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Harmonogramy WP.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE_INCREMENTAL ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Co 5 minut (qutlet-allegro sync-orders)', 'qutlet-allegro' ),
		);

		$schedules[ self::SCHEDULE_FULL ] = array(
			'interval' => self::INTERVAL_SECONDS_FULL,
			'display'  => __( 'Co 4 godziny (qutlet-allegro sync-orders --full)', 'qutlet-allegro' ),
		);

		return $schedules;
	}

	/**
	 * Idempotentnie planuje oba zdarzenia — brakujące planuje od nowa, a
	 * zaplanowane pod NIEAKTUALNYM harmonogramem przeplanowuje pod aktualny
	 * (wzorzec `StockSyncScheduler::ensure_scheduled()`).
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
	 * niż `$schedule`.
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
	 * Callback: przyrostowy tor eventowy (import + statusy), dla obu środowisk.
	 *
	 * @return void
	 */
	public function run_incremental(): void {
		foreach ( self::configured_environments() as $environment ) {
			self::run_command( 'wp qutlet-allegro sync-orders --environment=' . $environment );
		}
	}

	/**
	 * Callback: pełna rekoncyliacja statusów, dla obu środowisk.
	 *
	 * @return void
	 */
	public function run_full(): void {
		foreach ( self::configured_environments() as $environment ) {
			self::run_command( 'wp qutlet-allegro sync-orders --environment=' . $environment . ' --full' );
		}
	}

	/**
	 * Rozstrzyga listę środowisk do przebiegu ze stałej `wp-config.php`
	 * (D-6.9.2) i DZIAŁA na wyniku {@see self::plan_environments()} — loguje
	 * ostrzeżenie o pominiętych literówkach, a przy zerze prawidłowych środowisk
	 * kończy tyknięcie twardym błędem (wzorzec `StockSyncScheduler`, D-6.2c.3).
	 *
	 * @return array<int,string> Środowiska do przebiegu (kolejność kanoniczna).
	 */
	private static function configured_environments(): array {
		$is_defined = \defined( self::ENVIRONMENTS_CONSTANT );
		$raw        = $is_defined ? \constant( self::ENVIRONMENTS_CONSTANT ) : null;

		$plan = self::plan_environments( $is_defined, $raw );

		if ( null !== $plan['warning'] ) {
			WP_CLI::warning( $plan['warning'] );
		}

		if ( null !== $plan['error'] ) {
			WP_CLI::error( $plan['error'] ); // Twardy stop (wzorzec D-6.2c.3) — exit(1), ubija ten tick `cron event run`.
		}

		return $plan['environments'];
	}

	/**
	 * Czysta decyzja (D-6.9.2): co robić z (nie)zdefiniowaną stałą środowisk.
	 * Bez WP, bez efektów ubocznych — wzorzec 1:1 z
	 * `OfferSync\StockSyncScheduler::plan_environments()`.
	 *
	 * @param bool  $is_defined Czy stała jest zdefiniowana w `wp-config.php`.
	 * @param mixed $raw        Wartość stałej (dowolny typ; istotna tylko gdy `$is_defined`).
	 * @return array{environments:array<int,string>,warning:?string,error:?string}
	 */
	public static function plan_environments( bool $is_defined, $raw ): array {
		if ( ! $is_defined ) {
			return array(
				'environments' => self::ENVIRONMENTS,
				'warning'      => null,
				'error'        => null,
			);
		}

		if ( ! \is_string( $raw ) ) {
			return array(
				'environments' => array(),
				'warning'      => null,
				'error'        => \sprintf(
					'Stała %s musi być stringiem CSV (np. "production" albo "sandbox,production"), a ma typ %s.',
					self::ENVIRONMENTS_CONSTANT,
					\gettype( $raw )
				),
			);
		}

		$parsed = self::parse_environment_list( $raw );

		if ( array() === $parsed['valid'] ) {
			return array(
				'environments' => array(),
				'warning'      => null,
				'error'        => \sprintf(
					'Stała %s ("%s") nie wskazuje żadnego prawidłowego środowiska. Dozwolone: %s.',
					self::ENVIRONMENTS_CONSTANT,
					$raw,
					\implode( ', ', self::ENVIRONMENTS )
				),
			);
		}

		$warning = array() === $parsed['invalid']
			? null
			: \sprintf(
				'Stała %s zawiera nieznane środowiska (%s) — pomijam. Synchronizuję: %s.',
				self::ENVIRONMENTS_CONSTANT,
				\implode( ', ', $parsed['invalid'] ),
				\implode( ', ', $parsed['valid'] )
			);

		return array(
			'environments' => $parsed['valid'],
			'warning'      => $warning,
			'error'        => null,
		);
	}

	/**
	 * Czysty parser CSV środowisk — wzorzec 1:1 z
	 * `OfferSync\StockSyncScheduler::parse_environment_list()`.
	 *
	 * @param string $raw Surowa wartość CSV ze stałej.
	 * @return array{valid:array<int,string>,invalid:array<int,string>}
	 */
	public static function parse_environment_list( string $raw ): array {
		$normalized = array();
		foreach ( \explode( ',', $raw ) as $token ) {
			$token = \strtolower( \trim( $token ) );
			if ( '' !== $token ) {
				$normalized[] = $token;
			}
		}
		$normalized = \array_values( \array_unique( $normalized ) );

		$valid = array();
		foreach ( self::ENVIRONMENTS as $environment ) {
			if ( \in_array( $environment, $normalized, true ) ) {
				$valid[] = $environment;
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => \array_values( \array_diff( $normalized, self::ENVIRONMENTS ) ),
		);
	}

	/**
	 * Uruchamia komendę w TYM SAMYM procesie WP-CLI, bez `exit()` na błędzie, i
	 * łapie KAŻDY inny `\Throwable` — wzorzec 1:1 z
	 * `OfferSync\StockSyncScheduler::run_command()` (D-6.9.3: awaria jednego
	 * środowiska w pętli {@see self::run_incremental()}/{@see self::run_full()}
	 * nie może odebrać szansy kolejnemu).
	 *
	 * @param string $command Pełna komenda WP-CLI (bez wiodącego `wp `).
	 * @return void
	 */
	private static function run_command( string $command ): void {
		try {
			WP_CLI::runcommand(
				preg_replace( '/^wp\s+/', '', $command ),
				array(
					'launch'     => false,
					'exit_error' => false,
				)
			);
		} catch ( \Throwable $e ) {
			WP_CLI::warning( sprintf( '%s: nieoczekiwany wyjątek — %s', $command, $e->getMessage() ) );
		}
	}
}
