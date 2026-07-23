<?php
/**
 * Slice ApiSamples — komenda WP-CLI pobierająca surowe zwrotki kategorii Allegro (P-3.2a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\ApiSamples;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Read-only komenda WP-CLI, która pobiera realne (produkcyjne) zwrotki endpointów
 * kategorii Allegro i zapisuje je jako SUROWY JSON verbatim do wskazanego katalogu.
 * Materiał wejściowy dla P-3.2b (qutlet-meta): tam zwrotki lądują jako pliki-próbki
 * w `docs/allegro-api-samples/`. Kategorie są danymi PUBLICZNYMI Allegro (brak PII
 * sprzedawcy), więc redakcja jest tu trywialna — ale reżim „raw poza repo" trzymamy
 * spójnie z P-3.1a: surowe wyjście idzie do `--out` poza repozytorium.
 *
 * Zakres (P-3.2a, `docs/plan.md`):
 * - `GET /sale/categories` — lista kategorii korzenia (top-level, `parent: null`);
 * - `GET /sale/categories?parent.id={id}` — dzieci wskazanej kategorii (TRAVERSAL
 *   drzewa; ten sam endpoint co lista, różni się parametrem `parent.id`);
 * - `GET /sale/categories/{categoryId}` — pojedyncza kategoria (osobny endpoint).
 *
 * Dobór kategorii (bez „magic numbers" w kodzie): parametr traversalu wybieramy
 * automatycznie — pierwsza kategoria korzenia, która NIE jest liściem (`leaf: false`,
 * więc ma dzieci). Pojedynczą kategorię detalujemy dla tej samej kategorii, do której
 * schodzimy — dzięki temu ten sam `id` widać w trzech kształtach (item listy, rodzic
 * dzieci, pełny detal). Oba wybory można nadpisać flagami `--parent-id`/`--category-id`.
 *
 * Bezpieczeństwo:
 * - Token slotu `production/read` bierzemy przez wspólne {@see AllegroCliSupport::access_token()}
 *   (on-demand rotacja P-2.3 pod spodem). Slot `read` nie ma prawa zapisu.
 * - Komenda robi WYŁĄCZNIE żądania GET — nie tworzy, nie edytuje, nie usuwa niczego;
 *   bezpiecznik D-2.G7 (zapis treści oferty tylko na sandboxie) spełniony trywialnie.
 * - Access token NIGDY nie trafia do wyjścia (plików ani stdout) — służy tylko jako
 *   nagłówek `Authorization` żądania.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (nie na froncie).
 */
final class CategorySamplesCommand {

	use AllegroCliSupport;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Pobiera surowe zwrotki kategorii i zapisuje je do katalogu `--out`.
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Katalog docelowy na surowe pliki JSON. Tworzony, jeśli nie istnieje. Powinien
	 *   leżeć POZA repozytorium (spójnie z P-3.1a — surowego wyjścia nie commitujemy).
	 *
	 * [--parent-id=<id>]
	 * : Id kategorii, której dzieci pobrać jako przykład traversalu. Domyślnie
	 *   pierwsza kategoria korzenia z `leaf: false`.
	 *
	 * [--category-id=<id>]
	 * : Id kategorii do pobrania przez `GET /sale/categories/{id}` (pojedyncza).
	 *   Domyślnie to samo id, co `--parent-id` (ten sam byt w trzech kształtach).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sample-categories --out=/tmp/p32-raw
	 *     wp qutlet-allegro sample-categories --out=/tmp/p32-raw --parent-id=7059 --category-id=85166
	 *
	 * @param array<int,string>    $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string> $assoc_args Flagi `--klucz=wartość`.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$out = (string) get_flag_value( $assoc_args, 'out', '' );

		if ( '' === $out ) {
			WP_CLI::error( 'Podaj katalog docelowy przez --out=<dir>.' );
		}

		$parent_id_flag   = (string) get_flag_value( $assoc_args, 'parent-id', '' );
		$category_id_flag = (string) get_flag_value( $assoc_args, 'category-id', '' );

		if ( ! wp_mkdir_p( $out ) ) {
			WP_CLI::error( sprintf( 'Nie mogę utworzyć/otworzyć katalogu docelowego: %s', $out ) );
		}

		$access = $this->access_token( Environment::PRODUCTION, Environment::ROLE_READ );
		$api    = Environment::for_environment( Environment::PRODUCTION )->api_base_url();

		// 1. Lista kategorii korzenia (top-level, parent: null).
		$root_url  = $api . '/sale/categories';
		$root_resp = $this->get( $root_url, $access );

		if ( 200 !== $root_resp['status'] || ! is_array( $root_resp['data'] ) ) {
			WP_CLI::error(
				sprintf(
					'GET /sale/categories zwróciło HTTP %d%s.',
					$root_resp['status'],
					'' !== $root_resp['error'] ? ' (' . $root_resp['error'] . ')' : ''
				)
			);
		}

		$this->write( $out . '/GET_sale-categories.raw.json', $root_resp['body'] );

		$root_categories = isset( $root_resp['data']['categories'] ) && is_array( $root_resp['data']['categories'] )
			? $root_resp['data']['categories']
			: array();

		if ( array() === $root_categories ) {
			WP_CLI::error( 'Lista kategorii korzenia jest pusta — brak materiału do traversalu.' );
		}

		// 2. Dobór kategorii do traversalu (parent.id) i do detalu pojedynczej kategorii.
		$parent_id   = '' !== $parent_id_flag ? $parent_id_flag : $this->first_non_leaf( $root_categories );
		$category_id = '' !== $category_id_flag ? $category_id_flag : $parent_id;

		WP_CLI::log(
			sprintf(
				'Korzeń: %d kategorii. Traversal parent.id=%s; pojedyncza kategoria id=%s.',
				count( $root_categories ),
				$parent_id,
				$category_id
			)
		);

		// 3. Traversal: dzieci wskazanej kategorii (ten sam endpoint, parametr parent.id).
		//    Allegro używa kropkowanego parametru `parent.id` — budujemy query ręcznie,
		//    żeby http_build_query nie przerobiło klucza (spójnie z OfferSamplesCommand).
		$children_url  = $api . '/sale/categories?parent.id=' . rawurlencode( $parent_id );
		$children_resp = $this->get( $children_url, $access );

		$children_file = 'GET_sale-categories_parent-' . $parent_id . '.raw.json';

		if ( 200 === $children_resp['status'] ) {
			$this->write( $out . '/' . $children_file, $children_resp['body'] );
		} else {
			WP_CLI::warning( sprintf( 'Traversal parent.id=%s → HTTP %d %s', $parent_id, $children_resp['status'], $this->error_detail( $children_resp ) ) );
		}

		// 4. Pojedyncza kategoria (osobny endpoint GET /sale/categories/{id}).
		$single_url  = $api . '/sale/categories/' . rawurlencode( $category_id );
		$single_resp = $this->get( $single_url, $access );

		$single_file = 'GET_sale-categories_id-' . $category_id . '.raw.json';

		if ( 200 === $single_resp['status'] ) {
			$this->write( $out . '/' . $single_file, $single_resp['body'] );
		} else {
			WP_CLI::warning( sprintf( 'Pojedyncza kategoria id=%s → HTTP %d %s', $category_id, $single_resp['status'], $this->error_detail( $single_resp ) ) );
		}

		WP_CLI::log(
			sprintf(
				'  root=%d children(parent %s)=%d single(id %s)=%d',
				$root_resp['status'],
				$parent_id,
				$children_resp['status'],
				$category_id,
				$single_resp['status']
			)
		);

		// 5. Manifest — kontekst doboru (BEZ tokenów).
		$manifest = array(
			'environment' => Environment::PRODUCTION,
			'api_base'    => $api,
			'root'        => array(
				'url'    => $root_url,
				'status' => $root_resp['status'],
				'count'  => count( $root_categories ),
			),
			'traversal'   => array(
				'url'       => $children_url,
				'parent_id' => $parent_id,
				'status'    => $children_resp['status'],
				'file'      => 200 === $children_resp['status'] ? $children_file : null,
			),
			'single'      => array(
				'url'         => $single_url,
				'category_id' => $category_id,
				'status'      => $single_resp['status'],
				'file'        => 200 === $single_resp['status'] ? $single_file : null,
			),
		);

		$this->write( $out . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		WP_CLI::success( sprintf( 'Zapisano surowe zwrotki kategorii do: %s', $out ) );
	}

	/**
	 * Zwraca `id` pierwszej kategorii korzenia, która NIE jest liściem (`leaf: false`),
	 * więc ma dzieci nadające się do przykładu traversalu. Gdy żadna nie jest oznaczona
	 * jako nie-liść (nieoczekiwane dla korzenia), spada do pierwszej kategorii z `id`.
	 *
	 * @param array<int,mixed> $categories Kategorie korzenia z `GET /sale/categories`.
	 * @return string Id kategorii do traversalu.
	 */
	private function first_non_leaf( array $categories ): string {
		$fallback = '';

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['id'] ) ) {
				continue;
			}

			$id = (string) $category['id'];

			if ( '' === $fallback ) {
				$fallback = $id;
			}

			if ( isset( $category['leaf'] ) && false === $category['leaf'] ) {
				return $id;
			}
		}

		return $fallback;
	}
}
