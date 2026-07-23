<?php
/**
 * Slice SandboxSeed — komenda WP-CLI robiąca snapshot ofert produkcyjnych (P-3A.1a).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\SandboxSeed;

use Qutlet\Allegro\Auth\Environment;
use Qutlet\Allegro\Cli\AllegroCliSupport;
use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * Read-only komenda WP-CLI, która pobiera oferty z PRODUKCYJNEGO konta sprzedawcy i
 * zapisuje je jako trwały snapshot — surowy JSON verbatim, bez żadnej transformacji.
 * Transformacja jest robotą FAZY 4/6; tutaj chodzi wyłącznie o wierną kopię źródła,
 * z której zasiew sandboxa (P-3A.2) odtworzy asortyment po kwartalnym czyszczeniu.
 *
 * Slice `SandboxSeed/` jest NOWY i celowo osobny od `ApiSamples/`: tamten produkuje
 * materiał na ręcznie dobrane, ZREDAGOWANE próbki do repo (FAZA 3), ten produkuje
 * kompletne, SUROWE dane robocze, które do repo nie trafiają nigdy (D-3A.G3).
 *
 * Zakres (P-3A.1a, `docs/plan.md`):
 * - `GET /sale/offers?limit=<n>&offset=<n>` — WSZYSTKIE strony listy ofert;
 * - `GET /sale/product-offers/{offerId}` — pełna zwrotka, ale TYLKO dla ofert o
 *   `publication.status === 'ACTIVE'` (D-3A.1.1: zasiew odtwarza asortyment
 *   sprzedawalny, nie archiwum).
 * Oba z `Accept: application/vnd.allegro.public.v1+json`.
 *
 * Wznawialność i idempotencja (D-3A.1.2): stanem postępu jest ZAWARTOŚĆ DYSKU —
 * `offers/<offerId>.json` istnieje → pomijamy. Nie ma pliku kursora, bo byłby drugim
 * źródłem prawdy i rozjeżdżałby się przy przerwaniu między zapisem pliku a zapisem
 * stanu. `manifest.json` jest raportem przebiegu, nie stanem sterującym — jego
 * skasowanie niczego nie psuje.
 *
 * Zdjęcia (D-3A.1.3): zapisujemy `images[].url` w zwrotce, NIE ściągamy binariów.
 *
 * Bezpieczeństwo:
 * - Token slotu `production/read` przez wspólne {@see AllegroCliSupport::access_token()}
 *   (rotacja on-demand P-2.3 pod spodem). Slot `read` nie ma prawa zapisu.
 * - Komenda robi WYŁĄCZNIE żądania GET — bezpiecznik D-2.G7 spełniony trywialnie, a
 *   jednokierunkowość D-3A.G2 (produkcja → snapshot → sandbox, nigdy odwrotnie) wynika
 *   z tego, że nie ma tu żadnej operacji zapisu do Allegro.
 * - Access token NIGDY nie trafia do wyjścia — służy tylko jako nagłówek `Authorization`.
 * - Wyjście jest NIEZREDAGOWANE: pełna zwrotka niesie `location.city`/`location.postCode`,
 *   czyli adres sprzedawcy, redagowany w FAZIE 3 przed wejściem pliku do repo. Stąd
 *   bramka {@see self::assert_write_target_protected()}.
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (nie na froncie).
 */
final class OfferSnapshotCommand {

	use AllegroCliSupport;

	/**
	 * Status publikacji kwalifikujący ofertę do pełnego pobrania (D-3A.1.1).
	 * Literał VERBATIM z realnych zwrotek FAZY 3 — porównanie ścisłe, case-sensitive.
	 */
	private const STATUS_ACTIVE = 'ACTIVE';

	/**
	 * Domyślny (i maksymalny) rozmiar strony listy `GET /sale/offers`.
	 */
	private const DEFAULT_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji: górny limit stron w jednym przebiegu. Przy 100
	 * ofertach na stronę to 20 000 ofert — nieosiągalne dla konta, które snapshotujemy
	 * (`totalCount` rzędu setek). Chroni przed nieskończoną pętlą, gdyby API przestało
	 * honorować `offset`, a `totalCount` nie przyszedł.
	 */
	private const MAX_PAGES = 200;

	/**
	 * Co ile pobranych ofert wypisać linię postępu (768 osobnych linii to szum).
	 */
	private const PROGRESS_EVERY = 25;

	/**
	 * Timeout pojedynczego żądania HTTP (sekundy).
	 */
	private const REQUEST_TIMEOUT = 30;

	/**
	 * Pobiera snapshot ofert produkcyjnych do katalogu `--out`.
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Katalog docelowy snapshotu. Tworzony, jeśli nie istnieje. Wyjście jest
	 *   NIEZREDAGOWANE — katalog leżący w drzewie repozytorium musi mieć własny
	 *   `.gitignore` (D-3A.G3), inaczej komenda odmawia zapisu.
	 *
	 * [--limit=<n>]
	 * : Rozmiar strony listy `GET /sale/offers` (1–100).
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--max-offers=<n>]
	 * : Górny limit ofert, dla których pobieramy PEŁNĄ zwrotkę (0 = bez limitu). Do
	 *   przebiegów próbnych — lista i tak jest pobierana w całości.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--refresh]
	 * : Pobierz ponownie także oferty, które już leżą na dysku (domyślnie są pomijane).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro snapshot-offers --out=C:/…/docs/allegro-snapshot-offers
	 *     wp qutlet-allegro snapshot-offers --out=/srv/snapshot --max-offers=3
	 *
	 * @param array<int,string>          $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool>  $assoc_args Flagi `--klucz=wartość` i przełączniki.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		/*
		 * `--out` PODANE BEZ WARTOŚCI WP-CLI przekazuje jako `true` (flaga), a
		 * `(string) true` to `'1'` — powstałby katalog `1` względem cwd, czyli zrzut
		 * pełnych danych produkcyjnych gdzieś w drzewie WordPressa. Dlatego wymagamy
		 * jawnego stringa, zamiast rzutować cokolwiek, co przyjdzie (jak w P-3.3a).
		 */
		$out_flag = get_flag_value( $assoc_args, 'out', '' );

		if ( ! is_string( $out_flag ) || '' === $out_flag ) {
			WP_CLI::error( 'Podaj katalog docelowy jako ścieżkę: --out=<dir>.' );
		}

		$out        = rtrim( $out_flag, "/\\" );
		$limit      = min( self::DEFAULT_LIMIT, max( 1, (int) get_flag_value( $assoc_args, 'limit', (string) self::DEFAULT_LIMIT ) ) );
		$max_offers = max( 0, (int) get_flag_value( $assoc_args, 'max-offers', '0' ) );
		$refresh    = (bool) get_flag_value( $assoc_args, 'refresh', false );

		// Bramka PRZED jakimkolwiek mkdir — odmowa nie ma prawa zostawić po sobie katalogów.
		$this->assert_write_target_protected( $out );

		if ( ! wp_mkdir_p( $out ) || ! wp_mkdir_p( $out . '/list' ) || ! wp_mkdir_p( $out . '/offers' ) ) {
			WP_CLI::error( sprintf( 'Nie mogę utworzyć/otworzyć katalogu docelowego: %s', $out ) );
		}

		$access = $this->access_token( Environment::PRODUCTION, Environment::ROLE_READ );
		$api    = Environment::for_environment( Environment::PRODUCTION )->api_base_url();

		// 1. Kompletna lista ofert (wszystkie strony) — zapisywana verbatim, strona po stronie.
		$listing = $this->fetch_offer_index( $out, $api, $access, $limit );
		$index   = $listing['index'];

		if ( array() === $index ) {
			WP_CLI::error( 'Lista ofert jest pusta — konto produkcyjne nie zwróciło żadnej oferty.' );
		}

		$status_counts = array();

		foreach ( $index as $status ) {
			$status_counts[ $status ] = isset( $status_counts[ $status ] ) ? $status_counts[ $status ] + 1 : 1;
		}

		ksort( $status_counts );

		WP_CLI::log(
			sprintf(
				'Lista: %d stron, %d ofert (totalCount=%s). Rozkład statusów: %s.',
				count( $listing['pages'] ),
				count( $index ),
				null === $listing['total_count'] ? '?' : (string) $listing['total_count'],
				$this->format_counts( $status_counts )
			)
		);

		// 2. Pełne zwrotki — tylko oferty ACTIVE (D-3A.1.1), z pominięciem tych już na dysku.
		$records            = array();
		$fetched            = 0;
		$existing           = 0;
		$failed             = 0;
		$skipped_not_active = 0;
		$targets_done       = 0;

		foreach ( $index as $offer_id => $status ) {
			$offer_id = (string) $offer_id;

			if ( self::STATUS_ACTIVE !== $status ) {
				++$skipped_not_active;

				$records[ $offer_id ] = array(
					'status' => $status,
					'action' => 'skipped-not-active',
					'file'   => null,
				);

				continue;
			}

			if ( $max_offers > 0 && $targets_done >= $max_offers ) {
				$records[ $offer_id ] = array(
					'status' => $status,
					'action' => 'skipped-max-offers',
					'file'   => null,
				);

				continue;
			}

			++$targets_done;

			$file = 'offers/' . $this->safe_name( $offer_id ) . '.json';
			$path = $out . '/' . $file;

			if ( ! $refresh && file_exists( $path ) ) {
				++$existing;

				$records[ $offer_id ] = array(
					'status' => $status,
					'action' => 'existing',
					'file'   => $file,
				);

				continue;
			}

			$full = $this->get( $api . '/sale/product-offers/' . rawurlencode( $offer_id ), $access );

			if ( 200 !== $full['status'] ) {
				++$failed;

				WP_CLI::warning( sprintf( 'Oferta %s → HTTP %d %s', $offer_id, $full['status'], $this->error_detail( $full ) ) );

				$records[ $offer_id ] = array(
					'status'      => $status,
					'action'      => 'failed',
					'file'        => null,
					'http_status' => $full['status'],
				);

				continue;
			}

			$this->write( $path, $full['body'] );
			++$fetched;

			$records[ $offer_id ] = array(
				'status' => $status,
				'action' => 'fetched',
				'file'   => $file,
			);

			if ( 0 === $fetched % self::PROGRESS_EVERY ) {
				WP_CLI::log( sprintf( '  pobrano %d ofert (pominięto jako obecne: %d, błędów: %d)…', $fetched, $existing, $failed ) );
			}
		}

		// 3. Manifest — raport przebiegu i indeks konta (BEZ tokenów, BEZ treści ofert).
		$manifest = array(
			'point'              => 'P-3A.1a',
			'environment'        => Environment::PRODUCTION,
			'api_base'           => $api,
			'generated_at'       => gmdate( 'c' ),
			'options'            => array(
				'limit'      => $limit,
				'max_offers' => $max_offers,
				'refresh'    => $refresh,
			),
			'list'               => array(
				'page_size'   => $limit,
				'total_count' => $listing['total_count'],
				'offers_seen' => count( $index ),
				'pages'       => $listing['pages'],
			),
			'status_counts'      => $status_counts,
			'totals'             => array(
				'fetched'            => $fetched,
				'existing'           => $existing,
				'failed'             => $failed,
				'skipped_not_active' => $skipped_not_active,
			),
			'offers'             => $records,
		);

		$this->write( $out . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		/*
		 * Sukces ogłaszamy tylko wtedy, gdy na dysku REALNIE leży materiał dla P-3A.2.
		 * Gdy każda oferta padła (albo żadna nie jest ACTIVE), w katalogu zostaje sama
		 * lista i manifest — stan bezużyteczny, bez tego warunku nieodróżnialny od
		 * sukcesu (exit 0 + „Zapisano…”). Ta sama pułapka co w P-3.3a.
		 */
		if ( 0 === $fetched + $existing ) {
			WP_CLI::error(
				sprintf(
					'Snapshot nie zawiera ani jednej pełnej oferty (ACTIVE: %d, błędów: %d) — w %s jest tylko lista i manifest.',
					$status_counts[ self::STATUS_ACTIVE ] ?? 0,
					$failed,
					$out
				)
			);
		}

		WP_CLI::success(
			sprintf(
				'Snapshot w %s: %d pobranych, %d już obecnych, %d błędów, %d pominiętych (nie ACTIVE). NIE commituj — surowe dane produkcyjne.',
				$out,
				$fetched,
				$existing,
				$failed,
				$skipped_not_active
			)
		);
	}

	/**
	 * Przechodzi WSZYSTKIE strony `GET /sale/offers`, zapisując każdą verbatim i budując
	 * indeks `offerId => publication.status`.
	 *
	 * Niepełna lista czyni snapshot niewiarygodnym (nie wiadomo, czego brakuje), więc
	 * błąd HTTP na dowolnej stronie kończy komendę. Oferty pobrane we wcześniejszych
	 * przebiegach zostają na dysku nietknięte — kolejne uruchomienie je pomija (D-3A.1.2).
	 *
	 * @param string $out    Katalog snapshotu.
	 * @param string $api    Baza REST API Allegro.
	 * @param string $access Access token (bearer).
	 * @param int    $limit  Rozmiar strony.
	 * @return array{index:array<string,string>,pages:array<int,array{file:string,offset:int,count:int}>,total_count:int|null}
	 */
	private function fetch_offer_index( string $out, string $api, string $access, int $limit ): array {
		$index  = array();
		$pages  = array();
		$total  = null;
		$offset = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$url  = $api . '/sale/offers?' . http_build_query(
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			);
			$resp = $this->get( $url, $access );

			if ( 200 !== $resp['status'] || ! is_array( $resp['data'] ) ) {
				WP_CLI::error(
					sprintf(
						'GET /sale/offers (offset=%d) zwróciło HTTP %d %s — snapshot z niepełną listą byłby niewiarygodny.',
						$offset,
						$resp['status'],
						$this->error_detail( $resp )
					)
				);
			}

			$file = sprintf( 'list/offset-%06d.json', $offset );
			$this->write( $out . '/' . $file, $resp['body'] );

			$offers = isset( $resp['data']['offers'] ) && is_array( $resp['data']['offers'] )
				? array_values( $resp['data']['offers'] )
				: array();

			$pages[] = array(
				'file'   => $file,
				'offset' => $offset,
				'count'  => count( $offers ),
			);

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

				$offer_id = (string) $offer['id'];

				if ( '' === $offer_id ) {
					continue;
				}

				/*
				 * Brak `publication.status` traktujemy jako wartość własną `unknown`, a nie
				 * jako „nieaktywna": dzięki temu trafia do rozkładu statusów w manifeście i
				 * jest widoczna, zamiast wtopić się w worek ofert pominiętych.
				 */
				$index[ $offer_id ] = isset( $offer['publication']['status'] )
					? (string) $offer['publication']['status']
					: 'unknown';
			}

			$offset += count( $offers );

			if ( null !== $total && $offset >= $total ) {
				break;
			}

			if ( self::MAX_PAGES - 1 === $page ) {
				WP_CLI::warning(
					sprintf(
						'Przerwano paginację na bezpieczniku %d stron (offset=%d, totalCount=%s) — lista może być niepełna.',
						self::MAX_PAGES,
						$offset,
						null === $total ? '?' : (string) $total
					)
				);
			}
		}

		return array(
			'index'       => $index,
			'pages'       => $pages,
			'total_count' => $total,
		);
	}

	/**
	 * Bramka D-3A.G3: katalog docelowy leżący W DRZEWIE repozytorium git musi mieć własny
	 * `.gitignore`. Wyjście jest niezredagowane (adres sprzedawcy w `location`), więc bez
	 * tego pliku surowe dane produkcyjne pojawiłyby się w `git status` jeden nieuważny
	 * `git add` od publikacji.
	 *
	 * Sprawdzamy OBECNOŚĆ pliku, nie jego semantykę — to bezpiecznik przeciw pomyłce
	 * (`--out` wskazany w złe miejsce), a nie dowód, że reguły w nim faktycznie ignorują
	 * zawartość. Katalog poza jakimkolwiek repozytorium nie podlega bramce.
	 *
	 * Bramka odpala się PRZED utworzeniem katalogów, więc dla ścieżki jeszcze
	 * nieistniejącej badamy przynależność do repozytorium po najbliższym istniejącym
	 * przodku. Konsekwencja jest zamierzona: katalogu snapshotu WEWNĄTRZ repo komenda
	 * sama nie utworzy — musi powstać wcześniej, razem ze swoim `.gitignore` (u nas:
	 * P-3A.1b). Poza repo tworzy się normalnie.
	 *
	 * @param string $out Katalog snapshotu (może jeszcze nie istnieć).
	 * @return void
	 */
	private function assert_write_target_protected( string $out ): void {
		$real  = realpath( $out );
		$probe = false !== $real ? $real : $this->nearest_existing_ancestor( $out );

		if ( null === $probe ) {
			return; // Ścieżki nie da się w ogóle rozwiązać — zajmie się nią wp_mkdir_p.
		}

		$worktree = $this->enclosing_git_worktree( $probe );

		if ( null === $worktree ) {
			return;
		}

		if ( false !== $real && file_exists( $real . DIRECTORY_SEPARATOR . '.gitignore' ) ) {
			return;
		}

		WP_CLI::error(
			sprintf(
				'Katalog %s leży w repozytorium git (%s) i NIE ma własnego .gitignore%s '
				. 'Snapshot to pełne, niezredagowane dane produkcyjne (D-3A.G3) — odmawiam zapisu. '
				. 'Użyj katalogu z deny-all .gitignore (np. qutlet-meta/docs/allegro-snapshot-offers/) albo katalogu poza repo.',
				$out,
				$worktree,
				false !== $real ? '.' : ' (jeszcze nie istnieje — utwórz go razem z .gitignore).'
			)
		);
	}

	/**
	 * Zwraca ścieżkę realną najbliższego ISTNIEJĄCEGO przodka podanej ścieżki. Potrzebne,
	 * gdy `--out` jeszcze nie istnieje, a musimy wiedzieć, czy powstałby w repozytorium.
	 *
	 * @param string $path Ścieżka (istniejąca lub nie).
	 * @return string|null Ścieżka realna przodka albo null, gdy nie ma żadnego.
	 */
	private function nearest_existing_ancestor( string $path ): ?string {
		$current = $path;

		while ( true ) {
			$parent = dirname( $current );

			if ( $parent === $current ) {
				return null; // Doszliśmy do korzenia wolumenu, nic nie istnieje.
			}

			$real = realpath( $parent );

			if ( false !== $real ) {
				return $real;
			}

			$current = $parent;
		}
	}

	/**
	 * Szuka w górę drzewa katalogu roboczego gita (wpis `.git` — katalog albo plik, jak
	 * w worktree/submodule).
	 *
	 * @param string $dir Katalog startowy (ścieżka realna).
	 * @return string|null Korzeń repozytorium albo null, gdy poza repozytorium.
	 */
	private function enclosing_git_worktree( string $dir ): ?string {
		$current = $dir;

		while ( true ) {
			if ( file_exists( $current . DIRECTORY_SEPARATOR . '.git' ) ) {
				return $current;
			}

			$parent = dirname( $current );

			if ( $parent === $current ) {
				return null; // Korzeń wolumenu — wyżej nie ma czego sprawdzać.
			}

			$current = $parent;
		}
	}

	/**
	 * Formatuje rozkład statusów do jednej linii logu (`ACTIVE=612, ENDED=140`).
	 *
	 * @param array<string,int> $counts Rozkład `status => liczba`.
	 * @return string
	 */
	private function format_counts( array $counts ): string {
		$parts = array();

		foreach ( $counts as $status => $count ) {
			$parts[] = $status . '=' . $count;
		}

		return implode( ', ', $parts );
	}
}
