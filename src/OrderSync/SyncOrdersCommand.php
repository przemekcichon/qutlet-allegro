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
 * `wp qutlet-allegro sync-orders` — import ORAZ synchronizacja statusów zamówień
 * Allegro → natywne `WC_Order` (mapping §8, D-6.3.1–D-6.3.6 + P-6.5c/D-6.5.1–D-6.5.7).
 * Kierunek = TYLKO pull (D-6.5.1): slot `read`, zero zapisu do Allegro. Odpalane
 * RĘCZNIE (debug/testy) LUB automatycznie przez harmonogram WP-Cron
 * {@see \Qutlet\Allegro\OrderSync\OrderSyncScheduler} (P-6.9, realizacja
 * odłożonego D-6.3.3).
 *
 * ## Tor przyrostowy (kursor) — D-6.3.6 + P-6.5c
 * Przyrostowy polling `GET /order/events` z WŁASNYM kursorem
 * (`qutlet_allegro_order_sync_cursor_{środowisko}`, kontrakt §12.3) — NIE
 * współdzielonym z kursorem stanów P-6.2 (§10.5). Konsumujemy zdarzenia typów
 * {@see self::CONSUMED_EVENT_TYPES}: `READY_FOR_PROCESSING` (import/treść) ORAZ
 * tranzycje `FULFILLMENT_STATUS_CHANGED`/`BUYER_CANCELLED`/`AUTO_CANCELLED` (zmiana
 * statusu, P-6.5c). Dla KAŻDEGO ich `checkoutForm.id` pobieramy AUTORYTATYWNĄ treść
 * `GET /order/checkout-forms/{id}` (§8d — nie budujemy zamówienia ze snapshotu
 * zdarzenia) i robimy idempotentny upsert ({@see OrderWriter} godzi oś treści i oś
 * statusu, D-6.5.7). Zdarzenia `FILLED_IN`/`BOUGHT` (niezapłacone, próg = opłacone)
 * są POMIJANE i policzone w podsumowaniu.
 *
 * ## Tor rekoncyliacji (`--full`) — D-6.5.6
 * Kursor przyrostowy pokrywa PRZYSZŁOŚĆ, ale zamówienia zmienione zanim P-6.5c
 * konsumował tranzycje (albo gdy zdarzenie przeleciało bez akcji) utknęłyby w
 * `wc-processing`. `--full` iteruje zaimportowane `WC_Order` w stanie NIETERMINALNYM
 * (mają `_qutlet_allegro_checkout_form_id`), dociąga ich bieżącą treść z Allegro i
 * stosuje mapowanie — bez kursora. Wzorzec {@see \Qutlet\Allegro\OfferSync\SyncStockCommand}
 * (`--full`).
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
	 * Rozmiar strony przy iteracji zaimportowanych zamówień w torze `--full`.
	 */
	private const ORDER_PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (jak pozostałe komendy repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Typy zdarzeń `order/events` KONSUMOWANE przez pull (kontrakt §12.5, D-6.3.1 +
	 * D-6.5.4). `READY_FOR_PROCESSING` niesie próg importu/treści; pozostałe trzy to
	 * tranzycje statusu (wysyłka + anulowanie). `FILLED_IN`/`BOUGHT` (niezapłacone)
	 * świadomie POZA zbiorem — próg tworzenia = opłacone. Dla wszystkich pobieramy
	 * autorytatywną treść i godzimy oś treści+statusu ({@see OrderWriter::upsert()}).
	 *
	 * @var array<int,string>
	 */
	private const CONSUMED_EVENT_TYPES = array(
		OrderMapper::STATUS_READY, // 'READY_FOR_PROCESSING'
		'FULFILLMENT_STATUS_CHANGED',
		'BUYER_CANCELLED',
		'AUTO_CANCELLED',
	);

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
	 * [--full]
	 * : Rekoncyliacja: zamiast toru przyrostowego (kursor `order/events`) iteruje
	 *   zaimportowane `WC_Order` w stanie nieterminalnym i dociąga ich bieżący status
	 *   z Allegro (nadrabia backlog/dryf; D-6.5.6).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sync-orders
	 *     wp qutlet-allegro sync-orders --environment=production
	 *     wp qutlet-allegro sync-orders --environment=sandbox --full
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

		$full = (bool) get_flag_value( $assoc_args, 'full', false );

		// Token i baza PRZED lockiem: `access_token()` przy braku kończy proces
		// (`WP_CLI::error` → exit), co ominęłoby `finally` ze zwolnieniem zamka.
		$access = $this->access_token( $environment, Environment::ROLE_READ );
		$api    = Environment::for_environment( $environment )->api_base_url();

		$this->writer   = new OrderWriter();
		$this->counters = array(
			'created'         => 0,
			'updated'         => 0,
			'status_updated'  => 0,
			'unchanged'       => 0,
			'trashed'         => 0,
			'skipped_no_order' => 0,
			'skipped_events'  => 0,
			'gone'            => 0,
			'errors'          => 0,
		);

		$lock = new OrderSyncLock();

		if ( ! $lock->acquire( $environment ) ) {
			// Normalna sytuacja pod cronem (poprzedni przebieg jeszcze trwa) —
			// wyjście sukcesem, bez pracy.
			WP_CLI::log( sprintf( 'Inny przebieg sync-orders (%s) trwa — pomijam (lock).', $environment ) );

			return;
		}

		try {
			$fatal = $this->run( $environment, $api, $access, $full );
		} finally {
			$lock->release( $environment );
		}

		if ( null !== $fatal ) {
			WP_CLI::error( $fatal );
		}

		$c = $this->counters;
		WP_CLI::success(
			sprintf(
				'sync-orders (%s%s): utworzone %d, treść zaktualizowana %d, status zaktualizowany %d, bez zmian %d, w koszu %d, tranzycja bez zamówienia %d, zdarzeń pominiętych %d, zniknięte (404) %d, błędy %d.',
				$environment,
				$full ? ', --full' : '',
				$c['created'],
				$c['updated'],
				$c['status_updated'],
				$c['unchanged'],
				$c['trashed'],
				$c['skipped_no_order'],
				$c['skipped_events'],
				$c['gone'],
				$c['errors']
			)
		);
	}

	/**
	 * Właściwy przebieg — POD lockiem, więc bez `WP_CLI::error()` (patrz docblock
	 * klasy); błąd fatalny wraca stringiem. Rozgałęzia na tor przyrostowy (kursor)
	 * albo rekoncyliację `--full` (D-6.5.6), jak {@see \Qutlet\Allegro\OfferSync\SyncStockCommand::run()}.
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @param bool   $full        Tryb rekoncyliacji zamiast przyrostu.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function run( string $environment, string $api, string $access, bool $full ): ?string {
		if ( $full ) {
			// Rekoncyliacja deleguje błędy HTTP do {@see self::sync_order()} (liczniki +
			// backoff wewnątrz), więc nie ma własnej ścieżki błędu fatalnego.
			$this->reconcile( $environment, $api, $access );

			return null;
		}

		return $this->incremental( $environment, $api, $access );
	}

	/**
	 * Tor przyrostowy — zdarzenia `order/events` od kursora → dla `checkoutForm.id`
	 * typów {@see self::CONSUMED_EVENT_TYPES} pobierz autorytatywną treść i upsert.
	 * Kursor przesuwa się DOPIERO po przetworzeniu całości (przerwany przebieg
	 * powtarza pracę — upsert idempotentny). Wzorzec kursora/paginacji powielony z
	 * {@see \Qutlet\Allegro\OfferSync\SyncStockCommand::incremental()}.
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function incremental( string $environment, string $api, string $access ): ?string {
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

			foreach ( self::synced_checkout_form_ids_from_events( $events ) as $form_id ) {
				$form_ids[ $form_id ] = true;
			}

			$skipped_seen += self::skipped_event_count( $events );

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

		$this->counters['skipped_events'] = $skipped_seen;

		if ( '' === $last_id ) {
			// Strumień naprawdę pusty — kursor NIE jest ustawiany: przy pierwszym
			// realnym zdarzeniu ten sam kod wejdzie tu z tym samym pustym kursorem.
			WP_CLI::log( 'Strumień zdarzeń pusty — kursor zainicjuje się przy pierwszym zdarzeniu.' );

			return null;
		}

		if ( array() === $form_ids ) {
			// Zdarzenia były (kursor musi ruszyć), ale żadne nie było typu
			// konsumowanego (same FILLED_IN/BOUGHT) — nic do przetworzenia.
			WP_CLI::log( sprintf( 'Brak zdarzeń do przetworzenia (%d pominiętych FILLED_IN/BOUGHT) — kursor przesunięty.', $skipped_seen ) );
			update_option( $option, $last_id, false );

			return null;
		}

		if ( '' === $cursor ) {
			WP_CLI::log( sprintf( 'Pierwszy przebieg (%s): %d zamówień z dostępnej historii zdarzeń do przetworzenia.', $environment, count( $form_ids ) ) );
		} else {
			WP_CLI::log( sprintf( 'Zdarzenia wskazują %d zamówień do przetworzenia.', count( $form_ids ) ) );
		}

		$fetch_error = false;

		foreach ( array_keys( $form_ids ) as $form_id ) {
			$status = $this->sync_order( $api, $access, (string) $form_id );

			if ( 'rate-limited' === $status ) {
				WP_CLI::warning( 'HTTP 429 na checkout-forms — przerywam przebieg bez przesuwania kursora (backoff = kolejny przebieg).' );

				return null;
			}

			if ( 'error' === $status ) {
				// Nie przerywamy — inne zdrowe zamówienia z tego okna przetwarzamy dalej;
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
	 * Tor rekoncyliacji (`--full`, D-6.5.6): iteruje zaimportowane `WC_Order` w stanie
	 * NIETERMINALNYM (mają klucz `_qutlet_allegro_checkout_form_id`) i dla każdego
	 * dociąga bieżącą treść z Allegro, stosując mapowanie statusu ({@see self::sync_order()}).
	 * Bez kursora — nadrabia backlog/dryf, których tor przyrostowy nie złapał (zdarzenie
	 * fulfillmentu przeleciało zanim P-6.5c je konsumował). Snapshot id z góry, żeby
	 * tranzycja statusu w trakcie nie zaburzyła paginacji.
	 *
	 * @param string $environment Środowisko (log; slot `read` wspólny).
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return void
	 */
	private function reconcile( string $environment, string $api, string $access ): void {
		$form_ids = $this->nonterminal_imported_form_ids();

		if ( array() === $form_ids ) {
			WP_CLI::log( sprintf( 'Rekoncyliacja (%s): brak zaimportowanych zamówień w stanie nieterminalnym — nic do zrobienia.', $environment ) );

			return;
		}

		WP_CLI::log( sprintf( 'Rekoncyliacja (%s): %d zamówień nieterminalnych do sprawdzenia.', $environment, count( $form_ids ) ) );

		foreach ( $form_ids as $form_id ) {
			$status = $this->sync_order( $api, $access, $form_id );

			if ( 'rate-limited' === $status ) {
				WP_CLI::warning( 'HTTP 429 na checkout-forms — przerywam rekoncyliację (dokończy następny przebieg --full).' );

				return;
			}
		}
	}

	/**
	 * `checkoutForm.id` zaimportowanych `WC_Order` (mają klucz idempotencji
	 * `_qutlet_allegro_checkout_form_id`, kontrakt §12.1) w stanie NIETERMINALNYM.
	 * Terminalne (`completed`/`cancelled`/`refunded`) i kosz pomijamy — Allegro nie
	 * cofa ich mapowaniem P-6.5c, a zwrot to osobny punkt (D-6.5.3). `wc-shipped`
	 * (D-6.5.5) jest świadomie NIETERMINALNY, więc pozostaje w zbiorze (łapiemy
	 * tranzycję `shipped → completed`). Zbieramy same id z góry (snapshot) — dalsze
	 * przetwarzanie mutuje statusy, więc nie iterujemy „żywych" wyników zapytania.
	 *
	 * `wc-failed` też pomijamy (traktowany jak terminalny — nieopłacony/porzucony;
	 * import nie tworzy zamówień w tym stanie, więc rekoncyliacja nie ma czego w nim
	 * nadrabiać). Zbiór to statusy NIETERMINALNE, w których realnie żyją zaimportowane
	 * zamówienia Allegro.
	 *
	 * @return array<int,string>
	 */
	private function nonterminal_imported_form_ids(): array {
		$statuses = array( 'wc-pending', 'wc-on-hold', 'wc-' . OrderMapper::WC_PROCESSING, 'wc-' . OrderMapper::WC_SHIPPED );
		$form_ids = array();

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::ORDER_PAGE_LIMIT,
					'paged'      => $page,
					'status'     => $statuses,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- klucz idempotencji importu zamówień (kontrakt §12.1); rekoncyliacja iteruje tylko zaimportowane zamówienia.
						array(
							'key'     => OrderWriter::META_CHECKOUT_FORM_ID,
							'compare' => 'EXISTS',
						),
					),
				)
			);

			if ( ! is_array( $orders ) || array() === $orders ) {
				break;
			}

			foreach ( $orders as $order ) {
				// `wc_get_orders` (bez `return`) daje obiekty `WC_Order` (stub gwarantuje
				// typ); czytamy klucz idempotencji przez WC CRUD (spójnie pod HPOS/legacy).
				$form_id = (string) $order->get_meta( OrderWriter::META_CHECKOUT_FORM_ID );

				if ( '' !== $form_id ) {
					$form_ids[] = $form_id;
				}
			}

			if ( count( $orders ) < self::ORDER_PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Rekoncyliacja: przerwano zbieranie zamówień na bezpieczniku %d stron — resztę dociągnie następny przebieg --full.', self::MAX_PAGES ) );
			}
		}

		return $form_ids;
	}

	/**
	 * Pobiera autorytatywną treść zamówienia (§8d) i robi upsert `WC_Order` (import
	 * treści + synchronizacja statusu — {@see OrderWriter::upsert()} rozstrzyga
	 * create/status/skip). Zwraca `rate-limited` przy 429 (wołający przerywa BEZ
	 * przesuwania kursora) oraz `error` przy przejściowym błędzie pobrania treści
	 * (wołający WSTRZYMUJE kursor, dokańczając pozostałe). `skip` = świadome pominięcie
	 * z przesunięciem kursora (404 zniknęło).
	 *
	 * @param string $api     Baza REST API.
	 * @param string $access  Access token slotu `read`.
	 * @param string $form_id `checkoutForm.id`.
	 * @return string `ok` / `skip` / `error` / `rate-limited`.
	 */
	private function sync_order( string $api, string $access, string $form_id ): string {
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
			// zwracamy `error`, żeby wołający (tor przyrostowy) WSTRZYMAŁ kursor:
			// przesunięcie nad nieodczytane zdarzenie READY zgubiłoby OPŁACONĄ sprzedaż
			// (D-6.3.2 — zdarzenie READY zwykle się nie powtarza; backlog dociąga
			// `--full`). Kolejny przebieg ponowi całe okno — upsert jest idempotentny.
			++$this->counters['errors'];
			WP_CLI::warning( sprintf( 'Zamówienie %s: checkout-forms → HTTP %d %s', $form_id, $resp['status'], $this->error_detail( $resp ) ) );

			return 'error';
		}

		// Cała decyzja (utworzyć / zmienić status / pominąć bez zamówienia / kosz) w
		// {@see OrderWriter::upsert()}: import treści dla READY_FOR_PROCESSING oraz
		// synchronizacja statusu z obu osi (P-6.5c). NIE bramkujemy tu `is_ready()` —
		// tranzycje (SENT/CANCELLED) muszą dojść do istniejącego zamówienia.
		$result = $this->writer->upsert( $resp['data'] );

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
				WP_CLI::log( sprintf( '  zamówienie %s → zaktualizowana treść WC_Order %d (zmiana rewizji).', $form_id, $result['order_id'] ) );
				break;
			case 'status-updated':
				++$this->counters['status_updated'];
				WP_CLI::log( sprintf( '  zamówienie %s → status WC_Order %d zsynchronizowany z Allegro (P-6.5c).', $form_id, $result['order_id'] ) );
				break;
			case 'unchanged':
				++$this->counters['unchanged'];
				break;
			case 'skipped-no-order':
				++$this->counters['skipped_no_order'];
				WP_CLI::log( sprintf( '  zamówienie %s → tranzycja bez zaimportowanego zamówienia — pomijam (nie tworzymy z połowy cyklu, P-6.5c).', $form_id ) );
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
	 * Unikalne `checkoutForm.id` zdarzeń typu KONSUMOWANEGO ({@see self::CONSUMED_EVENT_TYPES}:
	 * `READY_FOR_PROCESSING` + tranzycje wysyłki/anulowania). Kolejność pierwszego
	 * wystąpienia. Dla każdego id wołający pobiera autorytatywną treść i upsertuje.
	 *
	 * Czysta funkcja statyczna — testowana PHPUnitem na kształcie realnej próbki
	 * (`docs/allegro-api-samples/GET_order-events.json`).
	 *
	 * @param array<int,mixed> $events Zdarzenia z `GET /order/events`.
	 * @return array<int,string> Unikalne id zamówień.
	 */
	public static function synced_checkout_form_ids_from_events( array $events ): array {
		$ids = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			if ( ! in_array( $event['type'] ?? null, self::CONSUMED_EVENT_TYPES, true ) ) {
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
	 * Liczba zdarzeń POMINIĘTYCH — typu spoza {@see self::CONSUMED_EVENT_TYPES}
	 * (`FILLED_IN`/`BOUGHT`: niezapłacone) — jako podsumowanie, bez zaśmiecania logu
	 * wpisem na każde. Czysta funkcja statyczna (testy).
	 *
	 * @param array<int,mixed> $events Zdarzenia strony.
	 * @return int
	 */
	public static function skipped_event_count( array $events ): int {
		$count = 0;

		foreach ( $events as $event ) {
			if ( is_array( $event ) && ! in_array( $event['type'] ?? null, self::CONSUMED_EVENT_TYPES, true ) ) {
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
