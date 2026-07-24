<?php
/**
 * Slice Auth — cron zabezpieczający odświeżanie tokenów OAuth (P-2.3).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Cron zabezpieczający: proaktywnie odświeża tokeny slotów, zanim access wygaśnie,
 * żeby konsument (FAZA 3/6) nie trafił na martwy token nawet bez ruchu on-demand.
 * Droga on-demand ({@see TokenRefresher::get_valid()}) jest podstawowa; cron to
 * siatka bezpieczeństwa.
 *
 * WP-Cron — świadoma decyzja, rozgraniczenie względem D-6.G1: D-6.G1 (ZREWIDOWANE
 * 2026-07-24 — patrz {@see \Qutlet\Allegro\OfferSync\StockSyncScheduler}) i tak
 * używa WP-Cron dla sync stanów magazynowych (własny interwał ~2 min przez filtr
 * `cron_schedules`), więc granica dziś nie jest „WP-Cron vs systemowy cron", tylko
 * „wymaga JEDNEGO niezawodnego zewnętrznego tyknięcia (`wp cron event run
 * --due-now`, konfiguracja na Local = handoff) czy nie". Odświeżanie tokenu ma
 * kadencję GODZINOWĄ (access żyje 12 h) i NIE jest czasowo-krytyczne — pageview-owy
 * pseudo-cron (albo brak ruchu przez dłuższą chwilę) mu nie zaszkodzi, bo podstawą
 * jest odświeżanie on-demand (`TokenRefresher::get_valid()`), a to zdarzenie jest
 * wyłącznie zabezpieczeniem. Nawet gdy `DISABLE_WP_CRON=true` (bo sync stanów tego
 * wymaga), to samo tyknięcie `wp cron event run --due-now`, które obsługuje sync,
 * odpali też TO zdarzenie, gdy jest due — nie potrzeba nic dodatkowego. Przebieg
 * da się też wywołać ręcznie (`wp cron event run {@see self::CRON_HOOK}`).
 *
 * Odświeżamy tylko sloty, które: (a) są połączone, (b) mają jeszcze ważny refresh
 * (inaczej potrzeba ponownej autoryzacji — nie ma czym odświeżać) i (c) access
 * wygasa w oknie {@see self::CRON_THRESHOLD}. Każdy slot idzie przez
 * {@see TokenRefresher::refresh()} (zamek + rotacja) — sloty są niezależne.
 */
final class RefreshScheduler {

	/**
	 * Nazwa zdarzenia WP-Cron (hook akcji uruchamianej przez cron).
	 */
	public const CRON_HOOK = 'qutlet_allegro_refresh_tokens';

	/**
	 * Kadencja crona — wbudowany harmonogram WP „hourly". Access żyje 12 h, więc
	 * odświeżanie co godzinę daje ~11 h zapasu i wiele okazji na nadrobienie.
	 */
	private const RECURRENCE = 'hourly';

	/**
	 * Cron odświeża slot, gdy jego access wygasa w ciągu tylu sekund. 2 h > kadencja
	 * (1 h), więc nawet pojedynczy nieodpalony przebieg WP-Cron nie doprowadzi do
	 * wygaśnięcia access między przebiegami.
	 */
	public const CRON_THRESHOLD = 2 * HOUR_IN_SECONDS;

	/**
	 * Wpina hooki: samo zdarzenie crona oraz samonaprawialne zaplanowanie.
	 * Wołane z `bootstrap()` (na `plugins_loaded`, gdy twarde zależności są obecne).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		// Samonaprawa: zaplanuj przy pierwszym `init`, jeśli zdarzenia jeszcze nie ma
		// (obejmuje wtyczkę AKTYWNĄ zanim ten kod wszedł — hook aktywacji już nie odpali).
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Idempotentnie planuje zdarzenie crona, jeśli nie jest jeszcze zaplanowane.
	 * Statyczna — używana i przez `init` (samonaprawa), i (potencjalnie) przez
	 * aktywację wtyczki.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::RECURRENCE, self::CRON_HOOK );
		}
	}

	/**
	 * Usuwa zdarzenie crona. Wołane przy DEZAKTYWACJI wtyczki (żeby nie zostawić
	 * osieroconego harmonogramu). Nie wymaga twardych zależności.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Callback crona: przechodzi po slotach i odświeża te, którym access niedługo
	 * wygaśnie, a refresh jeszcze żyje. Wynik każdego slotu wystawiamy akcją
	 * `qutlet_allegro_token_refreshed` (obserwowalność bez twardego logowania).
	 *
	 * @return void
	 */
	public function run(): void {
		$store     = new TokenStore();
		$refresher = new TokenRefresher( $store );
		$now       = time();

		foreach ( self::slots() as $slot ) {
			list( $environment, $role ) = $slot;

			$tokens = $store->get( $environment, $role );

			if ( null === $tokens ) {
				continue; // Slot niepołączony — nie ma czego odświeżać.
			}

			if ( $tokens->is_refresh_expired( $now ) ) {
				continue; // Refresh wygasł — potrzebna ponowna autoryzacja (nie cron).
			}

			if ( ! $tokens->is_access_expired( $now, self::CRON_THRESHOLD ) ) {
				continue; // Access ma jeszcze duży zapas — nie ruszamy jednorazowego refresh.
			}

			// Przekazujemy TEN SAM próg (CRON_THRESHOLD) do refresh(), żeby jego
			// podwójne sprawdzenie po zamku używało tego samego okna co decyzja
			// wyżej. Bez tego refresh() użyłby domyślnego ACCESS_LEEWAY (5 min) i
			// oddałby token bez odświeżenia dla wszystkiego, co wygasa >5 min od
			// teraz — czyli zwężałby okno crona z 2 h do 5 min i niweczył bezpiecznik.
			$result = $refresher->refresh( $environment, $role, self::CRON_THRESHOLD );

			/**
			 * Wynik proaktywnego odświeżenia slotu przez cron (obserwowalność).
			 *
			 * @param string             $environment Środowisko slotu.
			 * @param string             $role        Rola slotu.
			 * @param TokenSet|\WP_Error $result      Nowa para albo błąd.
			 */
			do_action( 'qutlet_allegro_token_refreshed', $environment, $role, $result );
		}
	}

	/**
	 * Słownik slotów (środowisko × rola). Osie pochodzą z {@see Environment}
	 * (jedno źródło prawdy) — ta sama kolejność co w UI ({@see OAuthController}).
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function slots(): array {
		return array(
			array( Environment::PRODUCTION, Environment::ROLE_READ ),
			array( Environment::PRODUCTION, Environment::ROLE_WRITE ),
			array( Environment::SANDBOX, Environment::ROLE_READ ),
			array( Environment::SANDBOX, Environment::ROLE_WRITE ),
		);
	}
}
