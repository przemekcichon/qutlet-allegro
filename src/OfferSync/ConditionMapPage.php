<?php
/**
 * Slice OfferSync — strona informacyjna mapowania „Stan" → klasa (P-12.1c, read-only).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy;

/**
 * Strona pod menu WooCommerce, pokazująca BIEŻĄCĄ zawartość
 * {@see OfferMapper::condition_map()} (D-4.1.1) jako tabelkę „wartość Allegro
 * «Stan» → nasza klasa" — WYŁĄCZNIE do odczytu (D-12.1c.2). Bez formularza, bez
 * `register_setting()`/przetwarzania POST — wzorzec podglądu
 * {@see \Qutlet\Core\ProductInfo\RawLayerMetaBox} (nie kopiowany 1:1, bo tam
 * jest metabox na ekranie produktu, tu samodzielna strona admina). Realne
 * zmiany mapowania nadal wymagają edycji `OfferMapper::CONDITION_MAP` (deploya)
 * — ta strona daje tylko widoczność bieżącego stanu.
 *
 * Opis klasy (nazwa/opis chipsa) pochodzi z bytu core
 * {@see ClassDefinitionsTaxonomy::get()}, gdy term z danym `kod` istnieje;
 * inaczej wiersz degraduje się do gołego literału kodu (D-12.1c.1 — klasa
 * „Nowe" nie jest jeszcze wyseedowana w Localu, to zamierzona ścieżka, nie
 * błąd).
 */
final class ConditionMapPage {

	/**
	 * Slug strony (podmenu WooCommerce).
	 */
	private const PAGE_SLUG = 'qutlet-allegro-condition-map';

	/**
	 * Capability strony — ustawienie/podgląd sklepowy, nie systemowy (spójnie
	 * z `ConnectionsPage`/`PromptSettingsPage`).
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Wpina rejestrację menu. Wołane z bootstrapu `qutlet-allegro` (na
	 * `plugins_loaded`, po sprawdzeniu twardych zależności — D-G5).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
	}

	/**
	 * Rejestruje podmenu pod menu WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Qutlet — mapowanie stanu Allegro', 'qutlet-allegro' ),
			__( 'Qutlet — mapowanie stanu Allegro', 'qutlet-allegro' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Renderuje stronę: nota o mechanizmie (kod, nie admin) + tabela mapowania.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Qutlet — mapowanie stanu Allegro', 'qutlet-allegro' ) );

		printf(
			'<p>%s</p>',
			esc_html__(
				'Podgląd bieżącej auto-mapy: wartość parametru „Stan" z oferty Allegro → nasza klasa stanu. Tylko do odczytu — zmiana mapowania wymaga edycji kodu (OfferMapper::CONDITION_MAP) i deploya, nie da się jej zmienić z tego ekranu. Import ustawia klasę TYLKO gdy pole na produkcie jest puste — ręczna korekta sprzedawcy nigdy nie jest nadpisywana kolejnym przebiegiem.',
				'qutlet-allegro'
			)
		);

		self::render_table();

		echo '</div>';
	}

	/**
	 * Renderuje tabelę „wartość Allegro «Stan»" → klasa, w kolejności zdefiniowanej
	 * w `CONDITION_MAP` (kolejność wpisów w stałej PHP, deterministyczna).
	 *
	 * @return void
	 */
	private static function render_table(): void {
		$map = OfferMapper::condition_map();

		echo '<table class="widefat striped" style="max-width:760px;margin-top:1em;">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Wartość Allegro „Stan"', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Kod klasy', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Nazwa klasy', 'qutlet-allegro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $map as $allegro_value => $kod ) {
			self::render_row( (string) $allegro_value, $kod );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renderuje pojedynczy wiersz. Nazwa klasy z bytu core, gdy term istnieje;
	 * inaczej degradacja do goły kodu + nota (D-12.1c.1).
	 *
	 * @param string $allegro_value Wartość parametru „Stan" (VERBATIM).
	 * @param string $kod           Kod klasy z `CONDITION_MAP`.
	 * @return void
	 */
	private static function render_row( string $allegro_value, string $kod ): void {
		$definicja = ClassDefinitionsTaxonomy::get( $kod );
		$nazwa     = null !== $definicja
			? $definicja['nazwa']
			: sprintf(
				/* translators: %s: kod klasy (np. "A", "Nowe"). */
				__( '(brak definicji klasy „%s" — pokazany goły kod)', 'qutlet-allegro' ),
				$kod
			);

		printf(
			'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td></tr>',
			esc_html( $allegro_value ),
			esc_html( $kod ),
			esc_html( $nazwa )
		);
	}
}
