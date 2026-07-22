<?php
/**
 * Slice ApiSamples — komenda WP-CLI pobierająca surowe zwrotki ofert Allegro (P-3.1a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\ApiSamples;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Auth\TokenRefresher;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Read-only komenda WP-CLI, która pobiera realne (produkcyjne) zwrotki endpointów
 * ofert Allegro i zapisuje je jako SUROWY JSON verbatim do wskazanego katalogu.
 * Materiał wejściowy dla P-3.1b (qutlet-meta): tam zwrotki są redagowane (D-3.G1)
 * i lądują jako pliki-próbki w `docs/allegro-api-samples/`. Tu — bez redakcji.
 *
 * Zakres (P-3.1a, `docs/plan.md`):
 * - `GET /sale/offers?limit=100` (jedna strona) — lista ofert sprzedawcy;
 * - auto-dobór ofert z KILKU rozłącznych kategorii (D-3.G3 — o wartości próbki
 *   decyduje rozpiętość kategorii, nie liczba ofert);
 * - dla każdej wybranej oferty: `GET /sale/product-offers/{offerId}` (pełne) oraz
 *   `GET /sale/product-offers/{offerId}/parts?include=stock&include=price` (partial,
 *   D-3.1.2 — realny lżejszy endpoint `getPartialProductOffer`, nie tryb tego samego
 *   wywołania).
 *
 * Bezpieczeństwo:
 * - Token bierzemy ze slotu `production/read` przez {@see TokenRefresher::get_valid()}
 *   (on-demand rotacja P-2.3). Slot `read` nie ma prawa zapisu.
 * - Komenda robi WYŁĄCZNIE żądania GET — nie tworzy, nie edytuje, nie usuwa ofert;
 *   bezpiecznik D-2.G7 (zapis treści oferty tylko na sandboxie) jest spełniony
 *   trywialnie, bo nie ma tu żadnej operacji zapisu do Allegro.
 * - Access token NIGDY nie trafia do wyjścia (plików ani stdout) — służy tylko jako
 *   nagłówek `Authorization` żądania.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (nie na froncie).
 */
final class OfferSamplesCommand {

	/**
	 * Nagłówek `Accept` wymagany przez Allegro REST API (wersjonowany media type).
	 */
	private const ACCEPT = 'application/vnd.allegro.public.v1+json';

	/**
	 * Domyślny limit ofert na stronę listy (maksimum akceptowane przez Allegro).
	 */
	private const DEFAULT_LIMIT = 100;

	/**
	 * Domyślny górny limit rozłącznych kategorii do próbkowania (D-3.G3).
	 */
	private const DEFAULT_MAX_CATEGORIES = 6;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Pobiera surowe zwrotki ofert i zapisuje je do katalogu `--out`.
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Katalog docelowy na surowe pliki JSON. Tworzony, jeśli nie istnieje. Powinien
	 *   leżeć POZA repozytorium — surowe dane są niezredagowane.
	 *
	 * [--max-categories=<n>]
	 * : Ile rozłącznych kategorii najwyżej próbkować (jedna oferta na kategorię).
	 * ---
	 * default: 6
	 * ---
	 *
	 * [--limit=<n>]
	 * : Rozmiar strony listy `GET /sale/offers` (1–100).
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sample-offers --out=/tmp/p31-raw --max-categories=6
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

		$max_categories = max( 1, (int) get_flag_value( $assoc_args, 'max-categories', (string) self::DEFAULT_MAX_CATEGORIES ) );
		$limit          = min( self::DEFAULT_LIMIT, max( 1, (int) get_flag_value( $assoc_args, 'limit', (string) self::DEFAULT_LIMIT ) ) );

		if ( ! wp_mkdir_p( $out ) ) {
			WP_CLI::error( sprintf( 'Nie mogę utworzyć/otworzyć katalogu docelowego: %s', $out ) );
		}

		$access = $this->access_token();
		$api    = Environment::for_environment( Environment::PRODUCTION )->api_base_url();

		// 1. Lista ofert (jedna strona).
		$list_url  = $api . '/sale/offers?' . http_build_query(
			array(
				'limit'  => $limit,
				'offset' => 0,
			)
		);
		$list_resp = $this->fetch( $list_url, $access );

		if ( 200 !== $list_resp['status'] || ! is_array( $list_resp['data'] ) ) {
			WP_CLI::error(
				sprintf(
					'GET /sale/offers zwróciło HTTP %d%s.',
					$list_resp['status'],
					'' !== $list_resp['error'] ? ' (' . $list_resp['error'] . ')' : ''
				)
			);
		}

		$this->write( $out . '/GET_sale-offers.raw.json', $list_resp['body'] );

		$offers = isset( $list_resp['data']['offers'] ) && is_array( $list_resp['data']['offers'] )
			? $list_resp['data']['offers']
			: array();

		if ( array() === $offers ) {
			WP_CLI::error( 'Lista ofert jest pusta — brak materiału do próbkowania (slot production/read pusty?).' );
		}

		// 2. Dobór ofert z rozłącznych kategorii (jedna oferta na kategorię, D-3.G3).
		$selected = $this->select_diverse( $offers, $max_categories );

		WP_CLI::log(
			sprintf(
				'Lista: %d ofert na stronie (totalCount=%s). Wybrano %d kategorii.',
				count( $offers ),
				isset( $list_resp['data']['totalCount'] ) ? (string) $list_resp['data']['totalCount'] : '?',
				count( $selected )
			)
		);

		// 3. Dla każdej wybranej oferty: pełne + partial.
		$product_offers = array();

		foreach ( $selected as $category_id => $offer_id ) {
			$full_url  = $api . '/sale/product-offers/' . rawurlencode( $offer_id );
			$parts_url = $api . '/sale/product-offers/' . rawurlencode( $offer_id ) . '/parts?' . http_build_query(
				array( 'include' => array( 'stock', 'price' ) ),
				'',
				'&'
			);

			$full  = $this->fetch( $full_url, $access );
			$parts = $this->fetch( $parts_url, $access );

			$full_file  = 'product-offer_' . $offer_id . '.full.raw.json';
			$parts_file = 'product-offer_' . $offer_id . '.parts.raw.json';

			if ( 200 === $full['status'] ) {
				$this->write( $out . '/' . $full_file, $full['body'] );
			} else {
				WP_CLI::warning( sprintf( 'Oferta %s: pełne GET → HTTP %d %s', $offer_id, $full['status'], $full['error'] ) );
			}

			if ( 200 === $parts['status'] ) {
				$this->write( $out . '/' . $parts_file, $parts['body'] );
			} else {
				WP_CLI::warning( sprintf( 'Oferta %s: /parts → HTTP %d %s', $offer_id, $parts['status'], $parts['error'] ) );
			}

			$product_offers[ $offer_id ] = array(
				'category_id'  => (string) $category_id,
				'full_status'  => $full['status'],
				'parts_status' => $parts['status'],
				'full_file'    => 200 === $full['status'] ? $full_file : null,
				'parts_file'   => 200 === $parts['status'] ? $parts_file : null,
			);

			WP_CLI::log( sprintf( '  offer %s (cat %s): full=%d parts=%d', $offer_id, (string) $category_id, $full['status'], $parts['status'] ) );
		}

		// 4. Manifest — kontekst doboru (BEZ tokenów, bez treści ofert).
		$manifest = array(
			'environment'    => Environment::PRODUCTION,
			'api_base'       => $api,
			'list'           => array(
				'url'         => $list_url,
				'status'      => $list_resp['status'],
				'page_count'  => count( $offers ),
				'total_count' => isset( $list_resp['data']['totalCount'] ) ? (int) $list_resp['data']['totalCount'] : null,
			),
			'max_categories' => $max_categories,
			'product_offers' => $product_offers,
		);

		$this->write( $out . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		WP_CLI::success( sprintf( 'Zapisano surowe zwrotki do: %s', $out ) );
	}

	/**
	 * Pobiera ważny access token slotu `production/read` (rotacja on-demand P-2.3).
	 * Kończy komendę błędem, gdy slot niepołączony / refresh wygasł / błąd sieci.
	 *
	 * @return string Access token (nigdy nie trafia do wyjścia poza nagłówkiem żądania).
	 */
	private function access_token(): string {
		$tokens = ( new TokenRefresher() )->get_valid( Environment::PRODUCTION, Environment::ROLE_READ );

		if ( is_wp_error( $tokens ) ) {
			WP_CLI::error( sprintf( 'Brak ważnego tokenu production/read: %s', $tokens->get_error_message() ) );
		}

		return $tokens->access_token();
	}

	/**
	 * Wybiera po JEDNEJ ofercie na każdą napotkaną kategorię (kolejność jak w liście),
	 * do `$max` kategorii. Realizacja D-3.G3 (rozpiętość kategorii > liczba ofert).
	 *
	 * @param array<int,mixed> $offers Tablica ofert z listy `GET /sale/offers`.
	 * @param int              $max    Górny limit kategorii.
	 * @return array<string,string> Mapa `category_id => offer_id` (max `$max` wpisów).
	 */
	private function select_diverse( array $offers, int $max ): array {
		$by_category = array();

		foreach ( $offers as $offer ) {
			if ( ! is_array( $offer ) || ! isset( $offer['id'] ) ) {
				continue;
			}

			$category_id = isset( $offer['category']['id'] ) ? (string) $offer['category']['id'] : 'unknown';

			if ( isset( $by_category[ $category_id ] ) ) {
				continue; // Pierwsza oferta z tej kategorii wystarcza.
			}

			$by_category[ $category_id ] = (string) $offer['id'];

			if ( count( $by_category ) >= $max ) {
				break;
			}
		}

		return $by_category;
	}

	/**
	 * Wykonuje żądanie GET z tokenem bearer i wersjonowanym `Accept`.
	 *
	 * @param string $url    Pełny URL.
	 * @param string $access Access token (bearer).
	 * @return array{status:int,body:string,data:array<mixed>|null,error:string} Znormalizowany wynik.
	 */
	private function fetch( string $url, string $access ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access,
					'Accept'        => self::ACCEPT,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'data'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		return array(
			'status' => $status,
			'body'   => $body,
			'data'   => is_array( $decoded ) ? $decoded : null,
			'error'  => '',
		);
	}

	/**
	 * Zapisuje treść do pliku, kończąc komendę błędem przy niepowodzeniu.
	 *
	 * @param string $path     Ścieżka pliku.
	 * @param string $contents Treść (surowy JSON verbatim albo manifest).
	 * @return void
	 */
	private function write( string $path, string $contents ): void {
		if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- surowy zrzut poza WP uploads; WP_Filesystem to nadmiar dla jednorazowego narzędzia CLI.
			WP_CLI::error( sprintf( 'Nie mogę zapisać pliku: %s', $path ) );
		}
	}
}
