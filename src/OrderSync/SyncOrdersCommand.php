<?php
/**
 * Slice OrderSync — komenda WP-CLI importu zamówień Allegro → WooCommerce (P-6.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro sync-orders` — przyrostowy import zamówień Allegro do natywnych
 * `WC_Order` (mapping §8, D-6.3.1–D-6.3.6). Odpalane RĘCZNIE (debug/testy);
 * automatyczny polling (scheduler WP-Cron wzorca `StockSyncScheduler`) to OSOBNY,
 * przyszły punkt (D-6.3.3, POZA zakresem P-6.3b) — gdy powstanie, konfiguracja
 * środowisk pójdzie wzorcem wp-config (P-6.2c), nie hardkodem.
 *
 * ## Przebieg (D-6.3.6)
 * Przyrostowy polling `GET /order/events` z WŁASNYM kursorem
 * (`qutlet_allegro_order_sync_cursor_{środowisko}`, kontrakt §12.3) — NIE
 * współdzielonym z kursorem stanów P-6.2 (§10.5): osobni konsumenci tego samego
 * endpointu, osobne kursory. Ze zdarzeń bierzemy `checkoutForm.id` zdarzeń typu
 * `READY_FOR_PROCESSING` (D-6.3.1), pobieramy AUTORYTATYWNĄ treść
 * `GET /order/checkout-forms/{id}` (§8d — nie budujemy zamówienia ze snapshotu
 * zdarzenia) i robimy idempotentny upsert `WC_Order` ({@see OrderWriter}). Zdarzenia
 * pozostałych typów (`FILLED_IN`, `BOUGHT`, `FULFILLMENT_STATUS_CHANGED`) są
 * POMIJANE i policzone w podsumowaniu (D-6.3.1) — tranzycje wysyłki/anulowania/
 * zwrotu mają kształt spoza próbki i domknie je osobny punkt (§8f).
 *
 * ## Rzetelność przebiegu (jak `SyncStockCommand`, D-6.G2)
 * Lock per środowisko ({@see OrderSyncLock}); HTTP 429 przerywa przebieg BEZ
 * przesunięcia kursora (backoff = kolejny przebieg); kursor przesuwa się dopiero po
 * przetworzeniu wszystkich stron. Wewnątrz sekcji z lockiem NIE wolno wywołać
 * `WP_CLI::error()` (kończy proces `exit`, omijając `finally` ze zwolnieniem zamka)
 * — błędy fatalne wracają stringiem i są zgłaszane PO zwolnieniu. Token i baza API
 * pobierane PRZED lockiem (`access_token()` przy braku kończy proces).
 *
 * Slot `read` (D-6.G5): odczyt zdarzeń i zamówień to operacje read-only — komenda
 * NIE robi żadnego żądania zapisu do Allegro.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class SyncOrdersCommand {

	use AllegroCliSupport;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy) — {@see AllegroCliSupport::send()}.
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Prefiks opcji kursora `order/events` per środowisko — kontrakt §12.3 (VERBATIM).
	 * OSOBNY od kursora stanów (`qutlet_allegro_stock_sync_cursor_`, §10.5).
	 */
	private const OPTION_CURSOR_PREFIX = 'qutlet_allegro_order_sync_cursor_';

	/**
	 * Rozmiar strony strumienia zdarzeń `GET /order/events`.
	 */
	private const EVENT_PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (jak pozostałe komendy repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Zapis zamówień do `WC_Order`.
	 *
	 * @var OrderWriter
	 */
	private $writer;

	/**
	 * Liczniki przebiegu (podsumowanie).
	 *
	 * @var array<string,int>
	 */
	private $counters = array();

	/**
	 * Importuje zamówienia Allegro do WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--environment=<env>]
	 * : Środowisko (`sandbox`/`production`); slot `read` (D-6.G5).
	 * ---
	 * default: sandbox
	 * options:
	 *   - sandbox
	 *   - production
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sync-orders
	 *     wp qutlet-allegro sync-orders --environment=production
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$environment = (string) get_flag_value( $assoc_args, 'environment', Environment::SANDBOX );

		if ( Environment::SANDBOX !== $environment && Environment::PRODUCTION !== $environment ) {
			WP_CLI::error( sprintf( 'Nieznane środowisko: „%s" (dozwolone: sandbox, production).', $environment ) );
		}

		// Token i baza PRZED lockiem: `access_token()` przy braku kończy proces
		// (`WP_CLI::error` → exit), co ominęłoby `finally` ze zwolnieniem zamka.
		$access = $this->access_token( $environment, Environment::ROLE_READ );
		$api    = Environment::for_environment( $environment )->api_base_url();

		$this->writer   = new OrderWriter();
		$this->counters = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'trashed'   => 0,
			'not_ready' => 0,
			'skipped'   => 0,
			'gone'      => 0,
			'errors'    => 0,
		);

		$lock = new OrderSyncLock();

		if ( ! $lock->acquire( $environment ) ) {
			// Normalna sytuacja pod cronem (poprzedni przebieg jeszcze trwa) —
			// wyjście sukcesem, bez pracy.
			WP_CLI::log( sprintf( 'Inny przebieg sync-orders (%s) trwa — pomijam (lock).', $environment ) );

			return;
		}

		try {
			$fatal = $this->run( $environment, $api, $access );
		} finally {
			$lock->release( $environment );
		}

		if ( null !== $fatal ) {
			WP_CLI::error( $fatal );
		}

		$c = $this->counters;
		WP_CLI::success(
			sprintf(
				'sync-orders (%s): utworzone %d, zaktualizowane %d, bez zmian %d, w koszu %d, nie-READY zdarzeń %d, pominięte %d, zniknięte (404) %d, błędy %d.',
				$environment,
				$c['created'],
				$c['updated'],
				$c['unchanged'],
				$c['trashed'],
				$c['not_ready'],
				$c['skipped'],
				$c['gone'],
				$c['errors']
			)
		);
	}

	/**
	 * Właściwy przebieg — POD lockiem, więc bez `WP_CLI::error()` (patrz docblock
	 * klasy); błąd fatalny wraca stringiem. Wzorzec kursora/paginacji powielony z
	 * {@see \Qutlet\Allegro\OfferSync\SyncStockCommand::incremental()}.
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function run( string $environment, string $api, string $access ): ?string {
		$option = self::OPTION_CURSOR_PREFIX . $environment;
		$cursor = (string) get_option( $option, '' );

		$form_ids     = array();
		$skipped_seen = 0;
		$last_id      = '';
		$from         = $cursor;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array( 'limit' => self::EVENT_PAGE_LIMIT );

			if ( '' !== $from ) {
				$query['from'] = $from;
			}

			$resp = $this->get( $api . '/order/events?' . http_build_query( $query ), $access );

			if ( 429 === $resp['status'] ) {
				WP_CLI::warning( 'HTTP 429 na order/events — przerywam przebieg bez przesuwania kursora (backoff = kolejny przebieg).' );

				return null;
			}

			if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
				return sprintf( 'GET /order/events (from=%s) → HTTP %d %s — kursor bez zmian, następny przebieg ponowi.', $from, $resp['status'], $this->error_detail( $resp ) );
			}

			$events = isset( $resp['data']['events'] ) && is_array( $resp['data']['events'] )
				? array_values( $resp['data']['events'] )
				: array();

			if ( array() === $events ) {
				break;
			}

			foreach ( self::ready_checkout_form_ids_from_events( $events ) as $form_id ) {
				$form_ids[ $form_id ] = true;
			}

			$skipped_seen += self::non_ready_event_count( $events );

			$page_last = self::last_event_id( $events );

			if ( '' === $page_last ) {
				return 'Strona order/events bez id ostatniego zdarzenia — nie mogę bezpiecznie przesunąć kursora.';
			}

			$last_id = $page_last;
			$from    = $page_last;

			if ( count( $events ) < self::EVENT_PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES - 1 === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano paginację zdarzeń na bezpieczniku %d stron — resztę dociągnie następny przebieg.', self::MAX_PAGES ) );
			}
		}

		$this->counters['not_ready'] = $skipped_seen;

		if ( '' === $last_id ) {
			// Strumień naprawdę pusty — kursor NIE jest ustawiany: przy pierwszym
			// realnym zdarzeniu ten sam kod wejdzie tu z tym samym pustym kursorem.
			WP_CLI::log( 'Strumień zdarzeń pusty — kursor zainicjuje się przy pierwszym zdarzeniu.' );

			return null;
		}

		if ( array() === $form_ids ) {
			// Zdarzenia były (kursor musi ruszyć), ale żadne nie było typu
			// READY_FOR_PROCESSING — nic do zaimportowania.
			WP_CLI::log( sprintf( 'Brak zdarzeń READY_FOR_PROCESSING (%d nie-READY pominięto) — kursor przesunięty.', $skipped_seen ) );
			update_option( $option, $last_id, false );

			return null;
		}

		if ( '' === $cursor ) {
			WP_CLI::log( sprintf( 'Pierwszy przebieg (%s): %d zamówień READY_FOR_PROCESSING z dostępnej historii zdarzeń.', $environment, count( $form_ids ) ) );
		} else {
			WP_CLI::log( sprintf( 'Zdarzenia wskazują %d zamówień READY_FOR_PROCESSING do importu.', count( $form_ids ) ) );
		}

		$fetch_error = false;

		foreach ( array_keys( $form_ids ) as $form_id ) {
			$status = $this->import_order( $api, $access, (string) $form_id );

			if ( 'rate-limited' === $status ) {
				WP_CLI::warning( 'HTTP 429 na checkout-forms — przerywam przebieg bez przesuwania kursora (backoff = kolejny przebieg).' );

				return null;
			}

			if ( 'error' === $status ) {
				// Nie przerywamy — inne zdrowe zamówienia z tego okna importujemy dalej;
				// ale zapamiętujemy, że kursora NIE wolno przesunąć (patrz niżej).
				$fetch_error = true;
			}
		}

		if ( $fetch_error ) {
			// Choć jedno zamówienie nie dało się pobrać (błąd przejściowy) — kursor
			// ZOSTAJE, żeby następny przebieg ponowił całe okno i dociągnął pominięte
			// (D-6.3.2). Zdrowe zamówienia już zapisane; ponowny upsert to NO-OP.
			WP_CLI::warning( 'Część zamówień nie została pobrana (błędy przejściowe) — kursor bez zmian, następny przebieg ponowi okno.' );

			return null;
		}

		update_option( $option, $last_id, false );

		return null;
	}

	/**
	 * Pobiera autorytatywną treść zamówienia i robi upsert `WC_Order`. Zwraca
	 * `rate-limited` przy 429 (wołający przerywa BEZ przesuwania kursora) oraz `error`
	 * przy przejściowym błędzie pobrania treści (wołający WSTRZYMUJE kursor, ale
	 * dokańcza pozostałe zamówienia). `skip` = świadome pominięcie z przesunięciem
	 * kursora (404 zniknęło / nie-READY w checkout-form).
	 *
	 * @param string $api     Baza REST API.
	 * @param string $access  Access token slotu `read`.
	 * @param string $form_id `checkoutForm.id`.
	 * @return string `ok` / `skip` / `error` / `rate-limited`.
	 */
	private function import_order( string $api, string $access, string $form_id ): string {
		$resp = $this->get( $api . '/order/checkout-forms/' . rawurlencode( $form_id ), $access );

		if ( 429 === $resp['status'] ) {
			return 'rate-limited';
		}

		if ( 404 === $resp['status'] ) {
			++$this->counters['gone'];
			WP_CLI::log( sprintf( '  zamówienie %s nie istnieje już w Allegro (404) — pomijam.', $form_id ) );

			return 'skip';
		}

		if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
			// Błąd PRZEJŚCIOWY pobrania treści (5xx/sieć), inny niż 404 (zniknęło) —
			// zwracamy `error`, żeby wołający WSTRZYMAŁ kursor: bez toru rekoncyliacji
			// (odpowiednika `--full` w SyncStockCommand) przesunięcie kursora nad
			// nieodczytane zamówienie zgubiłoby OPŁACONĄ sprzedaż na zawsze (D-6.3.2 —
			// realna sprzedaż nie może zniknąć; zdarzenie READY zwykle się nie powtarza).
			// Kolejny przebieg ponowi całe okno — upsert jest idempotentny (rewizja NO-OP),
			// więc zdrowe zamówienia to tanie NO-OP-y.
			++$this->counters['errors'];
			WP_CLI::warning( sprintf( 'Zamówienie %s: checkout-forms → HTTP %d %s', $form_id, $resp['status'], $this->error_detail( $resp ) ) );

			return 'error';
		}

		$form = $resp['data'];

		// Autorytatywny status z checkout-form (§8d): zamówienie mogło już opuścić
		// READY_FOR_PROCESSING między zdarzeniem a pobraniem treści (D-6.3.1 —
		// tranzycje poza READY są poza zakresem; skip + log).
		if ( ! OrderMapper::is_ready( $form ) ) {
			++$this->counters['skipped'];
			WP_CLI::log( sprintf( '  zamówienie %s nie jest READY_FOR_PROCESSING w checkout-form — pomijam (D-6.3.1).', $form_id ) );

			return 'skip';
		}

		$result = $this->writer->upsert( $form );

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( sprintf( 'Zamówienie %s: %s', $form_id, $warning ) );
		}

		$this->note_upsert_result( $form_id, $result );

		return 'ok';
	}

	/**
	 * Księgowanie wyniku upsertu (liczniki + log).
	 *
	 * @param string                                                          $form_id `checkoutForm.id`.
	 * @param array{action:string,order_id:int,warnings:array<int,string>}    $result  Wynik {@see OrderWriter::upsert()}.
	 * @return void
	 */
	private function note_upsert_result( string $form_id, array $result ): void {
		switch ( $result['action'] ) {
			case 'created':
				++$this->counters['created'];
				WP_CLI::log( sprintf( '  zamówienie %s → utworzone WC_Order %d.', $form_id, $result['order_id'] ) );
				break;
			case 'updated':
				++$this->counters['updated'];
				WP_CLI::log( sprintf( '  zamówienie %s → zaktualizowane WC_Order %d (zmiana rewizji).', $form_id, $result['order_id'] ) );
				break;
			case 'unchanged':
				++$this->counters['unchanged'];
				break;
			case 'skipped-trashed':
				++$this->counters['trashed'];
				WP_CLI::log( sprintf( '  zamówienie %s → WC_Order %d w koszu (wycofane ręcznie) — pomijam, nie odtwarzam.', $form_id, $result['order_id'] ) );
				break;
			default:
				++$this->counters['errors'];
				WP_CLI::warning( sprintf( 'Zamówienie %s: upsert nieudany (%s).', $form_id, $result['action'] ) );
				break;
		}
	}

	/**
	 * Unikalne `checkoutForm.id` zdarzeń typu `READY_FOR_PROCESSING` (D-6.3.1) —
	 * jedyny typ, dla którego tworzymy `WC_Order`. Kolejność pierwszego wystąpienia.
	 *
	 * Czysta funkcja statyczna — testowana PHPUnitem na kształcie realnej próbki
	 * (`docs/allegro-api-samples/GET_order-events.json`).
	 *
	 * @param array<int,mixed> $events Zdarzenia z `GET /order/events`.
	 * @return array<int,string> Unikalne id zamówień.
	 */
	public static function ready_checkout_form_ids_from_events( array $events ): array {
		$ids = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( OrderMapper::STATUS_READY !== ( $event['type'] ?? null ) ) {
				continue;
			}

			$id = $event['order']['checkoutForm']['id'] ?? null;

			if ( ( is_string( $id ) || is_int( $id ) ) && '' !== (string) $id ) {
				// Klucz i wartość = string: id zamówień to time UUID (nie-numeryczne),
				// ale trzymamy się wzorca `offer_ids_from_events` (dedup przez wartość).
				$ids[ (string) $id ] = (string) $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Liczba zdarzeń INNEGO typu niż `READY_FOR_PROCESSING` na stronie (D-6.3.1 —
	 * „pomijane + logowane" jako podsumowanie, bez zaśmiecania logu wpisem na każde).
	 * Czysta funkcja statyczna (testy).
	 *
	 * @param array<int,mixed> $events Zdarzenia strony.
	 * @return int
	 */
	public static function non_ready_event_count( array $events ): int {
		$count = 0;

		foreach ( $events as $event ) {
			if ( is_array( $event ) && OrderMapper::STATUS_READY !== ( $event['type'] ?? null ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Id OSTATNIEGO zdarzenia strony (kursor). Czysta funkcja statyczna (testy).
	 * Powielona z {@see \Qutlet\Allegro\OfferSync\SyncStockCommand::last_event_id()}
	 * (świadome powielenie wzorca — granica vertical slice, jak {@see OrderSyncLock}).
	 *
	 * @param array<int,mixed> $events Zdarzenia strony (kolejność API: rosnąco).
	 * @return string Pusty string, gdy ostatni wpis nie niesie id.
	 */
	public static function last_event_id( array $events ): string {
		$last = end( $events );

		if ( ! is_array( $last ) ) {
			return '';
		}

		$id = $last['id'] ?? null;

		return is_string( $id ) || is_int( $id ) ? (string) $id : '';
	}
}
