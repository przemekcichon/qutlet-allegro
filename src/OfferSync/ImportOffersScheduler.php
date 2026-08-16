<?php
/**
 * Slice OfferSync — harmonogram WP-Cron dla taniego delta-checku importu (P-15.3a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use WP_CLI;

/**
 * Harmonogram `import-offers --new-only --mark-ended` (FAZA 15, D-15.5) —
 * cykliczne dokładanie NOWYCH ofert do katalogu (bez powtarzania pełnego
 * importu) ORAZ wygaszanie kanału Allegro na produktach, których oferta
 * zniknęła z ACTIVE, wraz z auto-reversalem (P-15.4, D-15.7/D-15.11/D-15.13
 * — dopisane do TEGO SAMEGO tyknięcia, patrz docblock {@see self::run()}).
 *
 * Wzorzec 1:1 z {@see \Qutlet\Allegro\OfferSync\StockSyncScheduler} /
 * {@see \Qutlet\Allegro\OrderSync\OrderSyncScheduler}: własny interwał
 * `cron_schedules`, self-healing zaplanowanie na `init` (z przeplanowaniem przy
 * zmianie interwału), `wp_clear_scheduled_hook` przy dezaktywacji, izolacja
 * błędów per środowisko przez `WP_CLI::runcommand()`, konfigurowalna lista
 * środowisk przez stałą `wp-config.php`. Odpala się przez ten sam systemowy
 * tick `wp cron event run --due-now` (D-6.G1) — bez nowej linii crona.
 *
 * ## JEDEN hook, nie dwa (różnica względem `StockSyncScheduler`/`OrderSyncScheduler`)
 * Tamte dwa scheduler mają osobny tor przyrostowy i `--full`, bo `--full` tam
 * robi coś ISTOTNIE innego i droższego (pełna rekoncyliacja stanu/zamówień).
 * Delta-check importu (D-15.1/D-15.2) z natury JEST już pełnym skanem listy
 * `GET /sale/offers` za każdym przebiegiem — to sam w sobie tani „pełny" krok,
 * nie ma czego dzielić na dwa tory. Stąd {@see self::CRON_HOOK} to jedyne
 * zdarzenie, wołające zawsze `import-offers --new-only` (D-15.5).
 *
 * ## Kadencja (D-15.5)
 * 15 min — pośrednia między stanem magazynowym (~1 min, ryzyko nadsprzedaży,
 * {@see \Qutlet\Allegro\OfferSync\StockSyncScheduler}) a zamówieniami (~5 min,
 * doświadczenie klienta, {@see \Qutlet\Allegro\OrderSync\OrderSyncScheduler}):
 * nowe oferty nie niosą ryzyka finansowego, ale częstszy niż nocny tick
 * uzasadnia bieżący dopływ katalogu. Startowa wartość do zmierzenia/korekty
 * realnym kosztem paginowanej listy przy większym katalogu (jak 30 min dla
 * `sync-stock --full` zostało ZMIERZONE, nie zgadnięte, sesja 2026-07-24).
 *
 * ## Konfigurowalne środowiska bez deploya
 * {@see self::ENVIRONMENTS_CONSTANT} to `QUTLET_ALLEGRO_IMPORT_OFFERS_ENVIRONMENTS`
 * — ŚWIADOMIE osobna od `StockSyncScheduler::ENVIRONMENTS_CONSTANT` i
 * `OrderSyncScheduler::ENVIRONMENTS_CONSTANT` (ten sam motyw co D-6.9.2: import
 * ofert ma inny profil od stanu/zamówień, operator musi móc go wyłączyć
 * niezależnie). Format/fallback/walidacja identyczne z P-6.2c
 * ({@see self::plan_environments()}): stała niezdefiniowana → oba środowiska;
 * ≥1 prawidłowe → podzbiór + warning na literówki; zero prawidłowych → twardy
 * `WP_CLI::error()`.
 *
 * ## 429/backoff (D-15.6)
 * Bez nowej logiki retry/backoff — `import-offers` (nawet z `--new-only`) kończy
 * błędem HTTP na dowolnej stronie listy `WP_CLI::error()` (zachowanie
 * niezmienione, ground-truth P-15.1), co pod schedulerem degraduje się do
 * warninga dzięki {@see self::run_command()} (`exit_error => false` +
 * `catch (\Throwable)`, ten sam wzorzec co `StockSyncScheduler`); kolejne
 * tyknięcie (15 min) jest naturalnym ponowieniem.
 *
 * ## Dług: trzecia niemal identyczna kopia (świadomie NIE rozwiązany tutaj)
 * `plan_environments()`/`parse_environment_list()`/`run_command()`/
 * `ensure_hook_schedule()` to TRZECIA kopia tego samego wzorca po
 * `StockSyncScheduler` i `OrderSyncScheduler` — reguła trzech sugeruje wspólny
 * trait. Świadomie odłożone poza zakres P-15.3a (patrz `docs/plan.md`): reużycie
 * konstruktu obejmowałoby retrofit dwóch już zmergowanych, przetestowanych
 * schedulerów produkcyjnych — większy diff i ryzyko niż ten punkt (jedna nowa
 * klasa) uzasadnia. Kandydat na osobny, przyszły punkt.
 */
final class ImportOffersScheduler {

	/**
	 * Nazwa zdarzenia WP-Cron: delta-check importu (`import-offers --new-only`).
	 */
	public const CRON_HOOK = 'qutlet_allegro_import_offers_delta';

	/**
	 * Identyfikator własnego harmonogramu (filtr `cron_schedules`).
	 */
	private const SCHEDULE = 'qutlet_allegro_fifteen_minutes';

	/**
	 * Kadencja w sekundach (D-15.5) — startowa wartość do zmierzenia/korekty
	 * przy realizacji (patrz docblock klasy).
	 */
	private const INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;

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
	 * (D-15.5) — OSOBNA od `StockSyncScheduler::ENVIRONMENTS_CONSTANT` i
	 * `OrderSyncScheduler::ENVIRONMENTS_CONSTANT`. Wartość: string CSV, np.
	 * `"production"` albo `"sandbox,production"`.
	 */
	private const ENVIRONMENTS_CONSTANT = 'QUTLET_ALLEGRO_IMPORT_OFFERS_ENVIRONMENTS';

	/**
	 * Wpina hooki: własny interwał, zdarzenie crona i samonaprawialne
	 * zaplanowanie. Wołane z `bootstrap()` pod guardem `WP_CLI` (wzorzec
	 * `StockSyncScheduler::register()` — jedyny sposób odpalenia zdarzeń to
	 * `wp cron event run`, które i tak jest procesem WP-CLI).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- interwał spoza wbudowanych (jak StockSyncScheduler/OrderSyncScheduler), uzasadnione w docblocku klasy.
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Dokłada własny interwał (~15 min) do harmonogramów WP (wbudowane kończą
	 * się na `daily`) — filtr `cron_schedules`.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Harmonogramy WP.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Co 15 minut (qutlet-allegro import-offers --new-only --mark-ended)', 'qutlet-allegro' ),
		);

		return $schedules;
	}

	/**
	 * Idempotentnie planuje zdarzenie — brakujące planuje od nowa, a
	 * zaplanowane pod NIEAKTUALNYM harmonogramem przeplanowuje pod aktualny
	 * (wzorzec `StockSyncScheduler::ensure_scheduled()`).
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		self::ensure_hook_schedule( self::CRON_HOOK, self::SCHEDULE );
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
	 * Usuwa zdarzenie crona. Wołane przy dezaktywacji wtyczki.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Callback: delta-check importu (`import-offers --new-only --mark-ended`),
	 * dla obu środowisk. `--mark-ended` (P-15.4, D-15.7/D-15.13) dopisane do
	 * TEGO SAMEGO tyknięcia — reużywa dokładnie te same zbiory ACTIVE/known
	 * co `--new-only` (zero nowego kosztu API/crona), więc wygaszanie kanału
	 * Allegro po zniknięciu oferty i auto-reversal (D-15.11) działają
	 * automatycznie w tej samej kadencji 15 min, bez osobnego harmonogramu.
	 *
	 * @return void
	 */
	public function run(): void {
		foreach ( self::configured_environments() as $environment ) {
			self::run_command( 'wp qutlet-allegro import-offers --new-only --mark-ended --environment=' . $environment );
		}
	}

	/**
	 * Rozstrzyga listę środowisk do przebiegu ze stałej `wp-config.php`
	 * (D-15.5) i DZIAŁA na wyniku {@see self::plan_environments()} — loguje
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
	 * Czysta decyzja: co robić z (nie)zdefiniowaną stałą środowisk. Bez WP,
	 * bez efektów ubocznych — wzorzec 1:1 z
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
	 * `OfferSync\StockSyncScheduler::run_command()` (awaria jednego środowiska
	 * w pętli {@see self::run()} nie może odebrać szansy kolejnemu).
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
