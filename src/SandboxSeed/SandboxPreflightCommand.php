<?php
/**
 * Slice SandboxSeed — read-only sonda mierząca rozjazd snapshot ↔ sandbox (P-3A.2).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\SandboxSeed;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Auth\TokenRefresher;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Komenda WP-CLI, która NIC nie tworzy — zestawia identyfikatory występujące w snapshocie
 * produkcji (P-3A.1a) z tym, co realnie istnieje w sandboxie, i wypisuje pomiar.
 *
 * Powód istnienia: **D-3A.G5** jest OTWARTE („id kategorii i parametrów w sandboxie nie
 * muszą odpowiadać produkcyjnym"), a rozstrzygać je wolno wyłącznie na zmierzonych danych,
 * nie na przypuszczeniu. Snapshot zna tylko produkcyjne id; jedyny sposób sprawdzenia, czy
 * one w sandboxie istnieją, to o nie zapytać. Sonda jest zarazem naturalnym PREFLIGHTEM
 * zasiewu: zanim wyślemy pierwszą ofertę, chcemy wiedzieć, ile z nich w ogóle ma szansę
 * przejść walidację.
 *
 * Co mierzymy (wszystko GET, wszystko na sandboxie):
 * - `GET /sale/categories/{id}` — czy kategoria z oferty istnieje w sandboxie;
 * - `GET /sale/categories/{id}/parameters` — czy parametry poziomu OFERTY (te, które realnie
 *   występują w snapshocie; nie wpisujemy ich literałami, tylko czytamy z plików) istnieją w
 *   kategorii sandboxa i czy zgadzają się ich słownikowe `valuesIds`;
 * - `GET /sale/products/{id}` — czy produkt z katalogu Allegro (`productSet[].product.id`)
 *   istnieje w sandboxie; snapshot ma WYŁĄCZNIE oferty produktowe, więc to pytanie decyduje
 *   o kształcie całego zasiewu;
 * - słowniki KONTA sprzedawcy w sandboxie (`shipping-rates`, `return-policies`,
 *   `implied-warranties`, `warranties`, `responsible-producers`) — oferta produkcyjna
 *   odwołuje się do nich przez UUID-y konta produkcyjnego, których sandbox nie zna.
 *
 * Slot: `sandbox/write`. Sonda robi tylko GET-y, ale to jedyny slot z `sale:settings:read`
 * (D-2.G6), bez którego słowniki konta są niewidoczne — a to ten sam slot, którym pojedzie
 * zasiew, więc mierzymy dokładnie te uprawnienia, których użyje pisanie.
 *
 * Wznawialność: każda odpowiedź (także 404) ląduje w `--out` jako plik cache; ponowne
 * uruchomienie pomija to, co już zmierzone. Przy ~300 żądaniach most MCP potrafi urwać
 * przebieg w połowie — wtedy po prostu wołamy komendę ponownie (ta sama własność co
 * D-3A.1.2 dla snapshotu: stanem jest zawartość dysku).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki.
 */
final class SandboxPreflightCommand {

	/**
	 * Nagłówek `Accept` wymagany przez Allegro REST API (wersjonowany media type).
	 */
	private const ACCEPT = 'application/vnd.allegro.public.v1+json';

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Co ile żądań wypisać linię postępu.
	 */
	private const PROGRESS_EVERY = 25;

	/**
	 * Słowniki KONTA sprzedawcy do sprawdzenia w sandboxie: klucz → ścieżka endpointu.
	 * Wszystkie wymagają scope'u `sale:settings:read` (rola `write`, D-2.G6).
	 *
	 * @var array<string,string>
	 */
	private const ACCOUNT_DICTIONARIES = array(
		'shipping-rates'        => '/sale/shipping-rates',
		'return-policies'       => '/after-sales-service-conditions/return-policies',
		'implied-warranties'    => '/after-sales-service-conditions/implied-warranties',
		'warranties'            => '/after-sales-service-conditions/warranties',
		'responsible-producers' => '/sale/responsible-producers',
		// Nie jest słownikiem KONTA, tylko katalogiem metod dostawy — ale zasiew potrzebuje z niego
		// `shippingRatesConstraints`, żeby założyć zwykły cennik, więc sonda go cache'uje.
		'delivery-methods'      => '/sale/delivery-methods',
	);

	/**
	 * Mierzy, ile ze snapshotu da się w ogóle odtworzyć w sandboxie. Nic nie zapisuje do Allegro.
	 *
	 * ## OPTIONS
	 *
	 * --snapshot=<dir>
	 * : Katalog snapshotu z P-3A.1a (oczekuje podkatalogu `offers/` z plikami `<offerId>.json`).
	 *
	 * --out=<dir>
	 * : Katalog na cache odpowiedzi sandboxa i raport `preflight-report.json`. Wyjście jest
	 *   publicznymi danymi Allegro + własnymi ustawieniami konta sandboxowego, ale trzymamy
	 *   je poza repo tak samo jak snapshot.
	 *
	 * [--products=<n>]
	 * : Ile RÓŻNYCH produktów z katalogu sprawdzić (0 = wszystkie). Próbka wystarcza do
	 *   pomiaru skali, a oszczędza kilkaset żądań.
	 * ---
	 * default: 60
	 * ---
	 *
	 * [--categories=<n>]
	 * : Ile RÓŻNYCH kategorii sprawdzić (0 = wszystkie).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--write-id-map=<file>]
	 * : Zapisz tablicę mapowania prod→sandbox (D-3A.G5) złożoną WYŁĄCZNIE z bytów, których
	 *   obecność w sandboxie potwierdziło to uruchomienie. Konsumuje ją {@see IdMap}.
	 *   Sensowne tylko przy `--categories=0` — mapa z częściowego przebiegu pominęłaby
	 *   kategorie, których nie sprawdzono.
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro sandbox-preflight --snapshot=C:/…/docs/allegro-snapshot-offers --out=C:/…/preflight
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi `--klucz=wartość`.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$snapshot = $this->require_dir_flag( $assoc_args, 'snapshot' );
		$out      = $this->require_dir_flag( $assoc_args, 'out' );

		$product_budget  = max( 0, (int) get_flag_value( $assoc_args, 'products', '60' ) );
		$category_budget = max( 0, (int) get_flag_value( $assoc_args, 'categories', '0' ) );

		if ( ! is_dir( $snapshot . '/offers' ) ) {
			WP_CLI::error( sprintf( 'Nie widzę katalogu ofert snapshotu: %s/offers', $snapshot ) );
		}

		foreach ( array( '', '/categories', '/category-parameters', '/products', '/dictionaries' ) as $sub ) {
			if ( ! wp_mkdir_p( $out . $sub ) ) {
				WP_CLI::error( sprintf( 'Nie mogę utworzyć katalogu: %s', $out . $sub ) );
			}
		}

		$inventory = $this->read_snapshot_inventory( $snapshot );

		WP_CLI::log(
			sprintf(
				'Snapshot: %d ofert, %d kategorii, %d produktów, %d ofert produktowych.',
				$inventory['offers'],
				count( $inventory['categories'] ),
				count( $inventory['products'] ),
				$inventory['product_based']
			)
		);

		$env    = Environment::for_environment( Environment::SANDBOX );
		$api    = $env->api_base_url();
		$access = $this->access_token();

		$categories = $inventory['categories'];
		$products   = $inventory['products'];

		if ( $category_budget > 0 ) {
			$categories = array_slice( $categories, 0, $category_budget );
		}

		if ( $product_budget > 0 ) {
			$products = array_slice( $products, 0, $product_budget );
		}

		$report = array(
			'point'        => 'P-3A.2 (preflight, D-3A.G5)',
			'environment'  => Environment::SANDBOX,
			'api_base'     => $api,
			'generated_at' => gmdate( 'c' ),
			'snapshot'     => array(
				'dir'                 => $snapshot,
				'offers'              => $inventory['offers'],
				'product_based'       => $inventory['product_based'],
				'distinct_categories' => count( $inventory['categories'] ),
				'distinct_products'   => count( $inventory['products'] ),
				'offer_parameters'    => $inventory['offer_parameters'],
				'account_refs'        => $inventory['account_refs'],
			),
			'categories'   => $this->probe_categories( $out, $api, $access, $categories ),
			'parameters'   => array(),
			'products'     => $this->probe_products( $out, $api, $access, $products ),
			'dictionaries' => $this->probe_dictionaries( $out, $api, $access, $inventory['account_refs'] ),
		);

		$parameters          = $this->probe_category_parameters( $out, $api, $access, $categories, $inventory['offer_parameters'] );
		$report['parameters'] = $parameters['report'];

		$this->write(
			$out . '/preflight-report.json',
			(string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		$this->print_summary( $report );

		$id_map_file = get_flag_value( $assoc_args, 'write-id-map', '' );

		if ( is_string( $id_map_file ) && '' !== $id_map_file ) {
			/*
			 * Mapa z OKROJONEGO przebiegu jest gorsza niż jej brak: zasiew pomija oferty w
			 * kategoriach spoza mapy (D-3A.G5 — brak wpisu = brak mapowania), więc niesprawdzone
			 * kategorie po cichu wypadłyby z asortymentu. Dokumentowanie tego nie wystarcza.
			 */
			if ( $category_budget > 0 ) {
				WP_CLI::error( '--write-id-map wymaga --categories=0: mapa z częściowego przebiegu po cichu wycięłaby oferty przy zasiewie.' );
			}

			$this->write_id_map( $id_map_file, $report['categories']['present_ids'], $parameters['schema'], $inventory );
		}

		WP_CLI::success( sprintf( 'Raport: %s/preflight-report.json', $out ) );
	}

	/**
	 * Zapisuje tablicę mapowania prod→sandbox (D-3A.G5) złożoną z bytów POTWIERDZONYCH w tym
	 * przebiegu. Mapa jest dziś tożsamościowa — i to jest wynik pomiaru, nie założenie: wpis
	 * powstaje tylko dla identyfikatora, który sandbox realnie zwrócił. Po kwartalnym
	 * przetasowaniu kategorii ponowny przebieg wypluje mapę mniejszą (albo inną), a diff
	 * pokaże, co wypadło — zamiast pozwolić zasiewowi wysłać nieistniejące id.
	 *
	 * @param string                                                  $path      Ścieżka pliku mapy.
	 * @param array<int,string>                                       $categories Kategorie potwierdzone w sandboxie.
	 * @param array{parameters:array<int,string>,values:array<int,string>} $schema   Byty ze schematów kategorii sandboxa.
	 * @param array<string,mixed>                                     $inventory Inwentarz snapshotu.
	 * @return void
	 */
	private function write_id_map( string $path, array $categories, array $schema, array $inventory ): void {
		$map = array(
			'generatedAt'     => gmdate( 'c' ),
			'source'          => 'wp qutlet-allegro sandbox-preflight --write-id-map',
			'note'            => 'Wyłącznie identyfikatory potwierdzone żądaniem do sandboxa (D-3A.G5). Nie edytuj ręcznie — regeneruj pomiarem.',
			'categories'      => $this->identity_for( $categories ),
			'parameters'      => $this->identity_for( array_values( array_intersect( $inventory['all_parameter_ids'], $schema['parameters'] ) ) ),
			'parameterValues' => $this->identity_for( array_values( array_intersect( $inventory['all_value_ids'], $schema['values'] ) ) ),
		);

		$this->write( $path, (string) wp_json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		WP_CLI::log(
			sprintf(
				'Mapa D-3A.G5 → %s (kategorie: %d, parametry: %d, wartości: %d).',
				$path,
				count( $map['categories'] ),
				count( $map['parameters'] ),
				count( $map['parameterValues'] )
			)
		);
	}

	/**
	 * Buduje sekcję mapy `id → id` z listy potwierdzonych identyfikatorów.
	 *
	 * @param array<int,string> $ids Identyfikatory.
	 * @return array<array-key,string>
	 */
	private function identity_for( array $ids ): array {
		$section = array();

		sort( $ids );

		foreach ( $ids as $id ) {
			$section[ $id ] = $id;
		}

		return $section;
	}

	/**
	 * Wczytuje snapshot i wyciąga z niego identyfikatory, które zasiew musiałby odtworzyć
	 * w sandboxie. Czyta pliki, nie zgaduje kształtu — brakujące klucze pomija.
	 *
	 * Identyfikatory zwracamy jako LISTY stringów, nie jako klucze tablic: `offerId`,
	 * `category.id` i `parameters[].id` są u Allegro napisami numerycznymi, a PHP zamienia
	 * taki klucz na `int` — przy `strict_types` przekazanie go dalej do parametru `string`
	 * jest TypeError, a `in_array( …, …, true )` przestaje trafiać. Klucze zostawiamy
	 * wyłącznie tam, gdzie identyfikator jest nienumeryczny (UUID-y słowników konta).
	 *
	 * `offer_parameters` to parametry poziomu OFERTY (te jadą w `parameters` oferty i tylko one
	 * podlegają walidacji kategorii dziś). `all_parameter_ids`/`all_value_ids` obejmują TAKŻE
	 * parametry produktu — bo skoro katalogu produktów w sandboxie nie ma, zasiew zejdzie z nimi
	 * na poziom oferty i wtedy one też muszą być zmapowane.
	 *
	 * @param string $snapshot Katalog snapshotu.
	 * @return array{offers:int,product_based:int,categories:array<int,string>,products:array<int,string>,offer_parameters:array<int,array{id:string,value_ids:array<int,string>}>,all_parameter_ids:array<int,string>,all_value_ids:array<int,string>,account_refs:array<string,array<string,int>>}
	 */
	private function read_snapshot_inventory( string $snapshot ): array {
		$files = glob( $snapshot . '/offers/*.json' );

		if ( false === $files || array() === $files ) {
			WP_CLI::error( sprintf( 'Katalog %s/offers nie zawiera żadnego pliku oferty.', $snapshot ) );
		}

		$categories       = array();
		$products         = array();
		$offer_parameters = array();
		$account_refs     = array(
			'shipping-rates'        => array(),
			'return-policies'       => array(),
			'implied-warranties'    => array(),
			'warranties'            => array(),
			'responsible-producers' => array(),
		);
		$product_based    = 0;
		$all_parameters   = array();
		$all_values       = array();

		foreach ( $files as $file ) {
			$offer = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalny plik snapshotu poza uploads.

			if ( ! is_array( $offer ) ) {
				WP_CLI::warning( sprintf( 'Pomijam nieparsowalny plik snapshotu: %s', $file ) );

				continue;
			}

			if ( isset( $offer['category']['id'] ) ) {
				$categories[ (string) $offer['category']['id'] ] = true;
			}

			if ( isset( $offer['productSet'] ) && is_array( $offer['productSet'] ) && array() !== $offer['productSet'] ) {
				++$product_based;

				foreach ( $offer['productSet'] as $item ) {
					if ( isset( $item['product']['id'] ) ) {
						$products[ (string) $item['product']['id'] ] = true;
					}

					if ( isset( $item['responsibleProducer']['id'] ) ) {
						$this->bump( $account_refs['responsible-producers'], (string) $item['responsibleProducer']['id'] );
					}

					foreach ( (array) ( $item['product']['parameters'] ?? array() ) as $parameter ) {
						$this->collect_parameter( $parameter, $all_parameters, $all_values );
					}
				}
			}

			foreach ( (array) ( $offer['parameters'] ?? array() ) as $parameter ) {
				$this->collect_parameter( $parameter, $all_parameters, $all_values );

				if ( ! is_array( $parameter ) || ! isset( $parameter['id'] ) ) {
					continue;
				}

				$parameter_id = (string) $parameter['id'];

				if ( ! isset( $offer_parameters[ $parameter_id ] ) ) {
					$offer_parameters[ $parameter_id ] = array();
				}

				foreach ( (array) ( $parameter['valuesIds'] ?? array() ) as $value_id ) {
					$this->bump( $offer_parameters[ $parameter_id ], (string) $value_id );
				}
			}

			$after_sales = isset( $offer['afterSalesServices'] ) && is_array( $offer['afterSalesServices'] )
				? $offer['afterSalesServices']
				: array();

			foreach ( array(
				'return-policies'    => 'returnPolicy',
				'implied-warranties' => 'impliedWarranty',
				'warranties'         => 'warranty',
			) as $bucket => $key ) {
				if ( isset( $after_sales[ $key ]['id'] ) ) {
					$this->bump( $account_refs[ $bucket ], (string) $after_sales[ $key ]['id'] );
				}
			}

			if ( isset( $offer['delivery']['shippingRates']['id'] ) ) {
				$this->bump( $account_refs['shipping-rates'], (string) $offer['delivery']['shippingRates']['id'] );
			}
		}

		ksort( $offer_parameters );

		$parameters = array();

		foreach ( $offer_parameters as $parameter_id => $value_ids ) {
			$parameters[] = array(
				'id'        => (string) $parameter_id,
				'value_ids' => $this->string_keys( $value_ids ),
			);
		}

		return array(
			'offers'            => count( $files ),
			'product_based'     => $product_based,
			'categories'        => $this->string_keys( $categories ),
			'products'          => $this->string_keys( $products ),
			'offer_parameters'  => $parameters,
			'all_parameter_ids' => $this->string_keys( $all_parameters ),
			'all_value_ids'     => $this->string_keys( $all_values ),
			'account_refs'      => $account_refs,
		);
	}

	/**
	 * Dokłada id parametru i jego wartości słownikowych do zbiorów „wszystko, co widzieliśmy".
	 *
	 * @param mixed                  $parameter  Wpis `parameters[]` (oferty albo produktu).
	 * @param array<array-key,bool>  $parameters Zbiór id parametrów (przez referencję).
	 * @param array<array-key,bool>  $values     Zbiór id wartości (przez referencję).
	 * @return void
	 */
	private function collect_parameter( $parameter, array &$parameters, array &$values ): void {
		if ( ! is_array( $parameter ) || ! isset( $parameter['id'] ) ) {
			return;
		}

		$parameters[ (string) $parameter['id'] ] = true;

		foreach ( (array) ( $parameter['valuesIds'] ?? array() ) as $value_id ) {
			$values[ (string) $value_id ] = true;
		}
	}

	/**
	 * Sprawdza istnienie kategorii w sandboxie (`GET /sale/categories/{id}`).
	 *
	 * @param string            $out        Katalog cache.
	 * @param string            $api        Baza API sandboxa.
	 * @param string            $access     Access token.
	 * @param array<int,string> $categories Id kategorii z produkcji.
	 * @return array{checked:int,present:int,present_ids:array<int,string>,missing:array<int,string>,other:array<string,int>,leaf:int}
	 */
	private function probe_categories( string $out, string $api, string $access, array $categories ): array {
		$present     = 0;
		$present_ids = array();
		$leaf        = 0;
		$missing     = array();
		$other       = array();
		$done        = 0;

		foreach ( $categories as $category_id ) {
			$cached = $this->cached_get(
				$out . '/categories/' . $this->safe_name( $category_id ) . '.json',
				$api . '/sale/categories/' . rawurlencode( $category_id ),
				$access
			);

			if ( 200 === $cached['status'] ) {
				++$present;
				$present_ids[] = $category_id;

				if ( isset( $cached['data']['leaf'] ) && true === $cached['data']['leaf'] ) {
					++$leaf;
				}
			} elseif ( 404 === $cached['status'] ) {
				$missing[] = $category_id;
			} else {
				$this->bump( $other, (string) $cached['status'] );
			}

			$this->tick( ++$done, count( $categories ), 'kategorie' );
		}

		return array(
			'checked'     => count( $categories ),
			'present'     => $present,
			'present_ids' => $present_ids,
			'leaf'        => $leaf,
			'missing'     => $missing,
			'other'       => $other,
		);
	}

	/**
	 * Sprawdza, czy parametry oferty (`11323`, `229205`) istnieją w kategoriach sandboxa i czy
	 * ich `valuesIds` (słownikowe) są te same, co na produkcji.
	 *
	 * @param string                                              $out              Katalog cache.
	 * @param string                                              $api              Baza API sandboxa.
	 * @param string                                              $access           Access token.
	 * @param array<int,string>                                   $categories       Id kategorii.
	 * @param array<int,array{id:string,value_ids:array<int,string>}> $offer_parameters Parametry oferty ze snapshotu.
	 * @return array{report:array<string,mixed>,schema:array{parameters:array<int,string>,values:array<int,string>}}
	 */
	private function probe_category_parameters( string $out, string $api, string $access, array $categories, array $offer_parameters ): array {
		$per_parameter    = array();
		$unavailable      = array();
		$required_unknown = array();
		$schema_params    = array();
		$schema_values    = array();
		$checked          = 0;
		$done             = 0;

		foreach ( $offer_parameters as $parameter ) {
			$per_parameter[] = array(
				'id'                        => $parameter['id'],
				'categories_with_parameter' => 0,
				'categories_without'        => array(),
				'value_ids_expected'        => $parameter['value_ids'],
				'value_ids_missing'         => array(),
			);
		}

		foreach ( $categories as $category_id ) {
			$cached = $this->cached_get(
				$out . '/category-parameters/' . $this->safe_name( $category_id ) . '.json',
				$api . '/sale/categories/' . rawurlencode( $category_id ) . '/parameters',
				$access
			);

			$this->tick( ++$done, count( $categories ), 'parametry kategorii' );

			if ( 200 !== $cached['status'] || ! isset( $cached['data']['parameters'] ) || ! is_array( $cached['data']['parameters'] ) ) {
				$this->bump( $unavailable, (string) $cached['status'] );

				continue;
			}

			++$checked;

			$wanted = array();

			foreach ( $per_parameter as $slot => $tracked ) {
				$wanted[ $tracked['id'] ] = $slot;
			}

			$seen = array();

			foreach ( $cached['data']['parameters'] as $parameter ) {
				if ( ! is_array( $parameter ) || ! isset( $parameter['id'] ) ) {
					continue;
				}

				$parameter_id                 = (string) $parameter['id'];
				$schema_params[ $parameter_id ] = true;

				foreach ( (array) ( $parameter['dictionary'] ?? array() ) as $entry ) {
					if ( is_array( $entry ) && isset( $entry['id'] ) ) {
						$schema_values[ (string) $entry['id'] ] = true;
					}
				}

				$slot = $wanted[ $parameter_id ] ?? null;

				if ( null === $slot ) {
					// Parametr WYMAGANY przez sandbox, którego oferta w ogóle nie niesie.
					if ( isset( $parameter['required'] ) && true === $parameter['required'] ) {
						$this->bump( $required_unknown, $parameter_id . ' ' . (string) ( $parameter['name'] ?? '?' ) );
					}

					continue;
				}

				$seen[] = $parameter_id;
				++$per_parameter[ $slot ]['categories_with_parameter'];

				$known = array();

				foreach ( (array) ( $parameter['dictionary'] ?? array() ) as $entry ) {
					if ( is_array( $entry ) && isset( $entry['id'] ) ) {
						$known[] = (string) $entry['id'];
					}
				}

				if ( array() === $known ) {
					continue; // Parametr nie jest słownikowy — `valuesIds` nas nie dotyczy.
				}

				foreach ( $per_parameter[ $slot ]['value_ids_expected'] as $value_id ) {
					if ( ! in_array( $value_id, $known, true ) ) {
						$this->bump( $per_parameter[ $slot ]['value_ids_missing'], $value_id );
					}
				}
			}

			foreach ( $per_parameter as $slot => $tracked ) {
				if ( ! in_array( $tracked['id'], $seen, true ) ) {
					$per_parameter[ $slot ]['categories_without'][] = $category_id;
				}
			}
		}

		return array(
			'report' => array(
				'checked_categories' => $checked,
				'unavailable'        => $unavailable,
				'per_parameter'      => $per_parameter,
				'required_unknown'   => $required_unknown,
			),
			'schema' => array(
				'parameters' => $this->string_keys( $schema_params ),
				'values'     => $this->string_keys( $schema_values ),
			),
		);
	}

	/**
	 * Sprawdza istnienie produktów katalogu Allegro w sandboxie (`GET /sale/products/{id}`).
	 *
	 * @param string            $out      Katalog cache.
	 * @param string            $api      Baza API sandboxa.
	 * @param string            $access   Access token.
	 * @param array<int,string> $products Id produktów z produkcji.
	 * @return array{checked:int,present:int,missing:int,other:array<string,int>,missing_sample:array<int,string>}
	 */
	private function probe_products( string $out, string $api, string $access, array $products ): array {
		$present = 0;
		$missing = 0;
		$sample  = array();
		$other   = array();
		$done    = 0;

		foreach ( $products as $product_id ) {
			$cached = $this->cached_get(
				$out . '/products/' . $this->safe_name( $product_id ) . '.json',
				$api . '/sale/products/' . rawurlencode( $product_id ),
				$access
			);

			if ( 200 === $cached['status'] ) {
				++$present;
			} elseif ( 404 === $cached['status'] ) {
				++$missing;

				if ( count( $sample ) < 5 ) {
					$sample[] = $product_id;
				}
			} else {
				$this->bump( $other, (string) $cached['status'] );
			}

			$this->tick( ++$done, count( $products ), 'produkty' );
		}

		return array(
			'checked'        => count( $products ),
			'present'        => $present,
			'missing'        => $missing,
			'other'          => $other,
			'missing_sample' => $sample,
		);
	}

	/**
	 * Pobiera słowniki KONTA z sandboxa i sprawdza, czy produkcyjne UUID-y w nich występują.
	 *
	 * @param string                          $out          Katalog cache.
	 * @param string                          $api          Baza API sandboxa.
	 * @param string                          $access       Access token.
	 * @param array<string,array<string,int>> $account_refs Odwołania z produkcji: słownik → (UUID → liczność).
	 * @return array<string,array<string,mixed>>
	 */
	private function probe_dictionaries( string $out, string $api, string $access, array $account_refs ): array {
		$result = array();

		foreach ( self::ACCOUNT_DICTIONARIES as $name => $path ) {
			$cached = $this->cached_get(
				$out . '/dictionaries/' . $name . '.json',
				$api . $path,
				$access
			);

			$ids = array();

			if ( 200 === $cached['status'] && is_array( $cached['data'] ) ) {
				foreach ( $cached['data'] as $value ) {
					if ( ! is_array( $value ) ) {
						continue;
					}

					foreach ( $value as $entry ) {
						if ( is_array( $entry ) && isset( $entry['id'] ) ) {
							$ids[ (string) $entry['id'] ] = true;
						}
					}
				}
			}

			$expected = array_keys( $account_refs[ $name ] ?? array() );
			$found    = 0;

			foreach ( $expected as $id ) {
				if ( isset( $ids[ $id ] ) ) {
					++$found;
				}
			}

			$result[ $name ] = array(
				'http_status'         => $cached['status'],
				'sandbox_entries'     => count( $ids ),
				'production_ids'      => count( $expected ),
				'production_ids_found' => $found,
			);
		}

		return $result;
	}

	/**
	 * GET z cache na dysku: raz pobrana odpowiedź (także 404) nie jest pobierana ponownie.
	 * To ta sama własność, co wznawialność snapshotu (D-3A.1.2) — stanem jest dysk.
	 *
	 * @param string $path   Ścieżka pliku cache.
	 * @param string $url    Pełny URL.
	 * @param string $access Access token.
	 * @return array{status:int,data:array<mixed>|null}
	 */
	private function cached_get( string $path, string $url, string $access ): array {
		if ( file_exists( $path ) ) {
			$cached = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalny cache narzędzia CLI.

			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return array(
					'status' => (int) $cached['status'],
					'data'   => is_array( $cached['data'] ?? null ) ? $cached['data'] : null,
				);
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access,
					'Accept'        => self::ACCEPT,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Błędu transportu NIE cache'ujemy — to stan chwilowy, nie odpowiedź sandboxa.
			WP_CLI::warning( sprintf( '%s → %s', $url, $response->get_error_message() ) );

			return array(
				'status' => 0,
				'data'   => null,
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$entry   = array(
			'url'    => $url,
			'status' => $status,
			'data'   => is_array( $decoded ) ? $decoded : null,
		);

		$this->write( $path, (string) wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return array(
			'status' => $status,
			'data'   => $entry['data'],
		);
	}

	/**
	 * Wypisuje pomiar w postaci czytelnej bez otwierania raportu.
	 *
	 * @param array<string,mixed> $report Raport.
	 * @return void
	 */
	private function print_summary( array $report ): void {
		$categories = $report['categories'];
		$products   = $report['products'];

		WP_CLI::log( '--- POMIAR D-3A.G5 (sandbox vs snapshot) ---' );
		WP_CLI::log(
			sprintf(
				'Kategorie: sprawdzono %d, istnieje w sandboxie %d, brak %d (inne HTTP: %s).',
				$categories['checked'],
				$categories['present'],
				count( $categories['missing'] ),
				array() === $categories['other'] ? '—' : wp_json_encode( $categories['other'] )
			)
		);
		WP_CLI::log(
			sprintf(
				'Produkty katalogu: sprawdzono %d, istnieje %d, brak %d (inne HTTP: %s).',
				$products['checked'],
				$products['present'],
				$products['missing'],
				array() === $products['other'] ? '—' : wp_json_encode( $products['other'] )
			)
		);

		foreach ( $report['parameters']['per_parameter'] as $data ) {
			WP_CLI::log(
				sprintf(
					'Parametr %s: obecny w %d/%d kategorii, brakujące valuesIds: %d.',
					$data['id'],
					$data['categories_with_parameter'],
					$report['parameters']['checked_categories'],
					count( $data['value_ids_missing'] )
				)
			);
		}

		foreach ( $report['dictionaries'] as $name => $data ) {
			WP_CLI::log(
				sprintf(
					'Słownik %s: HTTP %d, wpisów w sandboxie %d, produkcyjnych id %d, z tego odnalezionych %d.',
					$name,
					$data['http_status'],
					$data['sandbox_entries'],
					$data['production_ids'],
					$data['production_ids_found']
				)
			);
		}
	}

	/**
	 * Pobiera ważny access token slotu `sandbox/write` (jedyny ze scope'em `sale:settings:read`).
	 *
	 * @return string Access token (nigdy nie trafia do wyjścia poza nagłówkiem żądania).
	 */
	private function access_token(): string {
		$environment = Environment::for_environment( Environment::SANDBOX );

		if ( ! $environment->has_credentials( Environment::ROLE_WRITE ) ) {
			WP_CLI::error( 'Brak stałych QUTLET_ALLEGRO_SANDBOX_WRITE_CLIENT_ID/SECRET w wp-config.php.' );
		}

		$tokens = ( new TokenRefresher() )->get_valid( Environment::SANDBOX, Environment::ROLE_WRITE );

		if ( is_wp_error( $tokens ) ) {
			WP_CLI::error( sprintf( 'Brak ważnego tokenu sandbox/write: %s', $tokens->get_error_message() ) );
		}

		return $tokens->access_token();
	}

	/**
	 * Odczytuje flagę katalogu, odrzucając przełącznik bez wartości (WP-CLI podaje wtedy `true`,
	 * a `(string) true` to `'1'` — powstałby katalog `1` względem cwd).
	 *
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @param string                    $name       Nazwa flagi.
	 * @return string Ścieżka bez końcowego separatora.
	 */
	private function require_dir_flag( array $assoc_args, string $name ): string {
		$value = get_flag_value( $assoc_args, $name, '' );

		if ( ! is_string( $value ) || '' === $value ) {
			WP_CLI::error( sprintf( 'Podaj katalog jako ścieżkę: --%s=<dir>.', $name ) );
		}

		return rtrim( $value, "/\\" );
	}

	/**
	 * Zwiększa licznik w tablicy `klucz => liczność`. Klucz numeryczny PHP i tak zamieni na
	 * `int` — stąd `array-key` w typie, zamiast udawać, że zostaje stringiem.
	 *
	 * @param array<array-key,int> $counter Licznik (przez referencję).
	 * @param string               $key     Klucz.
	 * @return void
	 */
	private function bump( array &$counter, string $key ): void {
		$counter[ $key ] = isset( $counter[ $key ] ) ? $counter[ $key ] + 1 : 1;
	}

	/**
	 * Zwraca klucze tablicy jako listę STRINGÓW. Klucz numeryczny („260041") wraca z PHP jako
	 * `int`, a dalej jedzie do parametrów typu `string` — bez tej normalizacji `strict_types`
	 * kończy się TypeError.
	 *
	 * @param array<array-key,mixed> $map Tablica z identyfikatorami w kluczach.
	 * @return array<int,string>
	 */
	private function string_keys( array $map ): array {
		$keys = array();

		foreach ( array_keys( $map ) as $key ) {
			$keys[] = (string) $key;
		}

		return $keys;
	}

	/**
	 * Wypisuje linię postępu co {@see self::PROGRESS_EVERY} pozycji.
	 *
	 * @param int    $done  Ile zrobione.
	 * @param int    $total Ile wszystkich.
	 * @param string $label Etykieta.
	 * @return void
	 */
	private function tick( int $done, int $total, string $label ): void {
		if ( 0 === $done % self::PROGRESS_EVERY || $done === $total ) {
			WP_CLI::log( sprintf( '  %s: %d/%d', $label, $done, $total ) );
		}
	}

	/**
	 * Sprowadza identyfikator do bezpiecznego fragmentu nazwy pliku.
	 *
	 * @param string $id Identyfikator.
	 * @return string
	 */
	private function safe_name( string $id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $id );
	}

	/**
	 * Zapisuje treść do pliku, kończąc komendę błędem przy niepowodzeniu.
	 *
	 * @param string $path     Ścieżka pliku.
	 * @param string $contents Treść.
	 * @return void
	 */
	private function write( string $path, string $contents ): void {
		if ( false === file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache narzędzia CLI poza WP uploads.
			WP_CLI::error( sprintf( 'Nie mogę zapisać pliku: %s', $path ) );
		}
	}
}
