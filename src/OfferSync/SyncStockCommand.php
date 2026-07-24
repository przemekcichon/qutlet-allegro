<?php
/**
 * Slice OfferSync — komenda WP-CLI synchronizacji stanów i cen (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use WC_Product;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro sync-stock` — utrzymanie synchronu stanów magazynowych
 * (i cen — mapping §5) między Allegro a Woo. Odpalane ręcznie (debug/testy) albo
 * przez WP-Cron ({@see StockSyncScheduler}, D-6.G1 zrewidowane 2026-07-24: własny
 * interwał ~2 min zamiast bezpośredniego wywołania z systemowego crona; systemowy
 * cron na Local tyka JEDNĄ linią `wp cron event run --due-now` — konfiguracja
 * crona = handoff, ale prościej niż poprzednia wersja).
 *
 * ## Model D-6.G3 (rozstrzygnięty 2026-07-23)
 * - Woo → Allegro: sprzedaż w sklepie pushuje NATYCHMIAST hookiem
 *   ({@see StockPushListener}); ten przebieg jedynie PONAWIA zaległe pushe
 *   (marker {@see StockPusher::META_PUSH_PENDING}).
 * - Allegro → Woo: przyrostowo po `GET /order/events` (własny kursor per
 *   środowisko — D-6.G2: NIE ciągniemy pełnej listy co 2 min) → dla zmienionych
 *   ofert świeży `GET .../parts` → stan + cena (`cena_allegro` → przeliczenie
 *   `_price`, kontrakt §11).
 * - `--full`: okresowa rekoncyliacja z listy `GET /sale/offers` (niesie stan
 *   i cenę) wg tabeli {@see StockReconciler} — obniżenia wprost, podniesienia po
 *   świeżym potwierdzeniu `/parts` (D-6.2.4). Osobny, rzadszy wpis crona.
 *
 * ## Rzetelność przebiegu (D-6.G2)
 * Lock per środowisko ({@see StockSyncLock}); HTTP 429 przerywa przebieg BEZ
 * przesunięcia kursora (kadencja crona jest naturalnym backoffem — nieprzetworzone
 * zdarzenia wrócą w następnym przebiegu); kursor przesuwa się dopiero po
 * przetworzeniu wszystkich stron. Wewnątrz sekcji z lockiem nie wolno wywołać
 * `WP_CLI::error()` (kończy proces `exit`, omijając `finally` ze zwolnieniem
 * zamka) — błędy fatalne wracają stringiem i są zgłaszane PO zwolnieniu.
 *
 * ## Granice zapisu
 * Pull pisze wyłącznie przez {@see ProductWriter::apply_stock_and_price()}
 * (kosz = wycofanie, D-6.2.1); push wyłącznie przez {@see StockPusher} (ciało =
 * literalnie sam `stock` — gwarancja D-2.G7). Produkty o pochodzeniu innym niż
 * `--environment` są pomijane (D-6.2.2).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class SyncStockCommand {

	use AllegroCliSupport;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy) — {@see AllegroCliSupport::send()}.
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Prefiks opcji kursora `order/events` per środowisko — kontrakt §10.5 (VERBATIM).
	 */
	private const OPTION_CURSOR_PREFIX = 'qutlet_allegro_stock_sync_cursor_';

	/**
	 * Rozmiar strony strumienia zdarzeń `GET /order/events` (jak P-3.3a).
	 */
	private const EVENT_PAGE_LIMIT = 100;

	/**
	 * Rozmiar strony listy `GET /sale/offers` (maksimum API).
	 */
	private const LIST_PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (jak w pozostałych komendach repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Ścieżka partiala stanu/ceny. Parametr `include` MUSI być powtórzony
	 * (nie `include[0]=…` z `http_build_query`) — inaczej HTTP 400 (P-3.1a).
	 */
	private const PARTS_QUERY = '/parts?include=stock&include=price';

	/**
	 * Zapis produktów (znajdowanie po kluczu powiązania + lekki zapis stanu/ceny).
	 *
	 * @var ProductWriter
	 */
	private $writer;

	/**
	 * Push stanu Woo→Allegro (ponowienia zaległych).
	 *
	 * @var StockPusher
	 */
	private $pusher;

	/**
	 * Liczniki przebiegu (podsumowanie + progi wyjścia).
	 *
	 * @var array<string,int>
	 */
	private $counters = array();

	/**
	 * Synchronizuje stany magazynowe (i ceny) między Allegro a WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--environment=<env>]
	 * : Środowisko (`sandbox`/`production`); sloty `read` (pull) + `write` (push) — D-6.G5.
	 * ---
	 * default: sandbox
	 * options:
	 *   - sandbox
	 *   - production
	 * ---
	 *
	 * [--full]
	 * : Rekoncyliacja pełnego katalogu z listy `GET /sale/offers` zamiast
	 *   przyrostu po `order/events` (rzadszy wpis crona; D-6.2.4).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sync-stock
	 *     wp qutlet-allegro sync-stock --environment=sandbox --full
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

		$this->assert_sync_dependencies();

		// Token i baza PRZED lockiem: `access_token()` przy braku kończy proces
		// (`WP_CLI::error` → exit), co ominęłoby `finally` ze zwolnieniem zamka.
		$access = $this->access_token( $environment, Environment::ROLE_READ );
		$api    = Environment::for_environment( $environment )->api_base_url();

		$this->writer   = new ProductWriter();
		$this->pusher   = new StockPusher();
		$this->counters = array(
			'pushed'        => 0,
			'push_failed'   => 0,
			'push_abandoned' => 0,
			'stock_pulled'  => 0,
			'price_pulled'  => 0,
			'trashed'       => 0,
			'foreign'       => 0,
			'unknown'       => 0,
			'push_first'    => 0,
			'errors'        => 0,
		);

		$lock = new StockSyncLock();

		if ( ! $lock->acquire( $environment ) ) {
			// Normalna sytuacja pod cronem (poprzedni przebieg jeszcze trwa) —
			// wyjście sukcesem, bez pracy (D-6.G2: brak nakładania).
			WP_CLI::log( sprintf( 'Inny przebieg sync-stock (%s) trwa — pomijam (lock).', $environment ) );

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
				'sync-stock (%s%s): push %d (nieudane %d, porzucone %d), pull stan %d / cena %d, kosz %d, obce środowisko %d, nieznane oferty %d, zaległy push %d, błędy %d.',
				$environment,
				$full ? ', --full' : '',
				$c['pushed'],
				$c['push_failed'],
				$c['push_abandoned'],
				$c['stock_pulled'],
				$c['price_pulled'],
				$c['trashed'],
				$c['foreign'],
				$c['unknown'],
				$c['push_first'],
				$c['errors']
			)
		);
	}

	/**
	 * Właściwy przebieg — wykonywany POD lockiem, więc bez `WP_CLI::error()`
	 * (patrz docblock klasy); błąd fatalny wraca stringiem.
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @param bool   $full        Tryb rekoncyliacji.
	 * @return string|null Komunikat błędu fatalnego albo null.
	 */
	private function run( string $environment, string $api, string $access, bool $full ): ?string {
		// 1. Zaległe pushe Woo→Allegro — ZAWSZE najpierw (D-6.2.4: pull na
		// produkcie z zaległym pushem nadpisałby sprzedaż sklepu stanem sprzed niej).
		$this->retry_pending_pushes( $environment );

		// 2. Pull: przyrost po zdarzeniach albo pełna rekoncyliacja.
		return $full
			? $this->reconcile( $environment, $api, $access )
			: $this->incremental( $environment, $api, $access );
	}

	/**
	 * Ponawia zaległe pushe (marker D-6.2.3) dla produktów WSKAZANEGO środowiska.
	 *
	 * Marker PORZUCONY (starszy niż {@see StockPusher::PENDING_STALE_SECONDS} —
	 * recenzja P-6.2b) jest czyszczony BEZWARUNKOWO, nawet bez próby pusha: dla
	 * przyczyn trwałych (brak rozpoznawalnego pochodzenia, produkt bez zarządzania
	 * stanem) kolejne ponowienia nigdy by się nie powiodły, a bez tego czyszczenia
	 * D-6.2.4 blokowałoby pull dla tego produktu NA ZAWSZE — cichy wyciek z syncu
	 * bez żadnej ścieżki odzyskania. Log na poziomie `error` (nie `warning`):
	 * to wymaga uwagi człowieka, nie samo się naprawi kolejnym przebiegiem.
	 *
	 * @param string $environment Środowisko przebiegu.
	 * @return void
	 */
	private function retry_pending_pushes( string $environment ): void {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => ProductWriter::LINK_LOOKUP_STATUSES,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => StockPusher::META_PUSH_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- marker operacyjny syncu (kontrakt §10.5); zaległych pushy są sztuki, nie tysiące.
				'meta_compare'   => 'EXISTS',
			)
		);

		foreach ( $ids as $product_id ) {
			$product_id = (int) $product_id;

			// Kosz = wycofany (D-6.2.1): push bezprzedmiotowy, marker do kasacji.
			if ( 'trash' === get_post_status( $product_id ) ) {
				++$this->counters['trashed'];
				StockPusher::clear_pending( $product_id );
				WP_CLI::log( sprintf( '  produkt %d w koszu (wycofany, D-6.2.1) — zaległy push anulowany.', $product_id ) );

				continue;
			}

			$provenance = OfferMapper::environment_from_offer_url(
				(string) get_post_meta( $product_id, 'allegro_url', true )
			);

			// Obce/nierozpoznane pochodzenie: znane inne środowisko domknie je
			// samo (D-6.2.2) — marker zostaje BEZ oznaczania jako porzucony. Ale
			// nierozpoznane (`null`) NIGDY nie dopasuje się do żadnego środowiska,
			// więc PORZUCENIE musi rozstrzygnąć TEN przebieg, inaczej nie zrobi
			// tego nikt.
			if ( $provenance !== $environment ) {
				if ( null === $provenance && StockPusher::is_abandoned_pending( $product_id ) ) {
					++$this->counters['push_abandoned'];
					StockPusher::clear_pending( $product_id );
					WP_CLI::warning( sprintf( 'Produkt %d: zaległy push porzucony (brak rozpoznawalnego pochodzenia, marker starszy niż próg) — WYMAGA ręcznej interwencji; marker wyczyszczony.', $product_id ) );

					continue;
				}

				++$this->counters['foreign'];

				continue;
			}

			$result = $this->pusher->push( $product_id );

			if ( $result['ok'] ) {
				++$this->counters['pushed'];
				WP_CLI::log( sprintf( '  produkt %d: zaległy push domknięty (stan %d).', $product_id, (int) $result['pushed_stock'] ) );

				continue;
			}

			if ( StockPusher::is_abandoned_pending( $product_id ) ) {
				++$this->counters['push_abandoned'];
				StockPusher::clear_pending( $product_id );
				WP_CLI::warning( sprintf( 'Produkt %d: zaległy push porzucony (%s; marker starszy niż próg) — WYMAGA ręcznej interwencji; marker wyczyszczony.', $product_id, $result['detail'] ) );

				continue;
			}

			++$this->counters['push_failed'];
			WP_CLI::warning( sprintf( 'Produkt %d: ponowienie pusha nieudane (%s) — marker zostaje.', $product_id, $result['detail'] ) );
		}
	}

	/**
	 * Pull przyrostowy: zdarzenia zamówień Allegro od kursora → świeży `/parts`
	 * dla ofert, których dotyczyły. Kursor przesuwa się DOPIERO po przetworzeniu
	 * całości (przerwany przebieg powtórzy pracę — pull jest idempotentny).
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function incremental( string $environment, string $api, string $access ): ?string {
		$option = self::OPTION_CURSOR_PREFIX . $environment;
		$cursor = (string) get_option( $option, '' );

		if ( '' === $cursor ) {
			return $this->seed_cursor( $environment, $api, $access );
		}

		$offer_ids = array();
		$last_id   = '';
		$from      = $cursor;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$resp = $this->get(
				$api . '/order/events?' . http_build_query(
					array(
						'limit' => self::EVENT_PAGE_LIMIT,
						'from'  => $from,
					)
				),
				$access
			);

			if ( 429 === $resp['status'] ) {
				WP_CLI::warning( 'HTTP 429 na order/events — przerywam przebieg bez przesuwania kursora (backoff = kadencja crona, D-6.G2).' );

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

			foreach ( self::offer_ids_from_events( $events ) as $offer_id ) {
				$offer_ids[ $offer_id ] = true;
			}

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

		if ( array() === $offer_ids ) {
			WP_CLI::log( 'Brak nowych zdarzeń — stany bez zmian.' );

			return null;
		}

		WP_CLI::log( sprintf( 'Zdarzenia wskazują %d ofert do odświeżenia.', count( $offer_ids ) ) );

		foreach ( array_keys( $offer_ids ) as $offer_id ) {
			$status = $this->pull_offer( $environment, $api, $access, (string) $offer_id );

			if ( 'rate-limited' === $status ) {
				WP_CLI::warning( 'HTTP 429 na /parts — przerywam przebieg bez przesuwania kursora (D-6.G2).' );

				return null;
			}
		}

		update_option( $option, $last_id, false );

		return null;
	}

	/**
	 * Pierwsze uruchomienie: bez kursora nie wiadomo, które zdarzenia są „nowe" —
	 * NIE przetwarzamy historii, tylko ustawiamy kursor na koniec strumienia.
	 * Zaległości względem historii wyrównuje `--full` (rekoncyliacja).
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function seed_cursor( string $environment, string $api, string $access ): ?string {
		$last_id = '';
		$from    = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$query = array( 'limit' => self::EVENT_PAGE_LIMIT );

			if ( '' !== $from ) {
				$query['from'] = $from;
			}

			$resp = $this->get( $api . '/order/events?' . http_build_query( $query ), $access );

			if ( 429 === $resp['status'] ) {
				WP_CLI::warning( 'HTTP 429 przy inicjalizacji kursora — następny przebieg ponowi.' );

				return null;
			}

			if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
				return sprintf( 'GET /order/events (inicjalizacja kursora) → HTTP %d %s.', $resp['status'], $this->error_detail( $resp ) );
			}

			$events = isset( $resp['data']['events'] ) && is_array( $resp['data']['events'] )
				? array_values( $resp['data']['events'] )
				: array();

			if ( array() === $events ) {
				break;
			}

			$page_last = self::last_event_id( $events );

			if ( '' === $page_last ) {
				break;
			}

			$last_id = $page_last;
			$from    = $page_last;

			if ( count( $events ) < self::EVENT_PAGE_LIMIT ) {
				break;
			}
		}

		if ( '' === $last_id ) {
			WP_CLI::log( 'Strumień zdarzeń pusty — kursor zainicjuje się przy pierwszym zdarzeniu.' );

			return null;
		}

		update_option( self::OPTION_CURSOR_PREFIX . $environment, $last_id, false );
		WP_CLI::log(
			sprintf(
				'Kursor zdarzeń zainicjowany na %s — historia sprzed inicjalizacji pominięta; stan wyrówna `sync-stock --full`.',
				$last_id
			)
		);

		return null;
	}

	/**
	 * Odświeża stan + cenę pojedynczej oferty świeżym `GET .../parts`.
	 *
	 * @param string $environment Środowisko przebiegu.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @param string $offer_id    Id oferty.
	 * @return string `ok` / `skip` / `rate-limited`.
	 */
	private function pull_offer( string $environment, string $api, string $access, string $offer_id ): string {
		$warnings   = array();
		$product_id = $this->writer->find_product_id( $offer_id, $warnings );

		foreach ( $warnings as $warning ) {
			WP_CLI::warning( sprintf( 'Oferta %s: %s', $offer_id, $warning ) );
		}

		if ( null === $product_id ) {
			// Oferta bez produktu — sync stanów NIE importuje (od tego jest
			// import-offers); liczymy i logujemy, kurator zdecyduje.
			++$this->counters['unknown'];
			WP_CLI::log( sprintf( '  oferta %s bez produktu w Woo — pomijam (import robi import-offers).', $offer_id ) );

			return 'skip';
		}

		if ( 'trash' === get_post_status( $product_id ) ) {
			++$this->counters['trashed'];
			WP_CLI::log( sprintf( '  oferta %s → produkt %d w koszu (wycofany, D-6.2.1) — pomijam.', $offer_id, $product_id ) );

			return 'skip';
		}

		$provenance = OfferMapper::environment_from_offer_url(
			(string) get_post_meta( $product_id, 'allegro_url', true )
		);

		if ( $provenance !== $environment ) {
			++$this->counters['foreign'];
			WP_CLI::log( sprintf( '  oferta %s → produkt %d pochodzi z „%s", przebieg działa na „%s" — pomijam (D-6.2.2).', $offer_id, $product_id, (string) $provenance, $environment ) );

			return 'skip';
		}

		if ( StockPusher::is_pending( $product_id ) ) {
			// Push nadal zaległy (ponowienie w kroku 1 padło) — pull wstrzymany
			// (D-6.2.4), inaczej nadpisalibyśmy sprzedaż sklepu.
			++$this->counters['push_first'];
			WP_CLI::log( sprintf( '  oferta %s → produkt %d z zaległym pushem — pull wstrzymany do domknięcia pusha.', $offer_id, $product_id ) );

			return 'skip';
		}

		$resp = $this->get( $api . '/sale/product-offers/' . rawurlencode( $offer_id ) . self::PARTS_QUERY, $access );

		if ( 429 === $resp['status'] ) {
			return 'rate-limited';
		}

		if ( 404 === $resp['status'] ) {
			++$this->counters['unknown'];
			WP_CLI::log( sprintf( '  oferta %s nie istnieje już w Allegro (404) — pomijam.', $offer_id ) );

			return 'skip';
		}

		if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
			++$this->counters['errors'];
			WP_CLI::warning( sprintf( 'Oferta %s: /parts → HTTP %d %s', $offer_id, $resp['status'], $this->error_detail( $resp ) ) );

			return 'skip';
		}

		$this->apply_pull( $offer_id, $product_id, $resp['data'] );

		return 'ok';
	}

	/**
	 * Rekoncyliacja pełnego katalogu z listy `GET /sale/offers` (tryb `--full`).
	 * Lista niesie stan i cenę; kierunki wg {@see StockReconciler} (D-6.2.4).
	 *
	 * @param string $environment Środowisko.
	 * @param string $api         Baza REST API.
	 * @param string $access      Access token slotu `read`.
	 * @return string|null Błąd fatalny albo null.
	 */
	private function reconcile( string $environment, string $api, string $access ): ?string {
		$offset = 0;
		$total  = null;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$resp = $this->get(
				$api . '/sale/offers?' . http_build_query(
					array(
						'limit'  => self::LIST_PAGE_LIMIT,
						'offset' => $offset,
					)
				),
				$access
			);

			if ( 429 === $resp['status'] ) {
				WP_CLI::warning( 'HTTP 429 na liście ofert — przerywam rekoncyliację (dokończy następny przebieg --full).' );

				return null;
			}

			if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
				return sprintf( 'GET /sale/offers (offset=%d) → HTTP %d %s — rekoncyliacja przerwana.', $offset, $resp['status'], $this->error_detail( $resp ) );
			}

			$offers = isset( $resp['data']['offers'] ) && is_array( $resp['data']['offers'] )
				? array_values( $resp['data']['offers'] )
				: array();

			if ( null === $total && isset( $resp['data']['totalCount'] ) ) {
				$total = (int) $resp['data']['totalCount'];
			}

			if ( array() === $offers ) {
				break;
			}

			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) || ! isset( $offer['id'] ) ) {
					continue;
				}

				$status = $this->reconcile_offer( $environment, $api, $access, $offer );

				if ( 'rate-limited' === $status ) {
					WP_CLI::warning( 'HTTP 429 przy potwierdzeniu /parts — przerywam rekoncyliację.' );

					return null;
				}
			}

			$offset += count( $offers );

			if ( null !== $total && $offset >= $total ) {
				break;
			}

			if ( self::MAX_PAGES - 1 === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano rekoncyliację na bezpieczniku %d stron (offset=%d).', self::MAX_PAGES, $offset ) );
			}
		}

		return null;
	}

	/**
	 * Rekoncyliacja pojedynczej oferty z wpisu LISTY (stan + cena z listy;
	 * podniesienie stanu wymaga świeżego `/parts` — D-6.2.4).
	 *
	 * @param string              $environment Środowisko przebiegu.
	 * @param string              $api         Baza REST API.
	 * @param string              $access      Access token slotu `read`.
	 * @param array<string,mixed> $offer       Wpis listy `GET /sale/offers`.
	 * @return string `ok` / `skip` / `rate-limited`.
	 */
	private function reconcile_offer( string $environment, string $api, string $access, array $offer ): string {
		$offer_id   = (string) $offer['id'];
		$warnings   = array();
		$product_id = $this->writer->find_product_id( $offer_id, $warnings );

		foreach ( $warnings as $warning ) {
			WP_CLI::warning( sprintf( 'Oferta %s: %s', $offer_id, $warning ) );
		}

		if ( null === $product_id ) {
			++$this->counters['unknown'];

			return 'skip';
		}

		if ( 'trash' === get_post_status( $product_id ) ) {
			++$this->counters['trashed'];
			WP_CLI::log( sprintf( '  oferta %s → produkt %d w koszu (wycofany, D-6.2.1) — pomijam.', $offer_id, $product_id ) );

			return 'skip';
		}

		$provenance = OfferMapper::environment_from_offer_url(
			(string) get_post_meta( $product_id, 'allegro_url', true )
		);

		if ( $provenance !== $environment ) {
			++$this->counters['foreign'];

			return 'skip';
		}

		if ( StockPusher::is_pending( $product_id ) ) {
			++$this->counters['push_first'];

			return 'skip';
		}

		$allegro_stock = $offer['stock']['available'] ?? null;

		if ( ! is_int( $allegro_stock ) ) {
			// Wpis listy bez stanu (kształt nieobserwowany) — nie zgadujemy;
			// cena i tak przejdzie przez apply (porównanie wewnątrz).
			$allegro_stock = null;
		}

		$product   = wc_get_product( $product_id );
		$woo_stock = $product instanceof WC_Product ? $product->get_stock_quantity() : null;

		$action = null !== $allegro_stock
			? StockReconciler::pull_action( $allegro_stock, $woo_stock, false )
			: StockReconciler::NOOP;

		if ( StockReconciler::CONFIRM_INCREASE === $action ) {
			// Podniesienie stanu w Woo — tylko po świeżym potwierdzeniu (D-6.2.4):
			// lista mogła powstać przed chwilowym pushem sprzedaży ze sklepu.
			$fresh = $this->get( $api . '/sale/product-offers/' . rawurlencode( $offer_id ) . self::PARTS_QUERY, $access );

			if ( 429 === $fresh['status'] ) {
				return 'rate-limited';
			}

			if ( 200 !== $fresh['status'] || ! is_array( $fresh['data'] ) ) {
				++$this->counters['errors'];
				WP_CLI::warning( sprintf( 'Oferta %s: potwierdzenie /parts → HTTP %d %s — podniesienie stanu odroczone.', $offer_id, $fresh['status'], $this->error_detail( $fresh ) ) );

				return 'skip';
			}

			$this->apply_pull( $offer_id, $product_id, $fresh['data'] );

			return 'ok';
		}

		// APPLY (obniżenie/inicjalizacja) i NOOP: stan wprost z listy (przy NOOP
		// null — bez dotykania stanu); cena zawsze przez porównanie w writerze.
		$stock_to_apply = StockReconciler::APPLY === $action ? $allegro_stock : null;
		$price          = OfferMapper::price_amount( $offer );

		$result = $this->writer->apply_stock_and_price( $product_id, $stock_to_apply, $price );

		$this->note_apply_result( $offer_id, $result );

		return 'ok';
	}

	/**
	 * Zapisuje stan + cenę ze świeżej zwrotki `/parts` (kształt pól tożsamy z
	 * pełną ofertą — `stock.available`, `sellingMode.price.amount`; mapping §5).
	 *
	 * @param string              $offer_id   Id oferty (log).
	 * @param int                 $product_id Id produktu.
	 * @param array<string,mixed> $parts      Zdekodowana zwrotka `/parts`.
	 * @return void
	 */
	private function apply_pull( string $offer_id, int $product_id, array $parts ): void {
		$result = $this->writer->apply_stock_and_price(
			$product_id,
			OfferMapper::stock_quantity( $parts ),
			OfferMapper::price_amount( $parts )
		);

		$this->note_apply_result( $offer_id, $result );
	}

	/**
	 * Wspólne księgowanie wyniku zapisu pull (liczniki + ostrzeżenia).
	 *
	 * @param string                                                                $offer_id Id oferty.
	 * @param array{stock_updated:bool,price_updated:bool,warnings:array<int,string>} $result Wynik {@see ProductWriter::apply_stock_and_price()}.
	 * @return void
	 */
	private function note_apply_result( string $offer_id, array $result ): void {
		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( sprintf( 'Oferta %s: %s', $offer_id, $warning ) );
		}

		if ( $result['stock_updated'] ) {
			++$this->counters['stock_pulled'];
		}

		if ( $result['price_updated'] ) {
			++$this->counters['price_pulled'];
		}
	}

	/**
	 * Id ofert, których dotyczą zdarzenia zamówień (`events[].order.lineItems[].offer.id`).
	 * Świadomie WSZYSTKIE typy zdarzeń: każde sygnalizuje „stan tej oferty mógł
	 * się zmienić", a prawdę i tak ustala świeży `/parts` — nadmiarowe odświeżenie
	 * jest tanie i odporne na nowe typy zdarzeń.
	 *
	 * Czysta funkcja statyczna — testowana PHPUnitem na kształcie realnej próbki.
	 *
	 * @param array<int,mixed> $events Zdarzenia z `GET /order/events`.
	 * @return array<int,string> Unikalne id ofert (kolejność pierwszego wystąpienia).
	 */
	public static function offer_ids_from_events( array $events ): array {
		$ids = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$line_items = $event['order']['lineItems'] ?? null;

			if ( ! is_array( $line_items ) ) {
				continue;
			}

			foreach ( $line_items as $item ) {
				$offer_id = is_array( $item ) ? ( $item['offer']['id'] ?? null ) : null;

				if ( ( is_string( $offer_id ) || is_int( $offer_id ) ) && '' !== (string) $offer_id ) {
					// Klucz tablicy: PHP rzutuje numeryczny STRING na int (id ofert
					// są numeryczne), więc `array_keys()` zwróciłby inty łamiąc
					// `strict_types` w dalszych wywołaniach oczekujących stringa —
					// dedup przez wartość samego stringa w tablicy, nie przez klucz.
					$ids[ (string) $offer_id ] = (string) $offer_id;
				}
			}
		}

		return array_values( $ids );
	}

	/**
	 * Id OSTATNIEGO zdarzenia strony (kursor). Czysta funkcja statyczna (testy).
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

	/**
	 * Twarde zależności syncu — jasny komunikat zamiast fatala w połowie zapisu
	 * (jak `ImportOffersCommand`): stawka rabatu z core P-6.1a (przeliczenie
	 * `_price`) i ACF (`update_field` dla `cena_allegro`).
	 *
	 * @return void
	 */
	private function assert_sync_dependencies(): void {
		if ( ! class_exists( '\Qutlet\Core\Pricing\DiscountRate' ) ) {
			WP_CLI::error( 'Brak Qutlet\Core\Pricing\DiscountRate — zaktualizuj qutlet-core do wersji z P-6.1a (stawka rabatu).' );
		}

		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'Brak funkcji update_field() — sync ceny wymaga aktywnego ACF (pola kanału Allegro rejestruje qutlet-core).' );
		}
	}
}
