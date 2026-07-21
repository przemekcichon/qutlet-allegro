<?php
/**
 * Slice Auth — klient HTTP do token endpointu Allegro (P-2.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use WP_Error;

/**
 * Klient token endpointu OAuth Allegro (D-2.G2 — klient poufny, Basic auth).
 *
 * Realizuje dwa grant-y (z manuala Allegro — czytane nie z pamięci):
 * - `authorization_code` — wymiana `code` (z callbacku P-2.2) na parę tokenów;
 * - `refresh_token`      — odświeżenie pary (rotacja, konsument: P-2.3).
 *
 * Żądanie (VERBATIM z manuala): `POST` na {@see Environment::token_endpoint()},
 * nagłówek `Authorization: Basic base64(clientId:clientSecret)`, ciało
 * `application/x-www-form-urlencoded`. Body:
 * - authorization_code: `grant_type=authorization_code`, `code`, `redirect_uri`;
 * - refresh_token:      `grant_type=refresh_token`, `refresh_token`.
 *
 * UWAGA (do potwierdzenia w P-2.3): manual (dwukrotnie odczytany) pokazuje ciało
 * refreshu WYŁĄCZNIE jako `grant_type` + `refresh_token` (bez `redirect_uri`).
 * Tak to tu realizujemy; dokładny zestaw parametrów refreshu potwierdzi P-2.3 na
 * żywym sandboxie (to jego zakres — realne odświeżanie).
 *
 * Rewizja P-2.1b: klient jest związany z JEDNYM slotem — instancją `Environment`
 * WSKAZANĄ przez wołającego (nie wykrytą globalnie, D-2.G2) plus rolą, bo sekrety
 * są per (środowisko, rola, D-2.G3). Nie zna zakresów; zwraca `TokenSet`, a o tym,
 * do którego slotu magazynu go zapisać, decyduje wołający (P-2.2/P-2.3).
 */
final class TokenClient {

	/**
	 * Timeout żądania do token endpointu (sekundy).
	 */
	private const REQUEST_TIMEOUT = 15;

	/**
	 * Konfiguracja środowiska (endpoint + sekrety per rola).
	 *
	 * @var Environment
	 */
	private $environment;

	/**
	 * Rola slotu (`Environment::ROLE_READ` / `Environment::ROLE_WRITE`) — wybiera
	 * właściwą parę sekretów aplikacji w obrębie środowiska.
	 *
	 * @var string
	 */
	private $role;

	/**
	 * @param Environment $environment Środowisko (sandbox/prod) — WSKAZANE przez wołającego.
	 * @param string      $role        Rola slotu (`Environment::ROLE_READ` / `Environment::ROLE_WRITE`).
	 */
	public function __construct( Environment $environment, string $role ) {
		$this->environment = $environment;
		$this->role        = $role;
	}

	/**
	 * Wymienia kod autoryzacyjny na parę tokenów (`grant_type=authorization_code`).
	 *
	 * @param string $code         Kod z callbacku OAuth (P-2.2).
	 * @param string $redirect_uri Redirect URI — MUSI dokładnie odpowiadać temu z
	 *                             żądania autoryzacji i rejestracji aplikacji.
	 * @return TokenSet|WP_Error `TokenSet` przy sukcesie, `WP_Error` przy błędzie.
	 */
	public function exchange_authorization_code( string $code, string $redirect_uri ) {
		return $this->request_token(
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $redirect_uri,
			)
		);
	}

	/**
	 * Odświeża parę tokenów (`grant_type=refresh_token`). Wynik to NOWA para —
	 * wołający nadpisuje nią magazyn (rotacja jednorazowego refresh).
	 *
	 * @param string $refresh_token Aktualny refresh token.
	 * @return TokenSet|WP_Error `TokenSet` przy sukcesie, `WP_Error` przy błędzie.
	 */
	public function refresh( string $refresh_token ) {
		return $this->request_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);
	}

	/**
	 * Wykonuje żądanie do token endpointu i mapuje odpowiedź na `TokenSet`.
	 *
	 * @param array<string,string> $body Parametry ciała (form-urlencoded).
	 * @return TokenSet|WP_Error
	 */
	private function request_token( array $body ) {
		if ( ! $this->environment->has_credentials( $this->role ) ) {
			return new WP_Error(
				'qutlet_allegro_missing_credentials',
				sprintf(
					/* translators: 1: environment identifier (sandbox/production), 2: role (read/write). */
					__( 'Brak sekretów Allegro (client_id/client_secret) w wp-config.php dla środowiska „%1$s" / roli „%2$s".', 'qutlet-allegro' ),
					$this->environment->type(),
					$this->role
				)
			);
		}

		$response = wp_remote_post(
			$this->environment->token_endpoint(),
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode(
						$this->environment->client_id( $this->role ) . ':' . $this->environment->client_secret( $this->role )
					),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( 200 !== $status ) {
			return $this->error_from_response( $status, \is_array( $data ) ? $data : array() );
		}

		if ( ! \is_array( $data ) || ! isset( $data['access_token'] ) || '' === (string) $data['access_token'] ) {
			return new WP_Error(
				'qutlet_allegro_token_malformed',
				__( 'Odpowiedź token endpointu Allegro nie zawiera access_token.', 'qutlet-allegro' ),
				array( 'status' => $status )
			);
		}

		return TokenSet::from_token_response( $data, time() );
	}

	/**
	 * Buduje `WP_Error` z odpowiedzi błędu OAuth (pola `error` / `error_description`).
	 *
	 * @param int                 $status HTTP status odpowiedzi.
	 * @param array<string,mixed> $data   Zdekodowane body (może być puste).
	 * @return WP_Error
	 */
	private function error_from_response( int $status, array $data ): WP_Error {
		$oauth_error = isset( $data['error'] ) ? (string) $data['error'] : 'unknown';
		$description = isset( $data['error_description'] ) ? (string) $data['error_description'] : '';

		return new WP_Error(
			'qutlet_allegro_token_http_error',
			sprintf(
				/* translators: 1: HTTP status, 2: OAuth error code, 3: error description. */
				__( 'Token endpoint Allegro zwrócił błąd (HTTP %1$d, %2$s): %3$s', 'qutlet-allegro' ),
				$status,
				$oauth_error,
				$description
			),
			array(
				'status'      => $status,
				'oauth_error' => $oauth_error,
			)
		);
	}
}
