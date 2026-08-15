<?php
/**
 * Test-only dubler `$wpdb` (P-15.2) — potrzebny do charakteryzacji
 * `ProductWriter::known_offer_ids()` (bulk lookup „znane offer_id", D-15.3) bez
 * ładowania WordPressa. Wzorzec jak pozostałe pliki w `tests/Stubs/` (P-6.7b/
 * P-13.4a): wąski wycinek realnego `wpdb` — TYLKO właściwości/metody faktycznie
 * wołane przez kod pod testem (`$postmeta`, `$posts`, `prepare()`, `get_results()`).
 *
 * `prepare()` jest UPROSZCZONYM dublerem (nie 1:1 z `wp-db.php`): zamienia `%s`
 * po kolei na wartości cudzysłowione (`addslashes()`), wystarczające do
 * scharakteryzowania KSZTAŁTU zapytania budowanego przez `known_offer_ids()`
 * (liczba placeholderów dopasowana do liczby statusów, literał meta_key) — nie
 * do weryfikacji bezpieczeństwa realnego `$wpdb->prepare()` (odpowiedzialność
 * rdzenia WP, nie tego portu).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\Stubs;

/**
 * Rejestruje zapytania (`$last_query`) i zwraca wiersze skonfigurowane przez
 * test (`$rows`) — bez realnej bazy.
 */
final class FakeWpdb {

	/**
	 * @var string
	 */
	public $postmeta = 'wp_postmeta';

	/**
	 * @var string
	 */
	public $posts = 'wp_posts';

	/**
	 * Ostatnie zapytanie przekazane do {@see self::get_results()} — do asercji
	 * kształtu zapytania (meta_key, statusy) bez uruchamiania realnego SQL-a.
	 *
	 * @var string
	 */
	public $last_query = '';

	/**
	 * Wiersze zwracane przez {@see self::get_results()} — ustawiane przez test.
	 *
	 * @var array<int,object>
	 */
	public $rows = array();

	/**
	 * @param string $query Zapytanie z placeholderami (`%s`).
	 * @param mixed  ...$args Wartości podstawiane po kolei za `%s`.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$i = 0;

		return preg_replace_callback(
			'/%s/',
			function () use ( $args, &$i ) {
				return "'" . addslashes( (string) $args[ $i++ ] ) . "'";
			},
			$query
		);
	}

	/**
	 * @param string $query Zapytanie (już po `prepare()`).
	 * @return array<int,object>
	 */
	public function get_results( $query ) {
		$this->last_query = $query;

		return $this->rows;
	}
}
