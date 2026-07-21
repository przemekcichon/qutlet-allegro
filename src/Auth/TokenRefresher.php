<?php
/**
 * Slice Auth — odświeżanie i rotacja tokenów OAuth Allegro (P-2.3).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

use WP_Error;

/**
 * Logika odświeżania access tokenu i rotacji jednorazowego refresh tokenu, OSOBNO
 * dla każdego slotu (środowisko × rola) — odświeżenie jednego slotu nigdy nie
 * dotyka pozostałych (osobny zamek, osobny wpis w magazynie). Zakres z planu P-2.3.
 *
 * Dwie drogi wejścia (obie prowadzą do tej samej rotacji):
 * - **on-demand** ({@see self::get_valid()}): konsument (FAZA 3/6) prosi o ważny
 *   access token dla slotu; gdy access wygasł lub wygasa w oknie `$leeway` —
 *   odśwież przed oddaniem.
 * - **cron zabezpieczający** ({@see RefreshScheduler}) woła {@see self::refresh()}
 *   proaktywnie, zanim access wygaśnie, żeby konsument nie trafił na martwy token.
 *
 * Rotacja jednorazowego refresh (manual Allegro — czytane nie z pamięci): użycie
 * refresh tokenu zwraca NOWĄ parę i unieważnia poprzedni refresh; poprzedni jest
 * ważny jeszcze ~60 s (okno tolerancji na powtórki/wyścigi). Stąd trzy zabezpieczenia:
 * 1. **Zamek slotu** ({@see RefreshLock}) — w obrębie tej instalacji odświeża naraz
 *    tylko jeden przebieg (główna ochrona przed podwójnym zużyciem refresh).
 * 2. **Podwójne sprawdzenie po zamku** — po zajęciu zamka czytamy magazyn PONOWNIE:
 *    inny przebieg mógł już odświeżyć, zanim dostaliśmy zamek → oddajemy jego
 *    świeży token bez kolejnego (marnującego) odświeżenia.
 * 3. **Tolerancja okna 60 s** — gdy odświeżenie mimo wszystko zwróci błąd (np.
 *    resztkowy wyścig międzyprocesowy, którego zamek nie objął), czytamy magazyn
 *    jeszcze raz: jeśli w międzyczasie pojawiła się świeża para (zapisał ją inny
 *    proces w oknie 60 s) — oddajemy ją zamiast błędu.
 *
 * Bez zależności od cronu ani UI — czysta logika, wołalna z każdej drogi.
 */
final class TokenRefresher {

	/**
	 * Margines proaktywnego odświeżenia access tokenu (sekundy): traktuj token jako
	 * „do odświeżenia", gdy wygaśnie w ciągu tylu sekund. 5 min daje zapas na
	 * dokończenie wywołania API tuż po odświeżeniu (access żyje 12 h, więc to
	 * znikomy ułamek). Konsument może podać własny `$leeway` w {@see self::get_valid()}.
	 */
	public const ACCESS_LEEWAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Magazyn tokenów (wstrzykiwalny — ułatwia testy i reużycie instancji).
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * @param TokenStore|null $store Magazyn tokenów (domyślnie nowy {@see TokenStore}).
	 */
	public function __construct( ?TokenStore $store = null ) {
		$this->store = $store instanceof TokenStore ? $store : new TokenStore();
	}

	/**
	 * On-demand: zwraca ważny zestaw tokenów slotu, odświeżając w razie potrzeby.
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param int    $leeway      Margines wyprzedzenia w sekundach (domyślnie {@see self::ACCESS_LEEWAY}).
	 * @return TokenSet|WP_Error Ważny `TokenSet` albo `WP_Error` (brak połączenia /
	 *                           refresh wygasł / błąd odświeżenia).
	 */
	public function get_valid( string $environment, string $role, int $leeway = self::ACCESS_LEEWAY ) {
		$tokens = $this->store->get( $environment, $role );

		if ( null === $tokens ) {
			return $this->not_connected_error( $environment, $role );
		}

		if ( ! $tokens->is_access_expired( time(), $leeway ) ) {
			return $tokens; // Access wciąż ważny z zapasem — bez sieci.
		}

		return $this->refresh( $environment, $role, $leeway );
	}

	/**
	 * Odświeża (rotuje) parę tokenów slotu pod zamkiem. Wynik to NOWA para zapisana
	 * w magazynie (nadpisuje poprzednią, w tym zużyty refresh). Bezpieczne do
	 * wołania równolegle — patrz docblock klasy (zamek + podwójne sprawdzenie +
	 * tolerancja okna 60 s).
	 *
	 * @param string $environment Jedna ze stałych `Environment::SANDBOX` / `Environment::PRODUCTION`.
	 * @param string $role        Jedna ze stałych `Environment::ROLE_READ` / `Environment::ROLE_WRITE`.
	 * @param int    $leeway      Margines uznania access za „do odświeżenia" (sekundy).
	 * @return TokenSet|WP_Error Nowy `TokenSet` albo `WP_Error`.
	 */
	public function refresh( string $environment, string $role, int $leeway = self::ACCESS_LEEWAY ) {
		$lock = new RefreshLock();

		if ( ! $lock->acquire( $environment, $role ) ) {
			// Zamek trzyma inny przebieg — mógł już odświeżyć. Oddaj jego świeży
			// token, jeśli jest; inaczej zgłoś „zajęte" (wołający może ponowić).
			$concurrent = $this->store->get( $environment, $role );

			if ( null !== $concurrent && ! $concurrent->is_access_expired( time(), $leeway ) ) {
				return $concurrent;
			}

			return new WP_Error(
				'qutlet_allegro_refresh_locked',
				sprintf(
					/* translators: 1: environment, 2: role. */
					__( 'Odświeżanie tokenu Allegro dla slotu „%1$s/%2$s" jest w toku w innym przebiegu.', 'qutlet-allegro' ),
					$environment,
					$role
				)
			);
		}

		try {
			// Podwójne sprawdzenie: inny przebieg mógł odświeżyć, zanim dostaliśmy zamek.
			$current = $this->store->get( $environment, $role );

			if ( null === $current ) {
				return $this->not_connected_error( $environment, $role );
			}

			if ( ! $current->is_access_expired( time(), $leeway ) ) {
				return $current; // Już świeży — nie marnuj jednorazowego refresh.
			}

			if ( '' === $current->refresh_token() || $current->is_refresh_expired( time() ) ) {
				return new WP_Error(
					'qutlet_allegro_refresh_expired',
					sprintf(
						/* translators: 1: environment, 2: role. */
						__( 'Refresh token Allegro dla slotu „%1$s/%2$s" wygasł lub go brak — wymagana ponowna autoryzacja (Połącz z Allegro).', 'qutlet-allegro' ),
						$environment,
						$role
					)
				);
			}

			$client = new TokenClient( Environment::for_environment( $environment ), $role );
			$result = $client->refresh( $current->refresh_token() );

			if ( is_wp_error( $result ) ) {
				// Tolerancja okna 60 s: resztkowy wyścig międzyprocesowy — inny proces
				// mógł właśnie zapisać świeżą parę. Oddaj ją zamiast błędu, jeśli jest.
				$reread = $this->store->get( $environment, $role );

				if ( null !== $reread && ! $reread->is_access_expired( time(), $leeway ) ) {
					return $reread;
				}

				return $result;
			}

			// Rotacja: nadpisz slot NOWĄ parą (nowy jednorazowy refresh w środku).
			if ( ! $this->store->save( $environment, $role, $result ) ) {
				return new WP_Error(
					'qutlet_allegro_refresh_store_failed',
					sprintf(
						/* translators: 1: environment, 2: role. */
						__( 'Odświeżono token Allegro dla slotu „%1$s/%2$s", ale zapis do magazynu się nie powiódł — sprawdź QUTLET_ALLEGRO_TOKEN_KEY oraz libsodium.', 'qutlet-allegro' ),
						$environment,
						$role
					)
				);
			}

			return $result;
		} finally {
			$lock->release( $environment, $role );
		}
	}

	/**
	 * `WP_Error` „slot niepołączony" (brak zapisanych tokenów).
	 *
	 * @param string $environment Środowisko slotu.
	 * @param string $role        Rola slotu.
	 * @return WP_Error
	 */
	private function not_connected_error( string $environment, string $role ): WP_Error {
		return new WP_Error(
			'qutlet_allegro_not_connected',
			sprintf(
				/* translators: 1: environment, 2: role. */
				__( 'Slot Allegro „%1$s/%2$s" nie jest połączony — brak zapisanych tokenów.', 'qutlet-allegro' ),
				$environment,
				$role
			)
		);
	}
}
