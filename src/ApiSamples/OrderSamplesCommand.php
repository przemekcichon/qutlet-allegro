<?php
/**
 * Slice ApiSamples — komenda WP-CLI pobierająca surowe zwrotki zamówień Allegro (P-3.3a).
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
 * zamówień Allegro i zapisuje je jako SUROWY JSON verbatim do wskazanego katalogu.
 * Materiał wejściowy dla P-3.3b (qutlet-meta), gdzie zwrotki są REDAGOWANE (D-3.G1)
 * i dopiero wtedy lądują jako pliki-próbki w `docs/allegro-api-samples/`.
 *
 * UWAGA — DANE OSOBOWE: w odróżnieniu od ofert (P-3.1a) i publicznych kategorii
 * (P-3.2a) wyjście TEJ komendy zawiera REALNE PII kupujących (imię, nazwisko,
 * adres, e-mail, telefon, NIP, login). Katalog `--out` MUSI leżeć poza
 * repozytorium i nie wolno commitować jego zawartości w żadnej postaci. Redakcja
 * dzieje się dopiero w P-3.3b, przed wejściem pliku do repo.
 *
 * Zakres (P-3.3a, `docs/plan.md`):
 * - `GET /order/events` — strumień zdarzeń zamówień sprzedawcy (lista);
 * - `GET /order/checkout-forms/{checkoutFormId}` — pojedyncze zamówienie (pełne).
 * - `GET /order/checkout-forms` — lista zamówień, wyłącznie jako FALLBACK dostarczający
 *   `checkoutFormId`, gdy strumień zdarzeń nic nie zwróci (D-3.3.2). Nie jest celem
 *   próbkowania samym w sobie.
 *
 * Dobór zamówień (D-3.G3 — różnorodność zamiast ilości): ze zdarzeń bierzemy po
 * JEDNYM `checkoutFormId` na każdy napotkany TYP zdarzenia (różne typy = różne
 * momenty życia zamówienia), a dopiero potem dobijamy do `--max-orders` pozostałymi
 * różnymi id. Który z pobranych kształtów trafi ostatecznie do repo, rozstrzyga P-3.3b.
 *
 * Bezpieczeństwo:
 * - Token slotu `production/read` bierzemy przez wspólne {@see AllegroCliSupport::access_token()}
 *   (on-demand rotacja P-2.3 pod spodem). Scope `allegro:api:orders:read` należy do roli `read`
 *   (D-2.G6), więc slot `read` wystarcza i nie ma prawa zapisu.
 * - Komenda robi WYŁĄCZNIE żądania GET — bezpiecznik D-2.G7 spełniony trywialnie.
 * - Access token NIGDY nie trafia do wyjścia (plików ani stdout) — służy tylko jako
 *   nagłówek `Authorization` żądania.
 * - Treść UDANEJ (HTTP 200) zwrotki nie trafia na stdout — tylko do pliku pod `--out`.
 *   Na stdout idą identyfikatory i statusy HTTP, a przy statusie ≠ 200 dodatkowo urwany
 *   początek body błędu (wspólne {@see AllegroCliSupport::error_detail()}). To JEDYNE
 *   miejsce, w którym fragment body odpowiedzi może pojawić się na stdout. Zmierzone na
 *   `GET /order/checkout-forms/{id}` z nieistniejącym id (P-3.3a): Allegro odpowiada
 *   HTTP 422 kopertą `{"errors":[{"code":"VALIDATION_ERROR",…}]}` — komunikat walidacyjny,
 *   bez danych kupującego. Pomiar pokrywa błąd walidacji, nie każdy możliwy status; gdyby
 *   jakiś błąd echował dane wejściowe, ucięcie do 300 znaków jest jedynym ogranicznikiem.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (nie na froncie).
 */
final class OrderSamplesCommand {

	use AllegroCliSupport;

	/**
	 * Domyślny górny limit zamówień do pobrania (D-3.G3 — publikujemy podzbiór).
	 */
	private const DEFAULT_MAX_ORDERS = 5;

	/**
	 * Rozmiar strony strumienia zdarzeń `GET /order/events`.
	 */
	private const EVENT_LIMIT = 100;

	/**
	 * Twardy limit strony listy zamówień (fallback) akceptowany przez Allegro.
	 */
	private const LIST_LIMIT_MAX = 100;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Pobiera surowe zwrotki zamówień i zapisuje je do katalogu `--out`.
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Katalog docelowy na surowe pliki JSON. Tworzony, jeśli nie istnieje. MUSI leżeć
	 *   POZA repozytorium — surowe zamówienia zawierają dane osobowe kupujących.
	 *
	 * [--max-orders=<n>]
	 * : Ile różnych zamówień najwyżej pobrać (po jednym na typ zdarzenia, potem dobicie).
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--checkout-form-id=<id>]
	 * : Pobierz DOKŁADNIE to zamówienie zamiast doboru ze zdarzeń. Strumień zdarzeń jest
	 *   i tak pobierany (to osobna próbka), ale nie służy wtedy za źródło id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sample-orders --out=/tmp/p33-raw
	 *     wp qutlet-allegro sample-orders --out=/tmp/p33-raw --max-orders=3
	 *
	 * @param array<int,string>    $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string> $assoc_args Flagi `--klucz=wartość`.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		/*
		 * `--out` PODANE BEZ WARTOŚCI WP-CLI przekazuje jako `true` (flaga), a
		 * `(string) true` to `'1'` — powstałby katalog `1` względem cwd, czyli zrzut
		 * realnego PII gdzieś w drzewie WordPressa. Dlatego wymagamy jawnego stringa,
		 * zamiast rzutować cokolwiek, co przyjdzie.
		 */
		$out_flag = get_flag_value( $assoc_args, 'out', '' );

		if ( ! is_string( $out_flag ) || '' === $out_flag ) {
			WP_CLI::error( 'Podaj katalog docelowy jako ścieżkę: --out=<dir> (poza repozytorium).' );
		}

		$out         = $out_flag;
		$max_orders  = max( 1, (int) get_flag_value( $assoc_args, 'max-orders', (string) self::DEFAULT_MAX_ORDERS ) );
		$id_flag     = get_flag_value( $assoc_args, 'checkout-form-id', '' );
		$explicit_id = is_string( $id_flag ) ? $id_flag : '';

		if ( ! wp_mkdir_p( $out ) ) {
			WP_CLI::error( sprintf( 'Nie mogę utworzyć/otworzyć katalogu docelowego: %s', $out ) );
		}

		$access = $this->access_token( Environment::PRODUCTION, Environment::ROLE_READ );
		$api    = Environment::for_environment( Environment::PRODUCTION )->api_base_url();

		// 1. Strumień zdarzeń zamówień (próbka sama w sobie ORAZ źródło checkoutFormId).
		$events_url  = $api . '/order/events?' . http_build_query( array( 'limit' => self::EVENT_LIMIT ) );
		$events_resp = $this->get( $events_url, $access );

		if ( 200 === $events_resp['status'] ) {
			$this->write( $out . '/GET_order-events.raw.json', $events_resp['body'] );
		} else {
			WP_CLI::warning( sprintf( 'GET /order/events → HTTP %d %s', $events_resp['status'], $this->error_detail( $events_resp ) ) );
		}

		$events = $this->list_from( $events_resp, 'events', 'GET /order/events' );

		// 2. Dobór zamówień: jawna flaga > zdarzenia > fallback na listę zamówień.
		$source = 'flag';

		if ( '' !== $explicit_id ) {
			$selected = array( $explicit_id => 'explicit-flag' );
		} else {
			$selected = $this->form_ids_from_events( $events, $max_orders );
			$source   = 'events';
		}

		if ( array() === $selected ) {
			WP_CLI::log( 'Strumień zdarzeń nie dał żadnego checkoutFormId — fallback na GET /order/checkout-forms (D-3.3.2).' );

			$list_url  = $api . '/order/checkout-forms?' . http_build_query(
				array(
					'limit'  => min( self::LIST_LIMIT_MAX, $max_orders ),
					'offset' => 0,
				)
			);
			$list_resp = $this->get( $list_url, $access );

			if ( 200 === $list_resp['status'] ) {
				$this->write( $out . '/GET_order-checkout-forms_list.raw.json', $list_resp['body'] );
			} else {
				WP_CLI::warning( sprintf( 'GET /order/checkout-forms → HTTP %d %s', $list_resp['status'], $this->error_detail( $list_resp ) ) );
			}

			$selected = $this->form_ids_from_list(
				$this->list_from( $list_resp, 'checkoutForms', 'GET /order/checkout-forms' ),
				$max_orders
			);
			$source   = 'checkout-forms-list';
		}

		if ( array() === $selected ) {
			WP_CLI::error( 'Nie znalazłem żadnego checkoutFormId (zdarzenia i lista zamówień puste) — brak materiału do próbkowania.' );
		}

		WP_CLI::log(
			sprintf(
				'Zdarzenia: %d. Wybrano %d zamówień (źródło id: %s).',
				count( $events ),
				count( $selected ),
				$source
			)
		);

		// 3. Pojedyncze zamówienia (pełna zwrotka — TU są dane osobowe kupującego).
		$orders = array();

		foreach ( $selected as $form_id => $origin ) {
			$form_id  = (string) $form_id;
			$form_url = $api . '/order/checkout-forms/' . rawurlencode( $form_id );
			$form     = $this->get( $form_url, $access );
			$file     = 'order-checkout-form_' . $this->safe_name( $form_id ) . '.raw.json';

			if ( 200 === $form['status'] ) {
				$this->write( $out . '/' . $file, $form['body'] );
			} else {
				WP_CLI::warning( sprintf( 'Zamówienie %s → HTTP %d %s', $form_id, $form['status'], $this->error_detail( $form ) ) );
			}

			$orders[ $form_id ] = array(
				'origin' => $origin,
				'status' => $form['status'],
				'file'   => 200 === $form['status'] ? $file : null,
			);

			WP_CLI::log( sprintf( '  order %s (%s): HTTP %d', $form_id, $origin, $form['status'] ) );
		}

		// 4. Manifest — kontekst doboru (BEZ tokenów i BEZ treści zamówień).
		$manifest = array(
			'environment' => Environment::PRODUCTION,
			'api_base'    => $api,
			'events'      => array(
				'url'    => $events_url,
				'status' => $events_resp['status'],
				'count'  => count( $events ),
			),
			'id_source'   => $source,
			'max_orders'  => $max_orders,
			'orders'      => $orders,
		);

		$this->write( $out . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		/*
		 * Sukces ogłaszamy tylko wtedy, gdy powstał materiał dla P-3.3b. Gdy KAŻDE
		 * `GET /order/checkout-forms/{id}` padło, w katalogu zostaje sam strumień
		 * zdarzeń i manifest — stan bezużyteczny, który bez tego warunku byłby
		 * nieodróżnialny od sukcesu (exit 0 + „Zapisano…”).
		 */
		$saved = 0;

		foreach ( $orders as $order ) {
			if ( null !== $order['file'] ) {
				++$saved;
			}
		}

		if ( 0 === $saved ) {
			WP_CLI::error(
				sprintf(
					'Żadne z %d zamówień nie zostało pobrane (same błędy HTTP) — w %s jest tylko strumień zdarzeń i manifest.',
					count( $orders ),
					$out
				)
			);
		}

		WP_CLI::success( sprintf( 'Zapisano %d zamówień do: %s (NIE commituj — realne PII).', $saved, $out ) );
	}

	/**
	 * Wyciąga tablicę spod oczekiwanego klucza koperty. Gdy klucza NIE ma, loguje
	 * faktyczne klucze najwyższego poziomu — nie zgadujemy kształtu zwrotki, tylko
	 * pokazujemy, co Allegro naprawdę przysłało (same nazwy kluczy, nie wartości,
	 * więc bez PII na stdout).
	 *
	 * @param array{status:int,body:string,data:array<mixed>|null,error:string} $resp  Wynik {@see self::fetch()}.
	 * @param string                                                           $key   Oczekiwany klucz koperty.
	 * @param string                                                           $label Nazwa endpointu do komunikatu.
	 * @return array<int,mixed> Lista spod klucza albo pusta tablica.
	 */
	private function list_from( array $resp, string $key, string $label ): array {
		if ( ! is_array( $resp['data'] ) ) {
			return array();
		}

		if ( ! isset( $resp['data'][ $key ] ) || ! is_array( $resp['data'][ $key ] ) ) {
			WP_CLI::warning(
				sprintf(
					'%s: brak oczekiwanego klucza "%s" w zwrotce. Klucze najwyższego poziomu: %s.',
					$label,
					$key,
					implode( ', ', array_keys( $resp['data'] ) )
				)
			);

			return array();
		}

		return array_values( $resp['data'][ $key ] );
	}

	/**
	 * Wybiera `checkoutFormId` ze zdarzeń: najpierw po JEDNYM na każdy napotkany TYP
	 * zdarzenia (D-3.G3 — różne momenty życia zamówienia), potem dobija pozostałymi
	 * różnymi id do `$max`.
	 *
	 * @param array<int,mixed> $events Zdarzenia z `GET /order/events`.
	 * @param int              $max    Górny limit zamówień.
	 * @return array<string,string> Mapa `checkoutFormId => typ zdarzenia, które je wskazało`.
	 */
	private function form_ids_from_events( array $events, int $max ): array {
		$first_per_type = array();
		$all            = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['order']['checkoutForm']['id'] ) ) {
				continue;
			}

			$id   = (string) $event['order']['checkoutForm']['id'];
			$type = isset( $event['type'] ) ? (string) $event['type'] : 'unknown';

			if ( '' === $id ) {
				continue;
			}

			if ( ! isset( $all[ $id ] ) ) {
				$all[ $id ] = $type;
			}

			if ( ! isset( $first_per_type[ $type ] ) ) {
				$first_per_type[ $type ] = $id;
			}
		}

		$selected = array();

		foreach ( $first_per_type as $type => $id ) {
			if ( count( $selected ) >= $max ) {
				break;
			}

			$selected[ (string) $id ] = (string) $type;
		}

		foreach ( $all as $id => $type ) {
			if ( count( $selected ) >= $max ) {
				break;
			}

			/*
			 * Id wybrane już w fazie 1 (po jednym na typ) POMIJAMY — bez tego dobicie
			 * nadpisywało jego etykietę pierwszym typem z `$all`, przez co manifest/stdout
			 * przypisywał zamówieniu inny typ zdarzenia, niż ten, który je wskazał. Selekcja
			 * (zbiór id) była poprawna — mylił się tylko opis. Pomijanie niczego nie usuwa
			 * z wyboru: nadpisanie istniejącego klucza i tak nie zwiększało licznika.
			 */
			if ( isset( $selected[ (string) $id ] ) ) {
				continue;
			}

			$selected[ (string) $id ] = (string) $type;
		}

		return $selected;
	}

	/**
	 * Wyciąga `id` z listy zamówień (fallback, gdy zdarzenia są puste — D-3.3.2).
	 *
	 * @param array<int,mixed> $forms Elementy `checkoutForms` z `GET /order/checkout-forms`.
	 * @param int              $max   Górny limit zamówień.
	 * @return array<string,string> Mapa `checkoutFormId => 'checkout-forms-list'`.
	 */
	private function form_ids_from_list( array $forms, int $max ): array {
		$selected = array();

		foreach ( $forms as $form ) {
			if ( count( $selected ) >= $max ) {
				break;
			}

			if ( ! is_array( $form ) || ! isset( $form['id'] ) ) {
				continue;
			}

			$id = (string) $form['id'];

			if ( '' === $id ) {
				continue;
			}

			$selected[ $id ] = 'checkout-forms-list';
		}

		return $selected;
	}
}
