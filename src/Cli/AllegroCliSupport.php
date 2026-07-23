<?php
/**
 * Wspólna powierzchnia HTTP/CLI dla komend WP-CLI qutlet-allegro (P-6.0).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Cli;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Auth\TokenRefresher;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Jedno miejsce na to, co do P-6.0 było SKOPIOWANE w sześciu komendach WP-CLI dwóch
 * slice'ów (`ApiSamples/`: sample-offers/-categories/-orders; `SandboxSeed/`:
 * snapshot-offers, sandbox-preflight, seed-sandbox): żądanie do Allegro (GET oraz
 * POST/PATCH z ciałem), pobranie access tokenu ze slotu, zwięzły opis błędu, zapis
 * pliku, bezpieczna nazwa pliku i odczyt flagi katalogu. Reguła trzech była dawno
 * przekroczona (do 6 kopii), a każda poprawka w obsłudze HTTP/tokenu wymagała tylu
 * samo identycznych edycji.
 *
 * ## Dlaczego trait, i dlaczego POZA oboma slice'ami
 * Powierzchnia obsługuje DWA slice'y, więc — zgodnie z planem P-6.0 — nie mieści się
 * w żadnym z nich; to jedyny dopuszczalny wyjątek od vertical slice w tym repo, stąd
 * osobna, jawnie międzyslice'owa lokalizacja `Cli/`. Trait, a nie klasa-współpracownik,
 * bo wszystkie konsumenty to komendy WP-CLI, a powierzchnia jest nierozłącznym splotem
 * transportu HTTP i glue CLI (`WP_CLI::error()`), którego i tak nikt spoza komendy nie
 * użyje. Slice `Auth/` celowo zostaje wolny od `WP_CLI`.
 *
 * ## Kontrakt dla klasy używającej
 * Klasa MUSI zdefiniować stałą `REQUEST_TIMEOUT` (int, sekundy) — {@see self::send()}
 * czyta ją przez `self::`, żeby zachować per-komendowy timeout (lżejsze GET-y = 30 s,
 * cięższy zasiew POST/PATCH = 45 s) bez przekazywania go w każdym wywołaniu.
 *
 * Zachowanie jest identyczne z kodem sprzed refaktoru — to czysty refaktor (P-6.0,
 * zero nowej funkcjonalności). Ujednolicenia świadome i wyłącznie na ścieżkach błędu
 * (nigdy nie trafiają do zapisywanych plików) są odnotowane przy metodach.
 */
trait AllegroCliSupport {

	/**
	 * Wersjonowany media type Allegro REST API — `Accept` (i `Content-Type` przy ciele).
	 */
	private static $allegro_media_type = 'application/vnd.allegro.public.v1+json';

	/**
	 * Wykonuje żądanie do Allegro: GET bez ciała albo POST/PATCH z ciałem JSON.
	 * Zbiera oba dawne warianty — `fetch()` (tylko GET) i `send()` (z ciałem) — w jedną
	 * metodę; GET to po prostu wywołanie z `$body === null`.
	 *
	 * @param string                   $method Metoda HTTP (`GET`/`POST`/`PATCH`).
	 * @param string                   $url    Pełny URL.
	 * @param string                   $access Access token (bearer).
	 * @param array<string,mixed>|null $body   Ciało żądania albo null (GET).
	 * @return array{status:int,body:string,data:array<mixed>|null,error:string} Znormalizowany wynik.
	 */
	private function send( string $method, string $url, string $access, ?array $body = null ): array {
		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access,
				'Accept'        => self::$allegro_media_type,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = self::$allegro_media_type;
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'data'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => $raw,
			'data'   => is_array( $decoded ) ? $decoded : null,
			'error'  => '',
		);
	}

	/**
	 * Żądanie GET z tokenem bearer i wersjonowanym `Accept`. Cienki alias na
	 * {@see self::send()} — zastępuje dawne prywatne `fetch()`.
	 *
	 * @param string $url    Pełny URL.
	 * @param string $access Access token (bearer).
	 * @return array{status:int,body:string,data:array<mixed>|null,error:string} Znormalizowany wynik.
	 */
	private function get( string $url, string $access ): array {
		return $this->send( 'GET', $url, $access, null );
	}

	/**
	 * Pobiera ważny access token slotu (środowisko × rola), kończąc komendę błędem,
	 * gdy sekretów brak, slot niepołączony, refresh wygasł albo odświeżenie padło.
	 *
	 * `$force` wymusza rotację zamiast oddania ważnego tokenu z magazynu — potrzebne,
	 * gdy zmienił się stan KONTA po stronie Allegro (token niesie kontekst konta z chwili
	 * autoryzacji, a access żyje 12 h). Bez `$force` oddajemy token z magazynu, dopóki ważny.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param bool   $force        Czy wymusić rotację tokenu.
	 * @return string Access token (nigdy nie trafia do wyjścia poza nagłówkiem żądania).
	 */
	private function access_token( string $environment, string $role, bool $force = false ): string {
		$config = Environment::for_environment( $environment );

		if ( ! $config->has_credentials( $role ) ) {
			WP_CLI::error(
				sprintf( 'Brak stałych client_id/client_secret pary %s/%s w wp-config.php.', $environment, $role )
			);
		}

		$refresher = new TokenRefresher();
		$tokens    = $force
			? $refresher->refresh( $environment, $role )
			: $refresher->get_valid( $environment, $role );

		if ( is_wp_error( $tokens ) ) {
			WP_CLI::error( sprintf( 'Brak ważnego tokenu %s/%s: %s', $environment, $role, $tokens->get_error_message() ) );
		}

		return $tokens->access_token();
	}

	/**
	 * Zwięzły opis błędu żądania do logu (błąd transportu WP albo urwane body 4xx/5xx).
	 * Nie trafia do plików wyjściowych — tylko na stdout/stderr komendy.
	 *
	 * @param array{status:int,body:string,data:array<mixed>|null,error:string} $resp Wynik {@see self::send()}.
	 * @param int                                                               $max  Ile znaków body zachować.
	 * @return string
	 */
	private function error_detail( array $resp, int $max = 300 ): string {
		if ( '' !== $resp['error'] ) {
			return $resp['error'];
		}

		return trim( substr( $resp['body'], 0, $max ) );
	}

	/**
	 * Odczytuje flagę katalogu, odrzucając przełącznik bez wartości: WP-CLI podaje wtedy
	 * `true`, a `(string) true` to `'1'` — powstałby katalog `1` względem cwd.
	 *
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @param string                    $name       Nazwa flagi.
	 * @return string Ścieżka bez końcowego separatora.
	 */
	private function require_dir_flag( array $assoc_args, string $name ): string {
		$value = get_flag_value( $assoc_args, $name, '' );

		if ( ! is_string( $value ) || '' === $value ) {
			WP_CLI::error( sprintf( 'Podaj katalog jako ścieżkę: --%s=<dir>.', $name ) );
		}

		return rtrim( $value, "/\\" );
	}

	/**
	 * Sprowadza identyfikator do bezpiecznego fragmentu nazwy pliku (`A-Za-z0-9._-`).
	 * Identyfikatory bywają podawane też ręcznie flagą, więc nie wklejamy ich do ścieżki
	 * bez filtra.
	 *
	 * @param string $id Identyfikator.
	 * @return string
	 */
	private function safe_name( string $id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $id );
	}

	/**
	 * Zapisuje treść do pliku, kończąc komendę błędem przy niepowodzeniu.
	 *
	 * @param string $path     Ścieżka pliku.
	 * @param string $contents Treść (surowy JSON verbatim albo manifest/raport).
	 * @return void
	 */
	private function write( string $path, string $contents ): void {
		if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- zrzut poza WP uploads; WP_Filesystem to nadmiar dla narzędzia CLI.
			WP_CLI::error( sprintf( 'Nie mogę zapisać pliku: %s', $path ) );
		}
	}
}
