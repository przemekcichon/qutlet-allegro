<?php
/**
 * Slice SandboxSeed — komenda WP-CLI zasiewająca sandbox ze snapshotu (P-3A.2).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\SandboxSeed;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Auth\TokenRefresher;
use InvalidArgumentException;
use RuntimeException;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Odtwarza w sandboxie asortyment ze snapshotu produkcji (P-3A.1a). Sandbox Allegro startuje
 * pusty i nie ma oficjalnego sposobu przeniesienia do niego ofert, a raz na kwartał kasuje
 * WSZYSTKIE oferty — więc zasiew jest czynnością cykliczną, nie akcją „raz a dobrze"
 * (D-3A.G1). Kierunek jest jednostronny: produkcja → snapshot → sandbox, nigdy odwrotnie
 * (D-3A.G2).
 *
 * ## Bezpiecznik (D-2.G7 / D-3A.G2)
 * Środowisko jest jawną flagą (`--environment`, domyślnie sandbox), a NIE wartością wpisaną
 * na sztywno — dzięki temu odmowa jest realna, nie dekoracyjna: literówka
 * `--environment=production` uderza w {@see Environment::assert_offer_content_write_allowed()}
 * i kończy się wyjątkiem PRZED pobraniem tokenu i przed jakimkolwiek żądaniem. Gdyby
 * środowisko było stałą w kodzie, bezpiecznik nie miałby czego bronić.
 *
 * ## Kształt oferty (decyzje użytkownika, sesja 2026-07-22, na podstawie pomiaru
 * `sandbox-preflight`)
 * - **Oferta kategoryjna, nie produktowa.** Wszystkie 555 ofert snapshotu jest produktowych,
 *   ale żaden z 495 identyfikatorów katalogu (`productSet[].product.id`) nie istnieje w
 *   sandboxie (404 `ProductNotFound` w 60/60 próbie). `productSet` więc odpada, a parametry
 *   Z PRODUKTU schodzą na poziom oferty — snapshot je ma (Marka, Model, Kod producenta, EAN),
 *   co pokrywa wymagania sandboxa dla 548/555 ofert.
 * - **Mapowanie prod→sandbox przez {@see IdMap}** (D-3A.G5). Pomiar pokazał dziś tożsamość
 *   (126/126 kategorii), ale warstwa zostaje, bo Allegro odświeża listę kwartalnie. Brak wpisu
 *   = brak mapowania: kategoria bez wpisu pomija ofertę, parametr bez wpisu wypada z payloadu.
 *   Żadnego cichego „pewnie to samo".
 * - **Zasoby konta bierzemy z sandboxa, nie z produkcji.** Produkcyjne UUID-y (98 sztuk:
 *   cenniki, polityki zwrotów, gwarancje, producenci odpowiedzialni) nie istnieją w sandboxie
 *   ani razu, więc `delivery.shippingRates` dostaje cennik KONTA SANDBOXOWEGO, a
 *   `responsibleProducer` wypada z payloadu.
 * - **`afterSalesServices` zakładamy sami.** Pierwotny plan mówił „pomiń jako opcjonalne";
 *   żywe API to obaliło (422 `ReturnPolicyNotDefinedException` +
 *   `ImpliedWarrantyNotDefinedException`), więc — decyzją użytkownika i zgodnie z D-2.G6, które
 *   dało roli `write` scope `sale:settings:write` „wyłącznie do zasiewu sandboxa" — komenda
 *   zakłada brakujące warunki na koncie sandboxowym (idempotentnie) i podstawia ich id.
 * - **Parametry sekcji produktu odpadają.** Schemat kategorii znaczy je
 *   `options.describesProduct`, a Allegro odrzuca ofertę, która wysyła je w `parameters`
 *   (422 `ParameterCategoryException`) — w ofercie kategoryjnej nie mają gdzie usiąść.
 *
 * ## Idempotencja i zdjęcia (D-3A.G1 + D-3A.G4)
 * Stanem sterującym jest SANDBOX, nie plik lokalny — bo to sandbox jest czyszczony kwartalnie,
 * więc każdy lokalny rejestr „co wysłaliśmy" rozjechałby się z rzeczywistością. Powiązanie
 * oferta produkcyjna ↔ oferta sandboxowa niesie pole `external.id` = produkcyjne `offerId`;
 * przed przebiegiem budujemy indeks z `GET /sale/offers`.
 *
 * Warunkiem pominięcia jest **KOMPLETNOŚĆ oferty, nie samo jej istnienie** (D-3A.G4, wprost
 * ostrzeżone w planie). Zdjęcia w sandboxie znikają po 7 dniach NIEZALEŻNIE od ofert, więc
 * reguła „oferta jest → pomiń" po tygodniu zostawiłaby komplet ofert bez obrazków i żaden
 * kolejny zasiew by tego nie naprawił. Dlatego dla istniejącej oferty sprawdzamy, czy jej
 * zdjęcie realnie się serwuje (żądanie po `primaryImage.url`), a gdy nie — wypychamy zdjęcia
 * ponownie (`PATCH`), zamiast pomijać.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class SandboxSeedCommand {

	/**
	 * Nagłówek `Accept`/`Content-Type` wymagany przez Allegro REST API.
	 */
	private const MEDIA_TYPE = 'application/vnd.allegro.public.v1+json';

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 45;

	/**
	 * Rozmiar strony przy budowaniu indeksu ofert sandboxa.
	 */
	private const INDEX_PAGE_SIZE = 100;

	/**
	 * Bezpiecznik pętli paginacji indeksu.
	 */
	private const MAX_INDEX_PAGES = 200;

	/**
	 * Zasiewa sandbox ofertami odtworzonymi ze snapshotu produkcji.
	 *
	 * ## OPTIONS
	 *
	 * --snapshot=<dir>
	 * : Katalog snapshotu z P-3A.1a (podkatalog `offers/` z plikami `<offerId>.json`).
	 *
	 * --cache=<dir>
	 * : Katalog na schematy parametrów kategorii i raport przebiegu. Ten sam, którego używa
	 *   `sandbox-preflight` — brakujące schematy komenda dociągnie sama.
	 *
	 * [--id-map=<file>]
	 * : Tablica mapowania prod→sandbox (D-3A.G5). Domyślnie plik obok kodu slice'a.
	 *   Regeneruje ją `sandbox-preflight --write-id-map`.
	 *
	 * [--environment=<env>]
	 * : Środowisko docelowe. `production` jest ODRZUCANE przez bezpiecznik D-2.G7 — flaga
	 *   istnieje po to, żeby ta odmowa była realna i testowalna.
	 * ---
	 * default: sandbox
	 * ---
	 *
	 * [--limit=<n>]
	 * : Ile ofert przetworzyć w tym przebiegu (0 = wszystkie). Przebieg jest wznawialny, więc
	 *   dzielenie na porcje niczego nie psuje.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--offer=<offerId>]
	 * : Przetwórz WYŁĄCZNIE tę jedną ofertę produkcyjną (do prób na żywym API).
	 *
	 * [--shipping-rate=<uuid>]
	 * : Cennik dostawy konta sandboxowego. Domyślnie pierwszy z `GET /sale/shipping-rates`.
	 *
	 * [--publication=<status>]
	 * : Status publikacji tworzonej oferty (`ACTIVE` albo `INACTIVE`).
	 * ---
	 * default: ACTIVE
	 * ---
	 *
	 * [--refresh-token]
	 * : Wymuś rotację tokenu slotu PRZED przebiegiem. Potrzebne, gdy zmienił się stan KONTA
	 *   po stronie Allegro (np. przemianowanie na konto firmowe): stary access token żyje 12 h,
	 *   więc bez rotacji komenda dalej chodziłaby ze starym kontekstem konta.
	 *
	 * [--refresh-images]
	 * : Wypchnij zdjęcia ponownie także wtedy, gdy wyglądają na obecne.
	 *
	 * [--dry-run]
	 * : Zbuduj payloady i pokaż, co by się stało — bez jednego żądania zapisu.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro seed-sandbox --snapshot=C:/…/docs/allegro-snapshot-offers --cache=C:/…/preflight --dry-run
	 *     wp qutlet-allegro seed-sandbox --snapshot=C:/…/docs/allegro-snapshot-offers --cache=C:/…/preflight --offer=17752734522
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$snapshot = $this->require_dir_flag( $assoc_args, 'snapshot' );
		$cache    = $this->require_dir_flag( $assoc_args, 'cache' );
		$dry_run  = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$refresh  = (bool) get_flag_value( $assoc_args, 'refresh-images', false );
		$limit    = max( 0, (int) get_flag_value( $assoc_args, 'limit', '0' ) );
		$only     = (string) get_flag_value( $assoc_args, 'offer', '' );
		$status   = strtoupper( (string) get_flag_value( $assoc_args, 'publication', 'ACTIVE' ) );

		/*
		 * BEZPIECZNIK D-2.G7 PIERWSZY — przed tokenem, przed wczytaniem snapshotu, przed
		 * czymkolwiek. Odmowa nie ma prawa nastąpić po połowie roboty.
		 */
		$environment = $this->target_environment( (string) get_flag_value( $assoc_args, 'environment', Environment::SANDBOX ) );

		try {
			$id_map = IdMap::from_file( $this->id_map_path( $assoc_args ) );
		} catch ( RuntimeException $error ) {
			// `error( …, false )` + `halt()` zamiast samego `error()`: wypisuje i KOŃCZY w sposób
			// widoczny dla analizy statycznej, więc dalszy kod nie udaje, że mapa jednak jest.
			WP_CLI::error( $error->getMessage(), false );
			WP_CLI::halt( 1 );
		}

		if ( ! is_dir( $snapshot . '/offers' ) ) {
			WP_CLI::error( sprintf( 'Nie widzę katalogu ofert snapshotu: %s/offers', $snapshot ) );
		}

		if ( ! wp_mkdir_p( $cache . '/category-parameters' ) ) {
			WP_CLI::error( sprintf( 'Nie mogę utworzyć katalogu cache: %s/category-parameters', $cache ) );
		}

		$api    = $environment->api_base_url();
		$access = $this->access_token( $environment->type(), (bool) get_flag_value( $assoc_args, 'refresh-token', false ) );

		$shipping_rate = $this->resolve_shipping_rate( $api, $access, (string) get_flag_value( $assoc_args, 'shipping-rate', '' ) );
		$after_sales   = $this->ensure_after_sales( $api, $access, $dry_run );
		$index         = $this->build_offer_index( $api, $access );

		WP_CLI::log(
			sprintf(
				'Sandbox: %d ofert z external.id w indeksie. Cennik dostawy: %s. Mapa: %s.',
				count( $index ),
				$shipping_rate,
				wp_json_encode( $id_map->sizes() )
			)
		);

		$files = glob( $snapshot . '/offers/*.json' );

		if ( false === $files || array() === $files ) {
			WP_CLI::error( sprintf( 'Katalog %s/offers nie zawiera żadnej oferty.', $snapshot ) );
		}

		sort( $files );

		$records = array();
		$totals  = array(
			'created'          => 0,
			'images_refreshed' => 0,
			'complete'         => 0,
			'skipped'          => 0,
			'failed'           => 0,
		);
		$done    = 0;

		foreach ( $files as $file ) {
			$offer = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalny plik snapshotu poza uploads.

			if ( ! is_array( $offer ) || ! isset( $offer['id'] ) ) {
				WP_CLI::warning( sprintf( 'Pomijam nieparsowalny plik snapshotu: %s', $file ) );

				continue;
			}

			$offer_id = (string) $offer['id'];

			if ( '' !== $only && $only !== $offer_id ) {
				continue;
			}

			if ( $limit > 0 && $done >= $limit ) {
				break;
			}

			++$done;

			$existing = $index[ $offer_id ] ?? null;

			if ( null !== $existing ) {
				$record = $this->reconcile_existing( $api, $access, $offer, $existing, $refresh, $dry_run );
			} else {
				$record = $this->create_offer( $api, $access, $cache, $offer, $id_map, $shipping_rate, $after_sales, $status, $dry_run );
			}

			++$totals[ $this->bucket_of( (string) $record['action'] ) ];

			$records[ $offer_id ] = $record;

			WP_CLI::log( sprintf( '  [%d] %s → %s%s', $done, $offer_id, $record['action'], isset( $record['detail'] ) ? ' (' . $record['detail'] . ')' : '' ) );
		}

		$report = array(
			'point'         => 'P-3A.2',
			'environment'   => $environment->type(),
			'api_base'      => $api,
			'generated_at'  => gmdate( 'c' ),
			'dry_run'       => $dry_run,
			'shipping_rate' => $shipping_rate,
			'publication'   => $status,
			'id_map'        => $id_map->sizes(),
			'totals'        => $totals,
			'offers'        => $records,
		);

		$this->write(
			$cache . '/seed-report.json',
			(string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		WP_CLI::success(
			sprintf(
				'%s: utworzone %d, odświeżone zdjęcia %d, kompletne %d, pominięte %d, błędy %d. Raport: %s/seed-report.json',
				$dry_run ? 'PRÓBA (dry-run)' : 'Zasiew',
				$totals['created'],
				$totals['images_refreshed'],
				$totals['complete'],
				$totals['skipped'],
				$totals['failed'],
				$cache
			)
		);
	}

	/**
	 * Zapewnia obecność warunków zwrotów i reklamacji na koncie sandboxowym i zwraca ich id.
	 *
	 * Pierwotna decyzja („pomijamy słowniki konta") padła przy pierwszym realnym POST-cie: Allegro
	 * odpowiada 422 `ReturnPolicyNotDefinedException` + `ImpliedWarrantyNotDefinedException`
	 * („You do not have any Returns/Complaints Terms"), więc `afterSalesServices` NIE jest
	 * opcjonalne, a konto sandboxowe startuje bez jakichkolwiek warunków. Decyzja użytkownika
	 * (sesja 2026-07-22): zasiew zakłada je sam — zgodnie z D-2.G6, które dało roli `write` scope
	 * `sale:settings:write` właśnie „wyłącznie do zasiewu sandboxa".
	 *
	 * Idempotentnie: cokolwiek już na koncie jest, tego używamy; POST leci tylko przy pustej
	 * liście. Adres jest SYNTETYCZNY — sandbox jest środowiskiem testowym, więc nie ma powodu
	 * przenosić tam prawdziwego adresu sprzedawcy tylko po to, żeby wypełnić wymagane pole.
	 *
	 * @param string $api     Baza API.
	 * @param string $access  Access token.
	 * @param bool   $dry_run Czy tylko sprawdzić stan, bez zakładania.
	 * @return array{returnPolicy:array{id:string},impliedWarranty:array{id:string}}|null
	 */
	private function ensure_after_sales( string $api, string $access, bool $dry_run ): ?array {
		$address = array(
			'name'        => 'Qutlet Sandbox',
			'street'      => 'Testowa 1',
			'postCode'    => '32-091',
			'city'        => 'Wilczkowice',
			'countryCode' => 'PL',
		);

		$return_policy = $this->first_existing( $api, $access, '/after-sales-service-conditions/return-policies', 'returnPolicies' );

		if ( null === $return_policy ) {
			if ( $dry_run ) {
				WP_CLI::log( '  (dry-run) konto sandboxowe nie ma warunków zwrotów — przebieg właściwy by je założył.' );

				return null;
			}

			$return_policy = $this->create_account_condition(
				$api,
				$access,
				'/after-sales-service-conditions/return-policies',
				array(
					'name'             => 'Qutlet sandbox seed',
					'isFulfillment'    => false,
					'availability'     => array( 'range' => 'FULL' ),
					'withdrawalPeriod' => 'P14D',
					'returnCost'       => array( 'coveredBy' => 'BUYER' ),
					'address'          => $address,
					'options'          => array(
						'cashOnDeliveryNotAllowed'        => false,
						'refundLoweredByReceivedDiscount' => false,
						'businessReturnAllowed'           => true,
						'collectBySellerOnly'             => false,
						'freeAccessoriesReturnRequired'   => false,
					),
				)
			);
		}

		$implied_warranty = $this->first_existing( $api, $access, '/after-sales-service-conditions/implied-warranties', 'impliedWarranties' );

		if ( null === $implied_warranty ) {
			if ( $dry_run ) {
				WP_CLI::log( '  (dry-run) konto sandboxowe nie ma warunków reklamacji — przebieg właściwy by je założył.' );

				return null;
			}

			$implied_warranty = $this->create_account_condition(
				$api,
				$access,
				'/after-sales-service-conditions/implied-warranties',
				array(
					'name'        => 'Qutlet sandbox seed',
					// Oba okresy P2Y: Allegro odrzuca krótsze („The minimum time to file a
					// complaint is two years" — 422 na `corporate.period` przy P1Y).
					'individual'  => array( 'period' => 'P2Y' ),
					'corporate'   => array( 'period' => 'P2Y' ),
					'address'     => $address,
					'description' => 'Warunki reklamacji założone automatycznie przez zasiew sandboxa (P-3A.2).',
				)
			);
		}

		WP_CLI::log( sprintf( 'Warunki konta: zwroty %s, reklamacje %s.', $return_policy, $implied_warranty ) );

		return array(
			'returnPolicy'     => array( 'id' => $return_policy ),
			'impliedWarranty'  => array( 'id' => $implied_warranty ),
		);
	}

	/**
	 * Zwraca id pierwszego istniejącego warunku danego typu albo `null`, gdy konto nie ma żadnego.
	 *
	 * @param string $api        Baza API.
	 * @param string $access     Access token.
	 * @param string $path       Ścieżka zasobu.
	 * @param string $collection Nazwa kolekcji w zwrotce.
	 * @return string|null
	 */
	private function first_existing( string $api, string $access, string $path, string $collection ): ?string {
		$response = $this->send( 'GET', $api . $path, $access, null );

		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
			WP_CLI::error( sprintf( 'GET %s zwróciło HTTP %d %s.', $path, $response['status'], $this->error_detail( $response ) ) );
		}

		foreach ( (array) ( $response['data'][ $collection ] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'] ) ) {
				continue;
			}

			/*
			 * Zwrotka niesie `seller.id` — jedyny dostępny w naszych scope'ach sposób, żeby
			 * powiedzieć, NA JAKIM koncie siedzą tokeny (`GET /me` wymaga `profile:read`).
			 * Przy sporze „które konto przemianowano" to jest rozstrzygający fakt.
			 */
			if ( isset( $entry['seller']['id'] ) ) {
				WP_CLI::log( sprintf( 'Konto tokenu (seller.id): %s', (string) $entry['seller']['id'] ) );
			}

			return (string) $entry['id'];
		}

		return null;
	}

	/**
	 * Zakłada warunek na koncie sandboxowym i zwraca jego id.
	 *
	 * @param string              $api     Baza API.
	 * @param string              $access  Access token.
	 * @param string              $path    Ścieżka zasobu.
	 * @param array<string,mixed> $payload Ciało żądania (kształt ze specyfikacji Allegro).
	 * @return string
	 */
	private function create_account_condition( string $api, string $access, string $path, array $payload ): string {
		$response = $this->send( 'POST', $api . $path, $access, $payload );

		if ( 201 !== $response['status'] && 200 !== $response['status'] ) {
			WP_CLI::error(
				sprintf( 'POST %s zwróciło HTTP %d %s.', $path, $response['status'], $this->error_detail( $response ) )
			);
		}

		if ( ! isset( $response['data']['id'] ) ) {
			WP_CLI::error( sprintf( 'POST %s nie zwróciło id utworzonego warunku.', $path ) );
		}

		WP_CLI::log( sprintf( 'Założono w sandboxie: %s → %s', $path, (string) $response['data']['id'] ) );

		return (string) $response['data']['id'];
	}

	/**
	 * Przerywa CAŁY przebieg, gdy odmowa dotyczy konta, a nie oferty.
	 *
	 * `OfferAccessDeniedException` („nie możesz korzystać z tej metody Publicznego API" na koncie
	 * zwykłym) nie jest cechą pojedynczej oferty — kolejne 554 próby dostałyby to samo. Bez tej
	 * bramki raport pokazuje 555 „błędów oferty" i gubi jedyną prawdziwą przyczynę.
	 *
	 * Sprawdzenia nie da się zrobić z wyprzedzeniem przez `GET /me`: ten endpoint wymaga scope'u
	 * `allegro:api:profile:read`, którego rola `write` świadomie nie ma (D-2.G6) — potwierdzone
	 * runtime odpowiedzią 403 `AccessDenied`. Dlatego rozpoznajemy przyczynę z pierwszej odmowy.
	 *
	 * @param array{status:int,body:string,data:array<mixed>|null,error:string} $response Odpowiedź POST-a.
	 * @return void
	 */
	private function abort_on_account_refusal( array $response ): void {
		if ( 403 !== $response['status'] ) {
			return;
		}

		foreach ( (array) ( $response['data']['errors'] ?? array() ) as $error ) {
			if ( is_array( $error ) && isset( $error['code'] ) && 'OfferAccessDeniedException' === $error['code'] ) {
				WP_CLI::error(
					'Allegro odmawia tworzenia ofert na TYM koncie: publiczne API wymaga konta FIRMOWEGO '
					. '(403 OfferAccessDeniedException). Przerywam przebieg — kolejne oferty dostałyby to samo. '
					. 'Sprawdź, czy sloty sandbox/read i sandbox/write są autoryzowane na koncie firmowym '
					. '(tokeny są per konto, więc po zmianie konta trzeba autoryzować je ponownie).'
				);
			}
		}
	}

	/**
	 * Kubełek raportu dla wyniku pojedynczej oferty. Warianty `would-*` (dry-run) liczą się tam,
	 * gdzie ich realne odpowiedniki — inaczej próba pokazywałaby same zera i nie dałoby się z niej
	 * odczytać, co zrobi przebieg właściwy.
	 *
	 * @param string $action Wynik zapisany w rekordzie oferty.
	 * @return string Klucz w tablicy `totals`.
	 */
	private function bucket_of( string $action ): string {
		$buckets = array(
			'created'              => 'created',
			'would-create'         => 'created',
			'images-refreshed'     => 'images_refreshed',
			'would-refresh-images' => 'images_refreshed',
			'complete'             => 'complete',
			'failed'               => 'failed',
		);

		return $buckets[ $action ] ?? 'skipped';
	}

	/**
	 * Rozstrzyga środowisko docelowe i EGZEKWUJE bezpiecznik D-2.G7 (zapis treści oferty tylko
	 * na sandboxie). Wszystko inne dzieje się dopiero po przejściu tej bramki.
	 *
	 * @param string $requested Wartość flagi `--environment`.
	 * @return Environment
	 */
	private function target_environment( string $requested ): Environment {
		try {
			$environment = Environment::for_environment( $requested );
			$environment->assert_offer_content_write_allowed();

			return $environment;
		} catch ( InvalidArgumentException $error ) {
			WP_CLI::error( $error->getMessage(), false );
		} catch ( RuntimeException $error ) {
			WP_CLI::error( $error->getMessage(), false );
		}

		WP_CLI::halt( 1 );
	}

	/**
	 * Tworzy ofertę w sandboxie na podstawie oferty ze snapshotu.
	 *
	 * @param string               $api           Baza API.
	 * @param string               $access        Access token.
	 * @param string               $cache         Katalog cache schematów kategorii.
	 * @param array<string,mixed>  $offer         Oferta ze snapshotu (surowa zwrotka produkcji).
	 * @param IdMap                $id_map        Mapowanie prod→sandbox.
	 * @param string               $shipping_rate Cennik dostawy konta sandboxowego.
	 * @param array{returnPolicy:array{id:string},impliedWarranty:array{id:string}}|null $after_sales Warunki konta.
	 * @param string               $status        Status publikacji.
	 * @param bool                 $dry_run       Czy tylko zbudować payload.
	 * @return array<string,mixed> Wpis do raportu.
	 */
	private function create_offer( string $api, string $access, string $cache, array $offer, IdMap $id_map, string $shipping_rate, ?array $after_sales, string $status, bool $dry_run ): array {
		$production_category = isset( $offer['category']['id'] ) ? (string) $offer['category']['id'] : '';
		$category            = '' !== $production_category ? $id_map->category( $production_category ) : null;

		if ( null === $category ) {
			return array(
				'action' => 'skipped-unmapped-category',
				'detail' => sprintf( 'kategoria %s spoza mapy D-3A.G5', $production_category ),
			);
		}

		$schema     = $this->category_schema( $api, $access, $cache, $category );
		$parameters = $this->build_parameters( $offer, $id_map, $schema );

		$payload = array(
			'name'        => (string) ( $offer['name'] ?? '' ),
			'category'    => array( 'id' => $category ),
			'parameters'  => $parameters['parameters'],
			'images'      => $this->image_urls( $offer ),
			'sellingMode' => $this->selling_mode( $offer ),
			'stock'       => $this->stock( $offer ),
			'publication' => array( 'status' => $status ),
			'delivery'    => array(
				'shippingRates' => array( 'id' => $shipping_rate ),
				'handlingTime'  => (string) ( $offer['delivery']['handlingTime'] ?? 'PT24H' ),
			),
			'external'    => array( 'id' => (string) $offer['id'] ),
		);

		/*
		 * `afterSalesServices` jest WYMAGANE — potwierdzone 422 z żywego sandboxa
		 * (`ReturnPolicyNotDefinedException`, `ImpliedWarrantyNotDefinedException`). Id-ki są
		 * sandboxowe: produkcyjnych UUID-ów to konto nie zna (0/98 w pomiarze).
		 */
		if ( null !== $after_sales ) {
			$payload['afterSalesServices'] = $after_sales;
		}

		if ( isset( $offer['description'] ) && is_array( $offer['description'] ) ) {
			$payload['description'] = $offer['description'];
		}

		if ( isset( $offer['location'] ) && is_array( $offer['location'] ) ) {
			$payload['location'] = $offer['location'];
		}

		if ( isset( $offer['payments'] ) && is_array( $offer['payments'] ) ) {
			$payload['payments'] = $offer['payments'];
		}

		if ( isset( $offer['language'] ) && is_string( $offer['language'] ) ) {
			$payload['language'] = $offer['language'];
		}

		if ( $dry_run ) {
			return array(
				'action'            => 'would-create',
				'detail'            => sprintf( '%d parametrów, %d zdjęć', count( $payload['parameters'] ), count( $payload['images'] ) ),
				'dropped_parameters' => $parameters['dropped'],
			);
		}

		$response = $this->send( 'POST', $api . '/sale/product-offers', $access, $payload );

		if ( 201 !== $response['status'] && 200 !== $response['status'] ) {
			$this->abort_on_account_refusal( $response );

			return array(
				'action'      => 'failed',
				'detail'      => sprintf( 'HTTP %d %s', $response['status'], $this->error_detail( $response ) ),
				'http_status' => $response['status'],
			);
		}

		return array(
			'action'             => 'created',
			'sandbox_offer_id'   => isset( $response['data']['id'] ) ? (string) $response['data']['id'] : null,
			'dropped_parameters' => $parameters['dropped'],
		);
	}

	/**
	 * Domyka istniejącą już ofertę sandboxa: pomija ją tylko wtedy, gdy jest KOMPLETNA (ma
	 * serwowane zdjęcie), a nie dlatego, że istnieje (D-3A.G4).
	 *
	 * @param string                                    $api      Baza API.
	 * @param string                                    $access   Access token.
	 * @param array<string,mixed>                       $offer    Oferta ze snapshotu.
	 * @param array{id:string,primary_image:string|null} $existing Wpis indeksu sandboxa.
	 * @param bool                                      $refresh  Wymuszenie wypchnięcia zdjęć.
	 * @param bool                                      $dry_run  Czy tylko pokazać zamiar.
	 * @return array<string,mixed> Wpis do raportu.
	 */
	private function reconcile_existing( string $api, string $access, array $offer, array $existing, bool $refresh, bool $dry_run ): array {
		$images = $this->image_urls( $offer );

		if ( array() === $images ) {
			return array(
				'action'           => 'complete',
				'sandbox_offer_id' => $existing['id'],
				'detail'           => 'snapshot nie ma zdjęć',
			);
		}

		if ( ! $refresh && null !== $existing['primary_image'] && $this->image_is_served( $existing['primary_image'] ) ) {
			return array(
				'action'           => 'complete',
				'sandbox_offer_id' => $existing['id'],
			);
		}

		if ( $dry_run ) {
			return array(
				'action'           => 'would-refresh-images',
				'sandbox_offer_id' => $existing['id'],
				'detail'           => sprintf( '%d zdjęć', count( $images ) ),
			);
		}

		$response = $this->send(
			'PATCH',
			$api . '/sale/product-offers/' . rawurlencode( $existing['id'] ),
			$access,
			array( 'images' => $images )
		);

		if ( 200 !== $response['status'] ) {
			return array(
				'action'           => 'failed',
				'sandbox_offer_id' => $existing['id'],
				'detail'           => sprintf( 'PATCH images HTTP %d %s', $response['status'], $this->error_detail( $response ) ),
				'http_status'      => $response['status'],
			);
		}

		return array(
			'action'           => 'images-refreshed',
			'sandbox_offer_id' => $existing['id'],
		);
	}

	/**
	 * Składa parametry oferty: te z poziomu oferty PLUS te z produktu (bo produktu w sandboxie
	 * nie ma), przetłumaczone przez mapę i przefiltrowane schematem kategorii sandboxa.
	 * Parametr, którego kategoria nie zna albo którego wartości nie ma w jej słowniku, wypada —
	 * wysłanie go i tak skończyłoby się odrzuceniem całej oferty.
	 *
	 * @param array<string,mixed>                                                   $offer  Oferta ze snapshotu.
	 * @param IdMap                                                                 $id_map Mapowanie.
	 * @param array<array-key,array{dictionary:array<int,string>,type:string,describesProduct:bool}> $schema Schemat parametrów kategorii sandboxa.
	 * @return array{parameters:array<int,array<string,mixed>>,dropped:array<array-key,string>}
	 */
	private function build_parameters( array $offer, IdMap $id_map, array $schema ): array {
		$source = array();

		foreach ( (array) ( $offer['productSet'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['product']['parameters'] ?? array() ) as $parameter ) {
				$source[] = $parameter;
			}
		}

		// Parametry poziomu oferty idą PO produktowych, żeby przy kolizji id wygrały: to one
		// niosą stan towaru (`11323`), czyli sedno oferty outletowej.
		foreach ( (array) ( $offer['parameters'] ?? array() ) as $parameter ) {
			$source[] = $parameter;
		}

		$built   = array();
		$dropped = array();

		foreach ( $source as $parameter ) {
			if ( ! is_array( $parameter ) || ! isset( $parameter['id'] ) ) {
				continue;
			}

			$production_id = (string) $parameter['id'];
			$mapped        = $id_map->parameter( $production_id );

			if ( null === $mapped ) {
				$dropped[ $production_id ] = 'brak w mapie D-3A.G5';

				continue;
			}

			if ( ! isset( $schema[ $mapped ] ) ) {
				$dropped[ $production_id ] = 'kategoria sandboxa nie zna tego parametru';

				continue;
			}

			/*
			 * Parametry sekcji PRODUKTU (`options.describesProduct`) nie mają prawa jechać w
			 * `parameters` oferty — Allegro odrzuca całą ofertę błędem `ParameterCategoryException`
			 * („should not be specified as in section `offer`"), co potwierdziliśmy na żywym
			 * sandboxie parametrem `224017 Kod producenta`. Skoro oferta jest kategoryjna (nie ma
			 * do czego dowiązać produktu), te parametry po prostu nie mają gdzie usiąść.
			 */
			if ( $schema[ $mapped ]['describesProduct'] ) {
				$dropped[ $production_id ] = 'parametr sekcji produktu — niedozwolony w ofercie';

				continue;
			}

			$entry = $this->parameter_entry( $parameter, $mapped, $id_map, $schema[ $mapped ] );

			if ( null === $entry ) {
				$dropped[ $production_id ] = 'brak wartości możliwej do przeniesienia';

				continue;
			}

			unset( $dropped[ $production_id ] );
			$built[ $mapped ] = $entry;
		}

		return array(
			'parameters' => array_values( $built ),
			'dropped'    => $dropped,
		);
	}

	/**
	 * Buduje pojedynczy wpis `parameters[]` payloadu albo `null`, gdy nie da się go przenieść.
	 *
	 * @param array<string,mixed>                                  $parameter Parametr ze snapshotu.
	 * @param string                                               $mapped    Zmapowane id parametru.
	 * @param IdMap                                                $id_map    Mapowanie.
	 * @param array{dictionary:array<int,string>,type:string,describesProduct:bool} $schema Schemat tego parametru w sandboxie.
	 * @return array<string,mixed>|null
	 */
	private function parameter_entry( array $parameter, string $mapped, IdMap $id_map, array $schema ): ?array {
		if ( array() !== $schema['dictionary'] ) {
			$values = array();

			foreach ( (array) ( $parameter['valuesIds'] ?? array() ) as $value_id ) {
				$value = $id_map->value( (string) $value_id );

				if ( null !== $value && in_array( $value, $schema['dictionary'], true ) ) {
					$values[] = $value;
				}
			}

			if ( array() === $values ) {
				return null;
			}

			return array(
				'id'        => $mapped,
				'valuesIds' => $values,
			);
		}

		$values = array();

		foreach ( (array) ( $parameter['values'] ?? array() ) as $value ) {
			if ( is_scalar( $value ) ) {
				$values[] = (string) $value;
			}
		}

		if ( array() === $values ) {
			return null;
		}

		return array(
			'id'     => $mapped,
			'values' => $values,
		);
	}

	/**
	 * Schemat parametrów kategorii sandboxa (id → słownik wartości + typ), z cache na dysku.
	 *
	 * @param string $api      Baza API.
	 * @param string $access   Access token.
	 * @param string $cache    Katalog cache.
	 * @param string $category Id kategorii w sandboxie.
	 * @return array<array-key,array{dictionary:array<int,string>,type:string,describesProduct:bool}>
	 */
	private function category_schema( string $api, string $access, string $cache, string $category ): array {
		$path = $cache . '/category-parameters/' . preg_replace( '/[^A-Za-z0-9._-]/', '_', $category ) . '.json';
		$data = null;

		if ( file_exists( $path ) ) {
			$cached = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- cache narzędzia CLI.

			if ( is_array( $cached ) && isset( $cached['data'] ) && is_array( $cached['data'] ) ) {
				$data = $cached['data'];
			}
		}

		if ( null === $data ) {
			$response = $this->send( 'GET', $api . '/sale/categories/' . rawurlencode( $category ) . '/parameters', $access, null );

			if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
				return array();
			}

			$data = $response['data'];

			$this->write(
				$path,
				(string) wp_json_encode(
					array(
						'url'    => $api . '/sale/categories/' . $category . '/parameters',
						'status' => 200,
						'data'   => $data,
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
		}

		$schema = array();

		foreach ( (array) ( $data['parameters'] ?? array() ) as $parameter ) {
			if ( ! is_array( $parameter ) || ! isset( $parameter['id'] ) ) {
				continue;
			}

			$dictionary = array();

			foreach ( (array) ( $parameter['dictionary'] ?? array() ) as $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$dictionary[] = (string) $entry['id'];
				}
			}

			$schema[ (string) $parameter['id'] ] = array(
				'dictionary'       => $dictionary,
				'type'             => (string) ( $parameter['type'] ?? '' ),
				'describesProduct' => isset( $parameter['options']['describesProduct'] ) && true === $parameter['options']['describesProduct'],
			);
		}

		return $schema;
	}

	/**
	 * Buduje indeks ofert sandboxa: produkcyjne `offerId` (niesione w `external.id`) → oferta
	 * sandboxowa. To jest STAN STERUJĄCY zasiewu — sandbox, nie plik lokalny, bo to sandbox
	 * jest kasowany kwartalnie.
	 *
	 * @param string $api    Baza API.
	 * @param string $access Access token.
	 * @return array<array-key,array{id:string,primary_image:string|null}>
	 */
	private function build_offer_index( string $api, string $access ): array {
		$index  = array();
		$offset = 0;

		for ( $page = 0; $page < self::MAX_INDEX_PAGES; $page++ ) {
			$url = $api . '/sale/offers?' . http_build_query(
				array(
					'limit'  => self::INDEX_PAGE_SIZE,
					'offset' => $offset,
				)
			);

			$response = $this->send( 'GET', $url, $access, null );

			if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
				WP_CLI::error(
					sprintf(
						'GET /sale/offers (offset=%d) zwróciło HTTP %d %s — bez indeksu zasiew dublowałby oferty.',
						$offset,
						$response['status'],
						$this->error_detail( $response )
					)
				);
			}

			$offers = isset( $response['data']['offers'] ) && is_array( $response['data']['offers'] )
				? array_values( $response['data']['offers'] )
				: array();

			if ( array() === $offers ) {
				break;
			}

			foreach ( $offers as $offer ) {
				if ( ! is_array( $offer ) || ! isset( $offer['id'] ) || ! isset( $offer['external']['id'] ) ) {
					continue;
				}

				$index[ (string) $offer['external']['id'] ] = array(
					'id'            => (string) $offer['id'],
					'primary_image' => isset( $offer['primaryImage']['url'] ) ? (string) $offer['primaryImage']['url'] : null,
				);
			}

			$offset += count( $offers );

			if ( isset( $response['data']['totalCount'] ) && $offset >= (int) $response['data']['totalCount'] ) {
				break;
			}
		}

		return $index;
	}

	/**
	 * Czy zdjęcie oferty realnie się serwuje. To jest test KOMPLETNOŚCI z D-3A.G4: sandbox kasuje
	 * pliki po 7 dniach, a sama oferta zostaje — więc obecność pola `primaryImage` niczego nie
	 * dowodzi, dowodzi dopiero odpowiedź CDN-u.
	 *
	 * @param string $url Adres zdjęcia.
	 * @return bool
	 */
	private function image_is_served( string $url ): bool {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Wybiera cennik dostawy KONTA SANDBOXOWEGO (produkcyjne UUID-y tam nie istnieją).
	 *
	 * @param string $api       Baza API.
	 * @param string $access    Access token.
	 * @param string $requested Wartość flagi `--shipping-rate` (może być pusta).
	 * @return string UUID cennika.
	 */
	private function resolve_shipping_rate( string $api, string $access, string $requested ): string {
		$response = $this->send( 'GET', $api . '/sale/shipping-rates', $access, null );

		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) ) {
			WP_CLI::error(
				sprintf( 'GET /sale/shipping-rates zwróciło HTTP %d %s.', $response['status'], $this->error_detail( $response ) )
			);
		}

		$rates = array();

		foreach ( (array) ( $response['data']['shippingRates'] ?? array() ) as $rate ) {
			if ( is_array( $rate ) && isset( $rate['id'] ) ) {
				$rates[] = (string) $rate['id'];
			}
		}

		if ( array() === $rates ) {
			WP_CLI::error( 'Konto sandboxowe nie ma ani jednego cennika dostawy — oferty nie przejdą walidacji.' );
		}

		if ( '' !== $requested ) {
			if ( ! in_array( $requested, $rates, true ) ) {
				WP_CLI::error( sprintf( 'Cennik %s nie należy do konta sandboxowego. Dostępne: %s', $requested, implode( ', ', $rates ) ) );
			}

			return $requested;
		}

		return $rates[0];
	}

	/**
	 * Adresy zdjęć oferty (D-3A.1.3 — snapshot trzyma URL-e, nie binaria).
	 *
	 * @param array<string,mixed> $offer Oferta ze snapshotu.
	 * @return array<int,string>
	 */
	private function image_urls( array $offer ): array {
		$urls = array();

		foreach ( (array) ( $offer['images'] ?? array() ) as $image ) {
			if ( is_string( $image ) && '' !== $image ) {
				$urls[] = $image;

				continue;
			}

			if ( is_array( $image ) && isset( $image['url'] ) && is_string( $image['url'] ) ) {
				$urls[] = $image['url'];
			}
		}

		return $urls;
	}

	/**
	 * Tryb sprzedaży bez pól pustych (`startingPrice`/`minimalPrice` przychodzą jako `null`,
	 * a Allegro odrzuca jawny null tam, gdzie oczekuje kwoty).
	 *
	 * @param array<string,mixed> $offer Oferta ze snapshotu.
	 * @return array<string,mixed>
	 */
	private function selling_mode( array $offer ): array {
		$mode = array();

		foreach ( (array) ( $offer['sellingMode'] ?? array() ) as $key => $value ) {
			if ( null !== $value ) {
				$mode[ (string) $key ] = $value;
			}
		}

		return $mode;
	}

	/**
	 * Stan magazynowy oferty.
	 *
	 * @param array<string,mixed> $offer Oferta ze snapshotu.
	 * @return array<string,mixed>
	 */
	private function stock( array $offer ): array {
		return array(
			'available' => (int) ( $offer['stock']['available'] ?? 1 ),
			'unit'      => (string) ( $offer['stock']['unit'] ?? 'UNIT' ),
		);
	}

	/**
	 * Pobiera ważny access token slotu `write` wskazanego środowiska.
	 *
	 * @param string $environment Identyfikator środowiska.
	 * @param bool   $force       Czy wymusić rotację zamiast użyć ważnego tokenu z magazynu.
	 * @return string Access token (nigdy nie trafia do wyjścia poza nagłówkiem żądania).
	 */
	private function access_token( string $environment, bool $force = false ): string {
		$config = Environment::for_environment( $environment );

		if ( ! $config->has_credentials( Environment::ROLE_WRITE ) ) {
			WP_CLI::error(
				sprintf( 'Brak stałych client_id/client_secret pary %s/write w wp-config.php.', $environment )
			);
		}

		$refresher = new TokenRefresher();

		/*
		 * `get_valid()` oddaje token z magazynu, dopóki jest ważny (12 h) — a token niesie
		 * KONTEKST KONTA z chwili autoryzacji. Gdy konto zmieniło stan po stronie Allegro,
		 * trzeba wymusić rotację, inaczej komenda uparcie chodzi ze starym kontekstem.
		 */
		$tokens = $force
			? $refresher->refresh( $environment, Environment::ROLE_WRITE )
			: $refresher->get_valid( $environment, Environment::ROLE_WRITE );

		if ( is_wp_error( $tokens ) ) {
			WP_CLI::error( sprintf( 'Brak ważnego tokenu %s/write: %s', $environment, $tokens->get_error_message() ) );
		}

		return $tokens->access_token();
	}

	/**
	 * Wykonuje żądanie do Allegro (GET bez ciała, POST/PATCH z ciałem JSON).
	 *
	 * @param string                   $method Metoda HTTP.
	 * @param string                   $url    Pełny URL.
	 * @param string                   $access Access token.
	 * @param array<string,mixed>|null $body   Ciało żądania albo null.
	 * @return array{status:int,body:string,data:array<mixed>|null,error:string}
	 */
	private function send( string $method, string $url, string $access, ?array $body ): array {
		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access,
				'Accept'        => self::MEDIA_TYPE,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = self::MEDIA_TYPE;
			$args['body']                    = (string) wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'data'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => $raw,
			'data'   => is_array( $decoded ) ? $decoded : null,
			'error'  => '',
		);
	}

	/**
	 * Zwięzły opis błędu żądania (błąd transportu albo urwane body odpowiedzi).
	 *
	 * @param array{status:int,body:string,data:array<mixed>|null,error:string} $response Wynik {@see self::send()}.
	 * @return string
	 */
	private function error_detail( array $response ): string {
		if ( '' !== $response['error'] ) {
			return $response['error'];
		}

		return trim( substr( $response['body'], 0, 500 ) );
	}

	/**
	 * Ścieżka pliku mapy: flaga albo plik obok kodu slice'a.
	 *
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return string
	 */
	private function id_map_path( array $assoc_args ): string {
		$flag = get_flag_value( $assoc_args, 'id-map', '' );

		if ( is_string( $flag ) && '' !== $flag ) {
			return $flag;
		}

		return __DIR__ . '/id-map.json';
	}

	/**
	 * Odczytuje flagę katalogu, odrzucając przełącznik bez wartości.
	 *
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @param string                    $name       Nazwa flagi.
	 * @return string
	 */
	private function require_dir_flag( array $assoc_args, string $name ): string {
		$value = get_flag_value( $assoc_args, $name, '' );

		if ( ! is_string( $value ) || '' === $value ) {
			WP_CLI::error( sprintf( 'Podaj katalog jako ścieżkę: --%s=<dir>.', $name ) );
		}

		return rtrim( $value, "/\\" );
	}

	/**
	 * Zapisuje plik, kończąc komendę błędem przy niepowodzeniu.
	 *
	 * @param string $path     Ścieżka.
	 * @param string $contents Treść.
	 * @return void
	 */
	private function write( string $path, string $contents ): void {
		if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- narzędzie CLI poza WP uploads.
			WP_CLI::error( sprintf( 'Nie mogę zapisać pliku: %s', $path ) );
		}
	}
}
