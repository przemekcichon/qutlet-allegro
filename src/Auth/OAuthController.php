<?php
/**
 * Slice Auth — flow „Połącz z Allegro" (admin) + callback OAuth (P-2.2).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use WP_REST_Request;

/**
 * Kontroler przepływu Authorization Code dla wszystkich czterech slotów
 * (środowisko × rola). Spina UI, akcje admina i trasę REST callbacku w jeden slice:
 *
 * - **UI** — podstrona pod menu WooCommerce (capability `manage_woocommerce`),
 *   wiersz na slot: stan połączenia, przyznane scope'y, wygaśnięcia (render:
 *   {@see ConnectionsPage}).
 * - **Połącz** — akcja `admin-post` budująca URL `authorize` OSOBNO dla wskazanego
 *   slotu, ze `state` niosącym parę (środowisko, rola) i chroniącym przed CSRF
 *   ({@see OAuthState}), i przekierowująca do Allegro.
 * - **Callback** — JEDNA trasa REST `/wp-json/qutlet-allegro/v1/oauth/callback`
 *   dla wszystkich slotów (slot niesie `state`): konsumuje `state`, ustala
 *   uprawnienie NIEZALEŻNIE od warstwy REST (patrz niżej), wymienia `code` na token
 *   ({@see TokenClient}) i zapisuje do właściwego slotu ({@see TokenStore}).
 * - **Rozłącz** — akcja `admin-post` usuwająca tokeny slotu.
 *
 * Uprawnienie callbacku (uwaga implementacyjna z planu, VERBATIM D-2.G4 + P-2.2):
 * trasa REST + cookie-auth BEZ nonce `wp_rest` → `rest_cookie_check_errors()` zeruje
 * bieżącego użytkownika, więc `current_user_can()` zawsze zwróciłby false. Powrót z
 * Allegro to zwykła nawigacja przeglądarki i nonce'a nie doniesie (`redirect_uri`
 * musi pasować DOKŁADNIE). Dlatego callback NIE polega na permission_callback REST:
 * waliduje ciasteczko logowania (`wp_validate_auth_cookie`) i sprawdza, że powracający
 * użytkownik to ten sam, który rozpoczął flow (`state` → {@see OAuthState}).
 *
 * Ten kontroler NIE zapisuje treści ofert — bezpiecznik D-2.G7 go nie dotyczy
 * (i nie jest tu obchodzony).
 */
final class OAuthController {

	/**
	 * Slug podstrony (URL: `admin.php?page=...`).
	 */
	private const PAGE_SLUG = 'qutlet-allegro-oauth';

	/**
	 * Rodzic w menu — top-level WooCommerce.
	 */
	private const PARENT_SLUG = 'woocommerce';

	/**
	 * Capability wymagana do UI i akcji (decyzja użytkownika: `manage_woocommerce`).
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Namespace trasy REST callbacku (D-2.G4).
	 */
	private const REST_NAMESPACE = 'qutlet-allegro/v1';

	/**
	 * Ścieżka trasy REST callbacku (D-2.G4) — jedna dla wszystkich slotów.
	 */
	private const REST_ROUTE = '/oauth/callback';

	/**
	 * Nazwa akcji `admin-post` rozpoczynającej połączenie.
	 */
	private const CONNECT_ACTION = 'qutlet_allegro_oauth_connect';

	/**
	 * Nazwa akcji `admin-post` rozłączającej slot.
	 */
	private const DISCONNECT_ACTION = 'qutlet_allegro_oauth_disconnect';

	/**
	 * Query arg statusu operacji (dla komunikatu na podstronie).
	 */
	private const STATUS_ARG = 'qutlet_allegro_oauth';

	/**
	 * Query arg identyfikatora slotu (dla komunikatu na podstronie).
	 */
	private const SLOT_ARG = 'slot';

	/**
	 * Sloty w kolejności wyświetlania (środowisko × rola). Słownik obu osi pochodzi
	 * z {@see Environment} — jedno źródło prawdy.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private const SLOTS = array(
		array( Environment::PRODUCTION, Environment::ROLE_READ ),
		array( Environment::PRODUCTION, Environment::ROLE_WRITE ),
		array( Environment::SANDBOX, Environment::ROLE_READ ),
		array( Environment::SANDBOX, Environment::ROLE_WRITE ),
	);

	/**
	 * Wpina hooki slice'a. Rejestracje wiszą na hookach późniejszych niż
	 * `plugins_loaded` (admin_menu, rest_api_init, admin_post_*), więc kolejność
	 * względem core (D-G5) nie jest tu krytyczna — Auth nie czyta pól/serwisów core.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Rejestruje podstronę pod menu WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Połączenia Allegro', 'qutlet-allegro' ),
			__( 'Allegro OAuth', 'qutlet-allegro' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Rejestruje trasę REST callbacku (jedna dla wszystkich slotów).
	 *
	 * @return void
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_callback' ),
				/*
				 * permission_callback = __return_true CELOWO. Powrót z Allegro to
				 * nawigacja przeglądarki bez nonce `wp_rest`, więc
				 * `rest_cookie_check_errors()` zeruje użytkownika i każdy
				 * `current_user_can()` w permission_callback zwróciłby false —
				 * flow nigdy by nie ruszył. Uprawnienie egzekwuje sam handler
				 * NIEZALEŻNIE od REST: walidacja ciasteczka logowania +
				 * jednorazowy `state` wiązany z inicjatorem (patrz docblock klasy
				 * i {@see self::handle_callback()}).
				 */
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Renderuje podstronę stanu połączeń.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'qutlet-allegro' ), '', array( 'response' => 403 ) );
		}

		ConnectionsPage::render( $this->view_rows(), $this->current_notice() );
	}

	/**
	 * Akcja „Połącz": buduje URL autoryzacji dla wskazanego slotu i przekierowuje
	 * do Allegro. Uprawnienie i CSRF sprawdzamy tu w kontekście admina (nie REST),
	 * więc `current_user_can` + `check_admin_referer` działają normalnie.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień do łączenia z Allegro.', 'qutlet-allegro' ), '', array( 'response' => 403 ) );
		}

		$environment_id = $this->request_value( $_GET, 'environment' );
		$role           = $this->request_value( $_GET, 'role' );

		if ( ! $this->is_valid_slot( $environment_id, $role ) ) {
			wp_die( esc_html__( 'Nieprawidłowy slot (środowisko/rola).', 'qutlet-allegro' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( $this->connect_nonce_action( $environment_id, $role ) );

		$environment = Environment::for_environment( $environment_id );

		if ( ! $environment->has_credentials( $role ) ) {
			$this->page_redirect( 'missing_credentials', $environment_id . '_' . $role );
		}

		$state = OAuthState::issue( $environment_id, $role, get_current_user_id() );

		wp_redirect( $this->build_authorize_url( $environment, $role, $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- cel zewnętrzny (Allegro), nie lokalny.
		exit;
	}

	/**
	 * Akcja „Rozłącz": usuwa tokeny wskazanego slotu.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień do rozłączania Allegro.', 'qutlet-allegro' ), '', array( 'response' => 403 ) );
		}

		$environment_id = $this->request_value( $_POST, 'environment' );
		$role           = $this->request_value( $_POST, 'role' );

		if ( ! $this->is_valid_slot( $environment_id, $role ) ) {
			wp_die( esc_html__( 'Nieprawidłowy slot (środowisko/rola).', 'qutlet-allegro' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( $this->disconnect_nonce_action( $environment_id, $role ) );

		( new TokenStore() )->delete( $environment_id, $role );

		$this->page_redirect( 'disconnected', $environment_id . '_' . $role );
	}

	/**
	 * Handler callbacku OAuth (trasa REST). Uprawnienie ustala niezależnie od
	 * warstwy REST (patrz docblock klasy). Kończy się przekierowaniem operatora na
	 * podstronę z komunikatem — nie zwraca odpowiedzi REST.
	 *
	 * @param WP_REST_Request $request Żądanie callbacku (parametry: `state`, `code`, `error`).
	 * @return void
	 */
	public function handle_callback( WP_REST_Request $request ): void {
		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );

		// 1. Jednorazowy `state` (CSRF + odzyskanie autorytatywnego slotu i inicjatora).
		$slot = '' !== $state ? OAuthState::consume( $state ) : null;

		if ( null === $slot ) {
			$this->page_redirect( 'invalid_state', null );
		}

		$slot_key = $slot['environment'] . '_' . $slot['role'];

		// 2. Uprawnienie NIEZALEŻNE od REST: ciasteczko logowania + zgodność z inicjatorem.
		$cookie_user = (int) wp_validate_auth_cookie( '', 'logged_in' );

		if ( $cookie_user <= 0
			|| $cookie_user !== $slot['user_id']
			|| ! user_can( $cookie_user, self::CAPABILITY ) ) {
			$this->page_redirect( 'forbidden', $slot_key );
		}

		// 3. Odmowa / błąd po stronie Allegro (brak `code`).
		if ( '' === $code ) {
			$this->page_redirect( 'denied', $slot_key );
		}

		// 4. Wymiana `code` → token przez klienta właściwego slotu.
		$environment = Environment::for_environment( $slot['environment'] );
		$client      = new TokenClient( $environment, $slot['role'] );
		$tokens      = $client->exchange_authorization_code( $code, $this->redirect_uri() );

		if ( is_wp_error( $tokens ) ) {
			$this->page_redirect( 'exchange_failed', $slot_key );
		}

		// 5. Zapis do właściwego slotu magazynu.
		$saved = ( new TokenStore() )->save( $slot['environment'], $slot['role'], $tokens );

		$this->page_redirect( $saved ? 'connected' : 'store_failed', $slot_key );
	}

	/**
	 * Buduje dane wierszy dla widoku (stan każdego slotu + URL-e akcji).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function view_rows(): array {
		$store = new TokenStore();
		$rows  = array();

		foreach ( self::SLOTS as $slot ) {
			list( $environment_id, $role ) = $slot;

			$environment = Environment::for_environment( $environment_id );
			$tokens      = $store->get( $environment_id, $role );

			$rows[] = array(
				'slot'               => $environment_id . '_' . $role,
				'label'              => $this->slot_label( $environment_id, $role ),
				'environment'        => $environment_id,
				'role'               => $role,
				'has_credentials'    => $environment->has_credentials( $role ),
				'connected'          => null !== $tokens,
				'scope'              => null !== $tokens ? $tokens->scope() : '',
				'expires_at'         => null !== $tokens ? $tokens->expires_at() : 0,
				'refresh_expires_at' => null !== $tokens ? $tokens->refresh_expires_at() : 0,
				'connect_url'        => $this->connect_url( $environment_id, $role ),
				'disconnect_action'  => self::DISCONNECT_ACTION,
				'disconnect_nonce'   => $this->disconnect_nonce_action( $environment_id, $role ),
			);
		}

		return $rows;
	}

	/**
	 * Buduje komunikat po akcji z query args (tylko do odczytu/wyświetlenia —
	 * status z białej listy, slot sanityzowany). Zwraca null, gdy brak statusu.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function current_notice(): ?array {
		$status = $this->request_value( $_GET, self::STATUS_ARG );

		if ( '' === $status ) {
			return null;
		}

		$messages = array(
			'connected'           => array( 'success', __( 'Slot połączony z Allegro.', 'qutlet-allegro' ) ),
			'disconnected'        => array( 'success', __( 'Slot rozłączony — tokeny usunięte.', 'qutlet-allegro' ) ),
			'invalid_state'       => array( 'error', __( 'Nieprawidłowy lub wygasły token stanu (ochrona CSRF). Spróbuj połączyć ponownie.', 'qutlet-allegro' ) ),
			'forbidden'           => array( 'error', __( 'Brak uprawnień lub sesja nie zgadza się z użytkownikiem, który rozpoczął autoryzację.', 'qutlet-allegro' ) ),
			'denied'              => array( 'error', __( 'Autoryzacja została odrzucona po stronie Allegro.', 'qutlet-allegro' ) ),
			'exchange_failed'     => array( 'error', __( 'Wymiana kodu autoryzacyjnego na token nie powiodła się.', 'qutlet-allegro' ) ),
			'store_failed'        => array( 'error', __( 'Nie udało się zapisać tokenów — sprawdź QUTLET_ALLEGRO_TOKEN_KEY oraz dostępność libsodium.', 'qutlet-allegro' ) ),
			'missing_credentials' => array( 'error', __( 'Brak sekretów aplikacji (client_id/client_secret) w wp-config.php dla tego slotu.', 'qutlet-allegro' ) ),
		);

		if ( ! isset( $messages[ $status ] ) ) {
			return null;
		}

		list( $type, $message ) = $messages[ $status ];

		$slot_key = $this->request_value( $_GET, self::SLOT_ARG );

		if ( '' !== $slot_key ) {
			$message .= sprintf( ' (%s)', $slot_key );
		}

		return array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Buduje URL akcji „Połącz" (link z nonce → `admin-post.php`).
	 *
	 * @param string $environment_id Środowisko.
	 * @param string $role           Rola.
	 * @return string
	 */
	private function connect_url( string $environment_id, string $role ): string {
		$url = add_query_arg(
			array(
				'action'      => self::CONNECT_ACTION,
				'environment' => $environment_id,
				'role'        => $role,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, $this->connect_nonce_action( $environment_id, $role ) );
	}

	/**
	 * Buduje pełny URL autoryzacji OAuth dla slotu (D-2.G2/G6).
	 *
	 * @param Environment $environment Środowisko (WSKAZANE, nie wykryte).
	 * @param string      $role        Rola (dobiera client_id i scope'y).
	 * @param string      $state       Jednorazowy `state`.
	 * @return string
	 */
	private function build_authorize_url( Environment $environment, string $role, string $state ): string {
		$params = array(
			'response_type' => 'code',
			'client_id'     => $environment->client_id( $role ),
			'redirect_uri'  => $this->redirect_uri(),
			'scope'         => Scopes::as_string( $role ),
			'state'         => $state,
		);

		return $environment->authorize_endpoint() . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Redirect URI callbacku — JEDNO źródło prawdy dla URL-a autoryzacji i wymiany
	 * kodu (muszą być identyczne). Musi też DOKŁADNIE odpowiadać adresowi
	 * zarejestrowanemu w aplikacji Allegro (D-2.G4).
	 *
	 * @return string
	 */
	private function redirect_uri(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Przekierowanie na podstronę z kodem statusu (i opcjonalnie slotem). Kończy
	 * żądanie (`exit`).
	 *
	 * @param string      $status   Kod statusu (biała lista w {@see self::current_notice()}).
	 * @param string|null $slot_key Identyfikator slotu `środowisko_rola` albo null.
	 * @return void
	 *
	 * @phpstan-return never
	 */
	private function page_redirect( string $status, ?string $slot_key ): void {
		$args = array(
			'page'            => self::PAGE_SLUG,
			self::STATUS_ARG  => $status,
		);

		if ( null !== $slot_key ) {
			$args[ self::SLOT_ARG ] = $slot_key;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Etykieta slotu do UI.
	 *
	 * @param string $environment_id Środowisko.
	 * @param string $role           Rola.
	 * @return string
	 */
	private function slot_label( string $environment_id, string $role ): string {
		$environment_label = Environment::PRODUCTION === $environment_id
			? __( 'Produkcja', 'qutlet-allegro' )
			: __( 'Sandbox', 'qutlet-allegro' );

		$role_label = Environment::ROLE_READ === $role
			? __( 'odczyt', 'qutlet-allegro' )
			: __( 'zapis', 'qutlet-allegro' );

		return sprintf( '%1$s · %2$s (%3$s)', $environment_label, $role_label, $role );
	}

	/**
	 * Nazwa akcji nonce dla „Połącz" danego slotu (wiąże nonce ze slotem).
	 *
	 * @param string $environment_id Środowisko.
	 * @param string $role           Rola.
	 * @return string
	 */
	private function connect_nonce_action( string $environment_id, string $role ): string {
		return self::CONNECT_ACTION . '_' . $environment_id . '_' . $role;
	}

	/**
	 * Nazwa akcji nonce dla „Rozłącz" danego slotu.
	 *
	 * @param string $environment_id Środowisko.
	 * @param string $role           Rola.
	 * @return string
	 */
	private function disconnect_nonce_action( string $environment_id, string $role ): string {
		return self::DISCONNECT_ACTION . '_' . $environment_id . '_' . $role;
	}

	/**
	 * Czy para (środowisko, rola) należy do dozwolonego słownika slotów.
	 *
	 * @param string $environment_id Środowisko do walidacji.
	 * @param string $role           Rola do walidacji.
	 * @return bool
	 */
	private function is_valid_slot( string $environment_id, string $role ): bool {
		$known_environment = Environment::SANDBOX === $environment_id || Environment::PRODUCTION === $environment_id;
		$known_role        = Environment::ROLE_READ === $role || Environment::ROLE_WRITE === $role;

		return $known_environment && $known_role;
	}

	/**
	 * Bezpieczny odczyt pojedynczej wartości z tablicy żądania: tylko string,
	 * odslashowany i sprowadzony do `sanitize_key` (nasze wartości to slugi:
	 * środowisko/rola/status/slot — wszystkie w zbiorze `[a-z0-9_-]`).
	 *
	 * @param array<string,mixed> $source Tablica źródłowa (`$_GET` / `$_POST`).
	 * @param string              $key    Klucz.
	 * @return string Pusty string, gdy brak / nie-string.
	 */
	private function request_value( array $source, string $key ): string {
		if ( ! isset( $source[ $key ] ) || ! \is_string( $source[ $key ] ) ) {
			return '';
		}

		return sanitize_key( wp_unslash( $source[ $key ] ) );
	}
}
