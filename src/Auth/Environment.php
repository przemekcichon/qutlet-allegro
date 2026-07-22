<?php
/**
 * Slice Auth — konfiguracja środowiska Allegro dla WSKAZANEJ pary (P-2.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use InvalidArgumentException;
use RuntimeException;

/**
 * Konfiguracja środowiska OAuth Allegro: który świat (sandbox/produkcja), jego
 * bazowe adresy URL oraz sekrety aplikacji (client_id / client_secret) czytane
 * ze stałych `wp-config.php` per (środowisko, rola).
 *
 * Rewizja P-2.1b (zrewidowane D-2.G1/G2/G3): środowisko przestaje być globalnie
 * WYKRYWANE i staje się PARAMETREM — obiekt tworzy się jawnie dla wskazanego
 * środowiska ({@see self::for_environment()}), a obie instancje (sandbox +
 * produkcja) mogą żyć równolegle w jednym żądaniu (lokalnie: odczyt produkcji +
 * zapis do sandboxa). Automatyczne `detect()` z P-2.1 USUNIĘTE — nic w kodzie
 * nie może już samo decydować, do którego Allegro idzie żądanie (D-2.G2).
 *
 * Decyzje wiążące (VERBATIM z `docs/plan.md`):
 * - D-2.G2 [ZREWIDOWANE]: klient poufny, Authorization Code, Basic auth na token
 *   endpoint. Środowisko jest parametrem połączenia, nie funkcją typu instalacji.
 * - D-2.G3 [ZREWIDOWANE]: osobna aplikacja Allegro per (środowisko, rola) — cztery
 *   komplety `client_id`/`client_secret` w `wp-config.php`, nigdy do repo. Schemat
 *   nazw stałych (symetryczny, wyprowadzalny programowo):
 *     `QUTLET_ALLEGRO_{ŚRODOWISKO}_{ROLA}_CLIENT_{ID|SECRET}`, np.
 *     `QUTLET_ALLEGRO_PRODUCTION_READ_CLIENT_ID`,
 *     `QUTLET_ALLEGRO_SANDBOX_WRITE_CLIENT_SECRET`.
 *   Nazwy z P-2.1 (`QUTLET_ALLEGRO_CLIENT_ID` itd.) WYCOFANE; migracja niepotrzebna
 *   (nigdy nie zdefiniowane w `wp-config.php`).
 * - D-2.G7 [USTALONE]: na produkcji tworzenie/publikacja/nadpisanie TREŚCI oferty
 *   jest ZABRONIONE (dozwolony tylko PATCH stanu magazynowego). Bezpiecznik
 *   egzekwowalny w kodzie — patrz {@see self::assert_offer_content_write_allowed()}.
 *
 * Bazy URL (z manuala Allegro — czytane nie z pamięci):
 * - produkcja: OAuth `https://allegro.pl`,                     API `https://api.allegro.pl`
 * - sandbox:   OAuth `https://allegro.pl.allegrosandbox.pl`,   API `https://api.allegro.pl.allegrosandbox.pl`
 * Ścieżki OAuth na obu bazach: `/auth/oauth/authorize`, `/auth/oauth/token`.
 * (API base wystawiamy już teraz — konsumują je FAZA 3/6; tu nieużywane poza
 * ekspozycją konfiguracji środowiska.)
 *
 * Ta klasa jest też JEDYNYM źródłem słownika slotu (środowisko × rola): stałe
 * środowisk (`SANDBOX`/`PRODUCTION`) oraz ról (`ROLE_READ`/`ROLE_WRITE`) żyją tu,
 * a magazyn tokenów ({@see TokenStore}) referuje do nich — naturalny kierunek
 * zależności storage → config i jedno miejsce prawdy dla obu osi slotu.
 *
 * Obiekt jest niemutowalny — twórz przez {@see self::for_environment()}.
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
	 * Rola: para tokenów tylko-odczyt (słownik slotu — patrz docblock klasy).
	 */
	public const ROLE_READ = 'read';

	/**
	 * Rola: para tokenów z prawem zapisu (słownik slotu — patrz docblock klasy).
	 */
	public const ROLE_WRITE = 'write';

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
	 * Bazy DEDYKOWANEGO hosta uploadu zdjęć per środowisko (bez ścieżki).
	 *
	 * Allegro wymaga osobnego hosta do wysyłki obrazów (`POST /sale/images`) — zwykła baza API
	 * tej operacji nie obsługuje. Zdjęcia trzeba PRZENIEŚĆ na serwery Allegro danego środowiska:
	 * oferta z obcym adresem obrazka jest odrzucana (422 `OfferImagesNotFoundException`).
	 *
	 * @var array<string,string>
	 */
	private const UPLOAD_BASE = array(
		self::PRODUCTION => 'https://upload.allegro.pl',
		self::SANDBOX    => 'https://upload.allegro.pl.allegrosandbox.pl',
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
	 * Tworzy konfigurację dla WSKAZANEGO środowiska (D-2.G2 — środowisko jest
	 * parametrem, nie jest wykrywane). Obie instancje mogą współistnieć.
	 *
	 * @param string $environment Jedna ze stałych `self::SANDBOX` / `self::PRODUCTION`.
	 * @return self
	 *
	 * @throws InvalidArgumentException Gdy `$environment` spoza dozwolonego zbioru
	 *                                  (błąd programisty, nie stan runtime).
	 */
	public static function for_environment( string $environment ): self {
		if ( self::SANDBOX !== $environment && self::PRODUCTION !== $environment ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznane środowisko Allegro: "%s".', $environment )
			);
		}

		return new self( $environment );
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
	 * @return string Baza dedykowanego hosta uploadu zdjęć (`POST /sale/images`).
	 *                Konsument: zasiew sandboxa (FAZA 3A), który przenosi zdjęcia ofert.
	 */
	public function upload_base_url(): string {
		return self::UPLOAD_BASE[ $this->type ];
	}

	/**
	 * `client_id` aplikacji dla danej roli (D-2.G3). Pusty string, gdy stała
	 * nieustawiona w `wp-config.php`.
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return string
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru.
	 */
	public function client_id( string $role ): string {
		return $this->read_constant( $this->secret_constant_name( $role, 'ID' ) );
	}

	/**
	 * `client_secret` aplikacji dla danej roli (D-2.G3). Pusty string, gdy stała
	 * nieustawiona w `wp-config.php`.
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return string
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru.
	 */
	public function client_secret( string $role ): string {
		return $this->read_constant( $this->secret_constant_name( $role, 'SECRET' ) );
	}

	/**
	 * Czy oba sekrety pary (środowisko, rola) są obecne (niepuste) w `wp-config.php`.
	 *
	 * @param string $role Jedna ze stałych `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @return bool
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru.
	 */
	public function has_credentials( string $role ): bool {
		return '' !== $this->client_id( $role ) && '' !== $this->client_secret( $role );
	}

	/**
	 * Bezpiecznik D-2.G7 (predykat): czy w tym środowisku wolno tworzyć/publikować/
	 * nadpisywać TREŚĆ oferty. Dozwolone WYŁĄCZNIE na sandboxie; na produkcji
	 * jedyną operacją zapisu jest PATCH stanu magazynowego (który tu NIE podlega).
	 *
	 * @return bool True dla sandboxa; false dla produkcji.
	 */
	public function allows_offer_content_write(): bool {
		return self::PRODUCTION !== $this->type;
	}

	/**
	 * Bezpiecznik D-2.G7 (egzekwowalny punkt): KAŻDA operacja zapisu treści oferty
	 * (tworzenie/publikacja/nadpisanie — FAZA 3A zasiew, FAZA 6 sync treści) MUSI
	 * przejść przez tę bramkę PRZED wykonaniem. Odmawia (wyjątek) gdy celem jest
	 * produkcja — pomyłka środowiska bez tego bezpiecznika oznaczałaby publikację
	 * na żywym koncie sprzedawcy.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Gdy środowisko = produkcja.
	 */
	public function assert_offer_content_write_allowed(): void {
		if ( ! $this->allows_offer_content_write() ) {
			throw new RuntimeException(
				'Bezpiecznik D-2.G7: zapis treści oferty (tworzenie/publikacja/nadpisanie) '
				. 'jest zabroniony na środowisku produkcyjnym Allegro — dozwolony wyłącznie na sandboxie.'
			);
		}
	}

	/**
	 * Buduje nazwę stałej sekretu wg schematu D-2.G3
	 * `QUTLET_ALLEGRO_{ŚRODOWISKO}_{ROLA}_CLIENT_{ID|SECRET}`.
	 *
	 * @param string $role Rola (walidowana) — `self::ROLE_READ` / `self::ROLE_WRITE`.
	 * @param string $kind `ID` albo `SECRET` (segment wyłącznie z wywołań wewnętrznych).
	 * @return string Nazwa stałej.
	 *
	 * @throws InvalidArgumentException Gdy rola spoza dozwolonego zbioru.
	 */
	private function secret_constant_name( string $role, string $kind ): string {
		if ( self::ROLE_READ !== $role && self::ROLE_WRITE !== $role ) {
			throw new InvalidArgumentException(
				sprintf( 'Nieznana rola tokenu Allegro: "%s".', $role )
			);
		}

		return sprintf(
			'QUTLET_ALLEGRO_%s_%s_CLIENT_%s',
			strtoupper( $this->type ),
			strtoupper( $role ),
			$kind
		);
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
