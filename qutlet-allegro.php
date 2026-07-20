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

/**
 * Punkt wejścia wtyczki. Uruchamiany na `plugins_loaded`.
 *
 * FAZA 0 = czysty szkielet: brak slice'ów, brak rejestracji komend WP-CLI
 * (D-0.3.1 — szkielet WP-CLI dopiero w FAZIE 2). Weryfikujemy tu wyłącznie
 * OBECNOŚĆ twardych zależności i przy braku robimy no-op + notice.
 *
 * @return void
 */
function bootstrap(): void {
	if ( ! dependencies_met() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_dependencies_notice' );

		return; // No-op: bez twardych zależności allegro niczego nie rejestruje.
	}

	/*
	 * TODO (kolejne fazy): tu wpinamy inicjalizację slice'ów synchronizacji
	 * (Auth/, OfferSync/ …) ładowanych z przestrzeni Qutlet\Allegro.
	 *
	 * UWAGA o kolejności (D-G5): WP ładuje wtyczki alfabetycznie, więc
	 * `qutlet-allegro` startuje PRZED `qutlet-core`. Sprawdzenie OBECNOŚCI core
	 * poniżej jest bezpieczne (stała `Qutlet\Core\VERSION` powstaje przy
	 * ładowaniu pliku core, zanim odpali jakikolwiek `plugins_loaded`), ale
	 * KOLEJNOŚCI callbacków nie gwarantuje. Realny init slice'ów — które czytają
	 * pola/serwisy zarejestrowane przez core — musi wpiąć się na PÓŹNIEJSZYM
	 * priorytecie niż core (core hakuje `plugins_loaded` z domyślnym 10, więc
	 * allegro np. priorytet > 10) lub na dedykowanym hooku „core gotowe".
	 */
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
