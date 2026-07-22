<?php
/**
 * Plugin Name:       Qutlet Allegro
 * Plugin URI:        https://github.com/przemekcichon/qutlet-allegro
 * Description:       Synchronizacja danych Qutlet ↔ Allegro: autoryzacja OAuth, import ofert do WooCommerce i utrzymanie synchronu. Zależny od Qutlet Core (model danych) i WooCommerce.
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Requires at least: 6.4
 * Author:            Qutlet
 * Text Domain:       qutlet-allegro
 * License:           proprietary
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro;

// Blokada bezpośredniego wywołania pliku poza WordPressem.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wersja wtyczki (jedno źródło prawdy — używać zamiast literału).
 */
const VERSION = '0.1.0';

/*
 * Autoloader Composera (D-G1): ładowany z guardem. Brak `vendor/autoload.php`
 * NIE jest fatal errorem — pokazujemy notice w adminie i przerywamy bootstrap,
 * żeby nie wywrócić całego WordPressa.
 */
$qutlet_allegro_autoload = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $qutlet_allegro_autoload ) ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );

	return;
}

require_once $qutlet_allegro_autoload;

// Slice'y synchronizacji uruchamiamy dopiero, gdy twarde zależności są obecne (D-G5).
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/*
 * Dezaktywacja: usuń zdarzenie WP-Cron odświeżania tokenów (P-2.3), żeby nie
 * zostawić osieroconego harmonogramu. Rejestrujemy hook bezwarunkowo (po
 * załadowaniu autoloadera) — sprzątanie musi działać niezależnie od obecności
 * twardych zależności. Zaplanowanie zdarzenia jest samonaprawialne (na `init`,
 * patrz Auth\RefreshScheduler), więc hooka aktywacji nie potrzebujemy.
 */
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\Auth\\RefreshScheduler::unschedule' );

/**
 * Punkt wejścia wtyczki. Uruchamiany na `plugins_loaded`.
 *
 * Najpierw weryfikujemy OBECNOŚĆ twardych zależności (D-G5) i przy braku robimy
 * no-op + notice. Gdy są obecne — rejestrujemy slice'y wtyczki. Aktualnie: slice
 * `Auth/` — flow OAuth (P-2.2) oraz odświeżanie/rotacja tokenów (P-2.3) — oraz slice
 * `ApiSamples/` (P-3.1a) — komenda WP-CLI pobierająca surowe zwrotki ofert (tylko
 * pod `WP_CLI`; D-0.3.1 zakazywał rejestracji jedynie w bootstrapie FAZY 0).
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! dependencies_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependencies_notice' );

		return; // No-op: bez twardych zależności allegro niczego nie rejestruje.
	}

	/*
	 * Slice Auth (P-2.2): flow „Połącz z Allegro" + callback OAuth. Rejestruje
	 * własne hooki (admin_menu, rest_api_init, admin_post_*) — wszystkie PÓŹNIEJSZE
	 * niż `plugins_loaded`. Auth nie czyta pól/serwisów core przy init, więc
	 * kolejność względem core (UWAGA o kolejności, D-G5, niżej) go nie dotyczy:
	 * wystarczy OBECNOŚĆ core zweryfikowana w `dependencies_met()`.
	 *
	 * UWAGA o kolejności (D-G5): WP ładuje wtyczki alfabetycznie, więc
	 * `qutlet-allegro` startuje PRZED `qutlet-core`. Sprawdzenie OBECNOŚCI core w
	 * `dependencies_met()` jest bezpieczne (stała `Qutlet\Core\VERSION` powstaje
	 * przy ładowaniu pliku core, zanim odpali jakikolwiek `plugins_loaded`), ale
	 * KOLEJNOŚCI callbacków nie gwarantuje. Przyszły slice, który przy init CZYTA
	 * pola/serwisy zarejestrowane przez core (np. OfferSync/), musi wpiąć się na
	 * PÓŹNIEJSZYM priorytecie niż core (core hakuje `plugins_loaded` z domyślnym
	 * 10, więc allegro np. priorytet > 10) lub na dedykowanym hooku „core gotowe".
	 */
	( new Auth\OAuthController() )->register();

	/*
	 * Slice Auth (P-2.3): odświeżanie/rotacja tokenów. Rejestruje zdarzenie WP-Cron
	 * (`qutlet_allegro_refresh_tokens`) + samonaprawialne zaplanowanie na `init`.
	 * Cron jest zabezpieczeniem; podstawą jest odświeżanie on-demand
	 * (`Auth\TokenRefresher::get_valid()`), którego użyją konsumenci FAZY 3/6.
	 * Świadomie WP-Cron, nie systemowy — rozgraniczenie względem D-6.G1 opisane w
	 * `Auth\RefreshScheduler`.
	 */
	( new Auth\RefreshScheduler() )->register();

	/*
	 * Slice ApiSamples (P-3.1a/P-3.2a/P-3.3a): komendy WP-CLI pobierające surowe zwrotki
	 * Allegro do plików (materiał wejściowy dla zredagowanych próbek w meta:
	 * P-3.1b oferty, P-3.2b kategorie, P-3.3b zamówienia). Rejestrowane WYŁĄCZNIE
	 * w kontekście WP-CLI — na froncie/adminie nieobecne.
	 */
	/*
	 * Slice SandboxSeed (P-3A.1a): komenda WP-CLI robiąca trwały snapshot ofert
	 * produkcyjnych — materiał dla zasiewu sandboxa (P-3A.2). Osobny slice od
	 * `ApiSamples/`, bo to inny produkt i inny reżim danych: tamten zbiera materiał
	 * na ZREDAGOWANE próbki do repo, ten kompletne SUROWE dane, które do repo nie
	 * trafiają nigdy (D-3A.G3). Również wyłącznie pod WP-CLI.
	 */
	if ( defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'qutlet-allegro sample-offers', ApiSamples\OfferSamplesCommand::class );
		\WP_CLI::add_command( 'qutlet-allegro sample-categories', ApiSamples\CategorySamplesCommand::class );
		\WP_CLI::add_command( 'qutlet-allegro sample-orders', ApiSamples\OrderSamplesCommand::class );
		\WP_CLI::add_command( 'qutlet-allegro snapshot-offers', SandboxSeed\OfferSnapshotCommand::class );
		\WP_CLI::add_command( 'qutlet-allegro sandbox-preflight', SandboxSeed\SandboxPreflightCommand::class );
	}
}

/**
 * Sprawdza obecność twardych zależności allegro (D-G5): WooCommerce + Qutlet Core.
 *
 * Weryfikujemy OBECNOŚĆ na `plugins_loaded` (kolejność callbacków to osobna
 * sprawa — patrz TODO w `bootstrap()`). Literały wykrywania sprawdzone w realnym
 * kodzie: WooCommerce definiuje klasę `WooCommerce`; Qutlet Core definiuje stałą
 * `Qutlet\Core\VERSION` (w `qutlet-core.php`, na poziomie pliku). Oba testy to
 * literały-stringi — nie wymagają stubów.
 *
 * @return bool True, gdy oba wymagania są obecne.
 */
function dependencies_met(): bool {
	return class_exists( 'WooCommerce' ) && defined( 'Qutlet\\Core\\VERSION' );
}

/**
 * Notice w adminie: brak autoloadera Composera.
 *
 * @return void
 */
function render_missing_autoloader_notice(): void {
	$message = __(
		'Qutlet Allegro: brak autoloadera Composera (vendor/autoload.php). Uruchom „composer install" w katalogu wtyczki.',
		'qutlet-allegro'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Notice w adminie: brak twardych zależności (WooCommerce / Qutlet Core).
 *
 * @return void
 */
function render_missing_dependencies_notice(): void {
	$message = __(
		'Qutlet Allegro wymaga aktywnych wtyczek WooCommerce oraz Qutlet Core. Do czasu ich aktywacji wtyczka nie robi nic.',
		'qutlet-allegro'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
