<?php
/**
 * Slice Auth — niemutowalny zestaw tokenów OAuth Allegro (P-2.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Pojedynczy zestaw tokenów jednej pary (read albo write): access + refresh +
 * metadane i wygaśnięcia. Obiekt wartości — niemutowalny, bez zależności od WP,
 * łatwy do testów.
 *
 * Kształt odpowiedzi token endpointu (z manuala Allegro — VERBATIM):
 *   {
 *     "access_token":  "eyJ...",
 *     "token_type":    "bearer",
 *     "refresh_token": "eyJ...",
 *     "expires_in":    43199,                 // sekundy życia access tokenu (~12 h)
 *     "scope":         "allegro:api:...",
 *     "jti":           "UUID"                 // nieprzechowywane
 *   }
 *
 * Wygaśnięcia trzymamy jako ABSOLUTNE znaczniki uniksowe (nie względne
 * `expires_in`), żeby konsument (P-2.3) mógł je porównać z „teraz" bez pamiętania
 * chwili pobrania:
 * - `expires_at`         = chwila_pobrania + `expires_in` (twarde, z odpowiedzi).
 * - `refresh_expires_at` = chwila_pobrania + {@see self::REFRESH_TTL_SECONDS}.
 *   Allegro NIE zwraca czasu życia refresh tokenu w odpowiedzi — to wartość
 *   POMOCNICZA (manual: refresh ważny do 3 mies.), używana przez P-2.3 do decyzji
 *   „refresh sam się starzeje, trzeba ponownej autoryzacji". Nie jest to gwarancja
 *   z API, tylko górne oszacowanie.
 */
final class TokenSet {

	/**
	 * Pomocniczy czas życia refresh tokenu (3 miesiące ≈ 90 dni), w sekundach.
	 * Manual Allegro: refresh ważny „do 3 miesięcy". Wartość orientacyjna —
	 * patrz docblock klasy. Liczba jawna (90 × 86400), NIE `DAY_IN_SECONDS` —
	 * DTO ma być niezależny od WP (jak deklaruje docblock klasy) i testowalny
	 * w izolacji.
	 */
	public const REFRESH_TTL_SECONDS = 90 * 86400;

	/**
	 * @var string
	 */
	private $access_token;

	/**
	 * @var string
	 */
	private $refresh_token;

	/**
	 * @var string
	 */
	private $token_type;

	/**
	 * @var string
	 */
	private $scope;

	/**
	 * Absolutny znacznik uniksowy wygaśnięcia access tokenu.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Absolutny znacznik uniksowy (orientacyjny) wygaśnięcia refresh tokenu.
	 *
	 * @var int
	 */
	private $refresh_expires_at;

	/**
	 * @param string $access_token       Access token (bearer).
	 * @param string $refresh_token      Refresh token (jednorazowy przy rotacji).
	 * @param string $token_type         Typ tokenu (zwykle `bearer`).
	 * @param string $scope              Przyznane zakresy (spacjami rozdzielone).
	 * @param int    $expires_at         Absolutny ts wygaśnięcia access.
	 * @param int    $refresh_expires_at Absolutny ts (orient.) wygaśnięcia refresh.
	 */
	public function __construct(
		string $access_token,
		string $refresh_token,
		string $token_type,
		string $scope,
		int $expires_at,
		int $refresh_expires_at
	) {
		$this->access_token       = $access_token;
		$this->refresh_token      = $refresh_token;
		$this->token_type         = $token_type;
		$this->scope              = $scope;
		$this->expires_at         = $expires_at;
		$this->refresh_expires_at = $refresh_expires_at;
	}

	/**
	 * Buduje `TokenSet` ze zdekodowanej odpowiedzi token endpointu.
	 *
	 * @param array<string,mixed> $response Zdekodowane JSON-owe body odpowiedzi.
	 * @param int                 $now      Chwila pobrania (unix ts).
	 * @return self
	 */
	public static function from_token_response( array $response, int $now ): self {
		$expires_in = isset( $response['expires_in'] ) ? (int) $response['expires_in'] : 0;

		return new self(
			isset( $response['access_token'] ) ? (string) $response['access_token'] : '',
			isset( $response['refresh_token'] ) ? (string) $response['refresh_token'] : '',
			isset( $response['token_type'] ) ? (string) $response['token_type'] : 'bearer',
			isset( $response['scope'] ) ? (string) $response['scope'] : '',
			$now + $expires_in,
			$now + self::REFRESH_TTL_SECONDS
		);
	}

	/**
	 * Odtwarza `TokenSet` z tablicy zapisanej w magazynie ({@see self::to_array()}).
	 *
	 * @param array<string,mixed> $data Tablica z magazynu.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['access_token'] ) ? (string) $data['access_token'] : '',
			isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			isset( $data['token_type'] ) ? (string) $data['token_type'] : 'bearer',
			isset( $data['scope'] ) ? (string) $data['scope'] : '',
			isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
			isset( $data['refresh_expires_at'] ) ? (int) $data['refresh_expires_at'] : 0
		);
	}

	/**
	 * Serializuje do tablicy pod zapis w magazynie (przed szyfrowaniem).
	 *
	 * @return array{access_token:string,refresh_token:string,token_type:string,scope:string,expires_at:int,refresh_expires_at:int}
	 */
	public function to_array(): array {
		return array(
			'access_token'       => $this->access_token,
			'refresh_token'      => $this->refresh_token,
			'token_type'         => $this->token_type,
			'scope'              => $this->scope,
			'expires_at'         => $this->expires_at,
			'refresh_expires_at' => $this->refresh_expires_at,
		);
	}

	/**
	 * @return string
	 */
	public function access_token(): string {
		return $this->access_token;
	}

	/**
	 * @return string
	 */
	public function refresh_token(): string {
		return $this->refresh_token;
	}

	/**
	 * @return string
	 */
	public function token_type(): string {
		return $this->token_type;
	}

	/**
	 * @return string
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * @return int Absolutny ts wygaśnięcia access tokenu.
	 */
	public function expires_at(): int {
		return $this->expires_at;
	}

	/**
	 * @return int Absolutny ts (orient.) wygaśnięcia refresh tokenu.
	 */
	public function refresh_expires_at(): int {
		return $this->refresh_expires_at;
	}

	/**
	 * Czy access token jest już (lub zaraz będzie) przeterminowany.
	 *
	 * @param int $now    Chwila odniesienia (unix ts).
	 * @param int $leeway Margines w sekundach — traktuj jako wygasły na tyle
	 *                    wcześniej (P-2.3 odświeża z wyprzedzeniem). Domyślnie 0.
	 * @return bool
	 */
	public function is_access_expired( int $now, int $leeway = 0 ): bool {
		return $now >= ( $this->expires_at - $leeway );
	}
}
