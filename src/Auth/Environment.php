<?php
/**
 * Slice Auth — wykrycie środowiska Allegro (sandbox/prod) i konfiguracja (P-2.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Konfiguracja środowiska OAuth Allegro: który świat (sandbox/produkcja), jego
 * bazowe adresy URL oraz sekrety aplikacji (client_id / client_secret) czytane
 * ze stałych `wp-config.php`.
 *
 * Decyzje wiążące (VERBATIM z `docs/plan.md`):
 * - D-2.G2: klient poufny, Authorization Code, Basic auth na token endpoint.
 *   Sandbox lokalnie / produkcja na produkcji — OSOBNE rejestracje i sekrety per
 *   środowisko. Wykrywamy środowisko natywnym `wp_get_environment_type()`:
 *   `production` → produkcja Allegro; wszystko inne (`local`/`development`/
 *   `staging`) → sandbox. Wybór zachowawczy: z maszyny nie-produkcyjnej NIGDY
 *   nie uderzamy w produkcyjne Allegro.
 * - D-2.G3: `client_id` / `client_secret` (per środowisko) w `wp-config.php`,
 *   nigdy do repo. Nazwy stałych z sufiksem środowiska (decyzja realizacyjna
 *   P-2.1, potwierdzona przez użytkownika):
 *     - produkcja: `QUTLET_ALLEGRO_CLIENT_ID`         / `QUTLET_ALLEGRO_CLIENT_SECRET`
 *     - sandbox:   `QUTLET_ALLEGRO_SANDBOX_CLIENT_ID` / `QUTLET_ALLEGRO_SANDBOX_CLIENT_SECRET`
 *
 * Bazy URL (z manuala Allegro — czytane nie z pamięci):
 * - produkcja: OAuth `https://allegro.pl`,                     API `https://api.allegro.pl`
 * - sandbox:   OAuth `https://allegro.pl.allegrosandbox.pl`,   API `https://api.allegro.pl.allegrosandbox.pl`
 * Ścieżki OAuth na obu bazach: `/auth/oauth/authorize`, `/auth/oauth/token`.
 * (API base wystawiamy już teraz — konsumują je FAZA 3/6; tu nieużywane poza
 * ekspozycją konfiguracji środowiska.)
 *
 * Obiekt jest niemutowalny — twórz przez `Environment::detect()`.
 */
final class Environment {

	/**
	 * Identyfikator środowiska: sandbox.
	 */
	public const SANDBOX = 'sandbox';

	/**
	 * Identyfikator środowiska: produkcja.
	 */
	public const PRODUCTION = 'production';

	/**
	 * Ścieżka endpointu autoryzacji (wspólna dla obu środowisk).
	 */
	private const AUTHORIZE_PATH = '/auth/oauth/authorize';

	/**
	 * Ścieżka token endpointu (wspólna dla obu środowisk).
	 */
	private const TOKEN_PATH = '/auth/oauth/token';

	/**
	 * Bazy OAuth per środowisko (bez ścieżki).
	 *
	 * @var array<string,string>
	 */
	private const OAUTH_BASE = array(
		self::PRODUCTION => 'https://allegro.pl',
		self::SANDBOX    => 'https://allegro.pl.allegrosandbox.pl',
	);

	/**
	 * Bazy REST API per środowisko (bez ścieżki).
	 *
	 * @var array<string,string>
	 */
	private const API_BASE = array(
		self::PRODUCTION => 'https://api.allegro.pl',
		self::SANDBOX    => 'https://api.allegro.pl.allegrosandbox.pl',
	);

	/**
	 * Nazwy stałych `wp-config.php` z sekretami per środowisko (D-2.G3).
	 *
	 * @var array<string,array{id:string,secret:string}>
	 */
	private const SECRET_CONSTANTS = array(
		self::PRODUCTION => array(
			'id'     => 'QUTLET_ALLEGRO_CLIENT_ID',
			'secret' => 'QUTLET_ALLEGRO_CLIENT_SECRET',
		),
		self::SANDBOX    => array(
			'id'     => 'QUTLET_ALLEGRO_SANDBOX_CLIENT_ID',
			'secret' => 'QUTLET_ALLEGRO_SANDBOX_CLIENT_SECRET',
		),
	);

	/**
	 * Rozpoznane środowisko (`self::SANDBOX` albo `self::PRODUCTION`).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * @param string $type Jedna ze stałych `self::SANDBOX` / `self::PRODUCTION`.
	 */
	private function __construct( string $type ) {
		$this->type = $type;
	}

	/**
	 * Wykrywa środowisko na podstawie `wp_get_environment_type()` (D-2.G2).
	 *
	 * Tylko `production` mapuje na produkcyjne Allegro; każdy inny typ WP
	 * (`local`, `development`, `staging`) → sandbox. Zachowawczo — nie ma tu
	 * ścieżki, którą maszyna nie-produkcyjna trafiłaby w produkcję Allegro.
	 *
	 * @return self
	 */
	public static function detect(): self {
		$wp_type = \function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		return new self( 'production' === $wp_type ? self::PRODUCTION : self::SANDBOX );
	}

	/**
	 * @return string Identyfikator środowiska (`self::SANDBOX`/`self::PRODUCTION`).
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * @return bool True, gdy działamy na sandboxie.
	 */
	public function is_sandbox(): bool {
		return self::SANDBOX === $this->type;
	}

	/**
	 * @return string Pełny URL endpointu autoryzacji (P-2.2 buduje z niego link).
	 */
	public function authorize_endpoint(): string {
		return self::OAUTH_BASE[ $this->type ] . self::AUTHORIZE_PATH;
	}

	/**
	 * @return string Pełny URL token endpointu (używa go TokenClient).
	 */
	public function token_endpoint(): string {
		return self::OAUTH_BASE[ $this->type ] . self::TOKEN_PATH;
	}

	/**
	 * @return string Baza REST API Allegro dla środowiska (konsument: FAZA 3/6).
	 */
	public function api_base_url(): string {
		return self::API_BASE[ $this->type ];
	}

	/**
	 * @return string `client_id` z `wp-config.php` albo '' gdy stała nieustawiona.
	 */
	public function client_id(): string {
		return $this->read_constant( self::SECRET_CONSTANTS[ $this->type ]['id'] );
	}

	/**
	 * @return string `client_secret` z `wp-config.php` albo '' gdy nieustawiona.
	 */
	public function client_secret(): string {
		return $this->read_constant( self::SECRET_CONSTANTS[ $this->type ]['secret'] );
	}

	/**
	 * Czy oba sekrety środowiska są obecne (niepuste) w `wp-config.php`.
	 *
	 * @return bool
	 */
	public function has_credentials(): bool {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * Odczyt stałej `wp-config.php` jako string (pusty string, gdy brak/nie-string).
	 *
	 * @param string $name Nazwa stałej.
	 * @return string
	 */
	private function read_constant( string $name ): string {
		if ( ! \defined( $name ) ) {
			return '';
		}

		$value = \constant( $name );

		return \is_string( $value ) ? $value : '';
	}
}
