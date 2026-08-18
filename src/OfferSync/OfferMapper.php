<?php
/**
 * Slice OfferSync — czyste funkcje mapujące ofertę Allegro na nasz model (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

use Qutlet\Allegro\Auth\Environment;

/**
 * Ekstrakcja i transformacja danych z pełnej zwrotki `GET /sale/product-offers/{id}`
 * według mappingu FAZY 4 (`docs/mapping-allegro.md` §1–§4) — bez żadnych zapisów.
 * Wszystkie klucze JSON VERBATIM z realnych zwrotek (próbki P-3.1 + snapshot);
 * zapis do WP robi {@see ProductWriter}, orkiestrację {@see ImportOffersCommand}.
 *
 * Klasa celowo BEZ wywołań WP — czysta transformacja, testowalna PHPUnitem bez
 * środowiska WordPressa (jak `SandboxSeed\IdMap`).
 */
final class OfferMapper {

	/**
	 * Auto-mapa Allegro „Stan" → `klasa_stanu` (D-4.1.1, tabela potwierdzona
	 * decyzją użytkownika D-6.1.4; REWIZJA CZĘŚCIOWA D-12.1c.1, sesja
	 * 2026-08-13 — `Nowy` przeniesiony z `A` do nowej klasy `Nowe`, D-12.G1;
	 * REWIZJA dalsza, sesja 2026-08-17 — mapowanie dziś TOŻSAMOŚCIOWE: kod =
	 * surowa wartość „Stan" verbatim dla wszystkich siedmiu wartości, osobne
	 * litery/słowa-kody (`A`-`D`, `Nowe`) wycofane).
	 * Klucze VERBATIM z wartości parametru `Stan` (id 11323) w realnym
	 * snapshocie; porównanie ścisłe, case-sensitive. Wartości to `kod` z bytu
	 * {@see \Qutlet\Core\ProductCondition\ClassDefinitionsTaxonomy} — pole
	 * `kod` to wolny tekst, nieograniczony do jednej litery.
	 *
	 * @var array<string,string>
	 */
	private const CONDITION_MAP = array(
		'Nowy'            => 'Nowy',
		'Powystawowy'     => 'Powystawowy',
		'Po zwrocie'      => 'Po zwrocie',
		'Używany'         => 'Używany',
		'Nowy z defektem' => 'Nowy z defektem',
		'Uszkodzony'      => 'Uszkodzony',
		'Na części'       => 'Na części',
	);

	/**
	 * Rodzaje kandydatów wagowo-wymiarowych — {@see self::WEIGHT_DIMENSION_CANDIDATES}.
	 */
	private const KIND_DIMENSION = 'dimension';
	private const KIND_WEIGHT    = 'weight';

	/**
	 * Kuratorska lista kandydatów na atrybuty wagowo-wymiarowe produktu/paczki
	 * (D-21.3.1, kontrakt §16) — nazwa parametru (VERBATIM ze słownika
	 * kategorii, §15) → rodzaj ({@see self::KIND_DIMENSION}/{@see self::KIND_WEIGHT}).
	 * Identyfikacja kandydata jest po NAZWIE (ta lista); sama JEDNOSTKA i
	 * przelicznik są rozstrzygane WYŁĄCZNIE przez `id` parametru ze słownika
	 * kategorii ({@see self::weight_dimension_attributes()}) — nigdy z nazwy.
	 * Generyczne „każdy parametr z jednostką długości/wagi” było ŚWIADOMIE
	 * odrzucone (kontrakt §16): wciągnęłoby np. `Długość przewodu` (`m`,
	 * kontrprzykład §15) w obróbkę zarezerwowaną dla wymiarów/wagi
	 * PRODUKTU/PACZKI.
	 *
	 * Lista NIE jest zamknięta — 391 różnych nazw parametrów w całej próbce
	 * ofert (mapping §4b), tu sprawdzony tylko ułamek (§15); dopisywanie
	 * nowych nazw przy kolejnych kategoriach jest oczekiwane, wzorem
	 * {@see self::CONDITION_MAP}.
	 *
	 * @var array<string,string>
	 */
	private const WEIGHT_DIMENSION_CANDIDATES = array(
		'Szerokość produktu'                      => self::KIND_DIMENSION,
		'Wysokość produktu'                        => self::KIND_DIMENSION,
		'Głębokość produktu'                       => self::KIND_DIMENSION,
		'Szerokość produktu z podstawą'            => self::KIND_DIMENSION,
		'Wysokość produktu z podstawą'             => self::KIND_DIMENSION,
		'Głębokość produktu z podstawą'            => self::KIND_DIMENSION,
		'Szerokość grilla'                         => self::KIND_DIMENSION,
		'Głębokość grilla'                         => self::KIND_DIMENSION,
		'Wysokość grilla'                          => self::KIND_DIMENSION,
		'Waga'                                     => self::KIND_WEIGHT,
		'Waga produktu'                            => self::KIND_WEIGHT,
		'Waga produktu z opakowaniem jednostkowym' => self::KIND_WEIGHT,
		'Waga z podstawą'                          => self::KIND_WEIGHT,
	);

	/**
	 * Przeliczniki jednostek długości → `cm` (baza kanoniczna), D-21.3.1 pkt 4
	 * (kontrakt §16) — pełny zestaw jednostek dostępnych w ustawieniach
	 * WooCommerce (`woocommerce_dimension_unit`), nie tylko `cm` widziane
	 * dziś lokalnie.
	 *
	 * @var array<string,float>
	 */
	private const LENGTH_TO_CM = array(
		'mm' => 0.1,
		'cm' => 1.0,
		'm'  => 100.0,
		'in' => 2.54,
		'yd' => 91.44,
	);

	/**
	 * Przeliczniki jednostek wagi → `g` (baza kanoniczna), D-21.3.1 pkt 4
	 * (kontrakt §16) — pełny zestaw jednostek dostępnych w ustawieniach
	 * WooCommerce (`woocommerce_weight_unit`), nie tylko `g`/`kg` widziane w
	 * próbce §15.
	 *
	 * @var array<string,float>
	 */
	private const WEIGHT_TO_G = array(
		'g'  => 1.0,
		'kg' => 1000.0,
		'oz' => 28.3495,
		'lb' => 453.592,
	);

	/**
	 * Bazy publicznych stron ofert per środowisko. Oferta nie niesie gotowego URL-a
	 * (mapping §2) — budujemy z `id`. Na sandboxie link ma prowadzić do sandboxa
	 * (inaczej `allegro_url` w dev wskazywałby nieistniejącą ofertę produkcyjną).
	 *
	 * @var array<string,string>
	 */
	private const OFFER_URL_BASE = array(
		Environment::PRODUCTION => 'https://allegro.pl/oferta/',
		Environment::SANDBOX    => 'https://allegro.pl.allegrosandbox.pl/oferta/',
	);

	/**
	 * `klasa_stanu` wyprowadzona z offer-level parametru „Stan" (mapping §2, D-4.1.1).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null Kod klasy (`A`-`D` albo `Nowe`) albo null (brak
	 *                     parametru / nieznana wartość).
	 */
	public static function condition_class( array $offer ): ?string {
		$value = self::parameter_value( self::offer_parameters( $offer ), 'Stan' );

		if ( null === $value ) {
			return null;
		}

		return self::CONDITION_MAP[ $value ] ?? null;
	}

	/**
	 * Surowa wartość parametru „Stan" (do raportu, gdy auto-mapa jej nie zna).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null
	 */
	public static function condition_raw( array $offer ): ?string {
		return self::parameter_value( self::offer_parameters( $offer ), 'Stan' );
	}

	/**
	 * Bieżąca zawartość auto-mapy Allegro „Stan" → `klasa_stanu` (D-4.1.1) —
	 * odczyt dla strony informacyjnej mapowania (P-12.1c, read-only,
	 * {@see \Qutlet\Allegro\OfferSync\ConditionMapPage}). Ta strona jest
	 * JEDYNYM konsumentem tego akcesora poza samą klasą — sama mapa zostaje
	 * `private const` (D-12.1c.2: bez mechanizmu edycji, zmiana mapowania to
	 * wciąż zmiana kodu, nie danych).
	 *
	 * @return array<string,string>
	 */
	public static function condition_map(): array {
		return self::CONDITION_MAP;
	}

	/**
	 * Marka wg reguły D-4.1.3: `Marka` ?? `Producent` (~40% ofert niesie markę
	 * wyłącznie jako „Producent" — mapping §3). Dopasowanie po NAZWIE parametru.
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null Znormalizowany (trim) term marki albo null.
	 */
	public static function brand( array $offer ): ?string {
		$params = self::product_parameters( $offer );
		$value  = self::parameter_value( $params, 'Marka' );

		if ( null === $value ) {
			$value = self::parameter_value( $params, 'Producent' );
		}

		if ( null === $value ) {
			return null;
		}

		$value = trim( $value );

		return '' === $value ? null : $value;
	}

	/**
	 * MPN — parametr produktowy `Kod producenta` (mapping §4b, kontrakt §10.1).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null
	 */
	public static function mpn( array $offer ): ?string {
		return self::parameter_value( self::product_parameters( $offer ), 'Kod producenta' );
	}

	/**
	 * GTIN — parametr produktowy `EAN (GTIN)` (mapping §4b, kontrakt §10.2 →
	 * natywne Woo `global_unique_id`).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null
	 */
	public static function gtin( array $offer ): ?string {
		return self::parameter_value( self::product_parameters( $offer ), 'EAN (GTIN)' );
	}

	/**
	 * Cena oferty (kanał Allegro) jako float — `sellingMode.price.amount` to STRING
	 * o zmiennym formacie (`"179.00"` vs `"179.0"`, mapping §6), bywa null dla
	 * ofert nie-ACTIVE.
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return float|null
	 */
	public static function price_amount( array $offer ): ?float {
		$amount = $offer['sellingMode']['price']['amount'] ?? null;

		if ( ! is_string( $amount ) || ! is_numeric( $amount ) ) {
			return null;
		}

		return (float) $amount;
	}

	/**
	 * Cena sklepu wg formuły D-4.1.2: `_price = cena_allegro × (1 − stawka/100)`,
	 * zaokrąglona do grosza (kontrakt §11).
	 *
	 * @param float $cena_allegro     Cena kanału Allegro (PLN).
	 * @param float $discount_percent Efektywna stawka rabatu w procentach.
	 * @return float
	 */
	public static function shop_price( float $cena_allegro, float $discount_percent ): float {
		return round( $cena_allegro * ( 1 - $discount_percent / 100 ), 2 );
	}

	/**
	 * Stan magazynowy — `stock.available` (mapping §1).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return int|null
	 */
	public static function stock_quantity( array $offer ): ?int {
		$available = $offer['stock']['available'] ?? null;

		return is_int( $available ) ? $available : null;
	}

	/**
	 * Publiczny URL oferty budowany z `id` per środowisko (mapping §2).
	 *
	 * @param string $environment Stała `Environment::SANDBOX`/`Environment::PRODUCTION`.
	 * @param string $offer_id    Id oferty.
	 * @return string
	 */
	public static function offer_url( string $environment, string $offer_id ): string {
		$base = self::OFFER_URL_BASE[ $environment ] ?? self::OFFER_URL_BASE[ Environment::PRODUCTION ];

		return $base . rawurlencode( $offer_id );
	}

	/**
	 * Środowisko POCHODZENIA produktu wyprowadzone z zapisanego `allegro_url`
	 * (odwrotność {@see self::offer_url()}) — D-6.2.2: push/pull stanu wolno
	 * kierować tylko do środowiska, z którego produkt został zaimportowany.
	 * Prefiksy są jednoznaczne (baza produkcyjna kończy się `pl/oferta/`,
	 * sandboxowa `allegrosandbox.pl/oferta/` — żaden nie jest prefiksem drugiego).
	 *
	 * @param string $url Zapisany `allegro_url` produktu.
	 * @return string|null Stała `Environment::*` albo null (URL nierozpoznany /
	 *                     produkt nie pochodzi z importu).
	 */
	public static function environment_from_offer_url( string $url ): ?string {
		foreach ( self::OFFER_URL_BASE as $environment => $base ) {
			if ( 0 === strpos( $url, $base ) ) {
				return $environment;
			}
		}

		return null;
	}

	/**
	 * Lista URL-i zdjęć oferty — `images[]` to tablica GOŁYCH stringów-URL-i
	 * (zweryfikowane w realnej zwrotce; na liście ofert jest inaczej — obiekty).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return array<int,string>
	 */
	public static function image_urls( array $offer ): array {
		if ( ! isset( $offer['images'] ) || ! is_array( $offer['images'] ) ) {
			return array();
		}

		$urls = array();

		foreach ( $offer['images'] as $url ) {
			if ( is_string( $url ) && '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Surowy opis prozą: sekcje `description.sections[].items[]` typu `TEXT`
	 * sklejone w jeden HTML (kontrakt §9.1 — `_qutlet_allegro_description_raw`);
	 * obrazy (`IMAGE`) pomijane — są w verbatim JSON.
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string Pusty string, gdy oferta nie ma opisu tekstowego.
	 */
	public static function description_raw( array $offer ): string {
		$sections = $offer['description']['sections'] ?? null;

		if ( ! is_array( $sections ) ) {
			return '';
		}

		$chunks = array();

		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || ! isset( $section['items'] ) || ! is_array( $section['items'] ) ) {
				continue;
			}

			foreach ( $section['items'] as $item ) {
				if ( ! is_array( $item ) || 'TEXT' !== ( $item['type'] ?? null ) ) {
					continue;
				}

				$content = $item['content'] ?? null;

				if ( is_string( $content ) && '' !== trim( $content ) ) {
					$chunks[] = $content;
				}
			}
		}

		return implode( "\n", $chunks );
	}

	/**
	 * Surowa specyfikacja: `productSet[0].product.parameters[]` spłaszczone do par
	 * `{etykieta, wartosc}` (kontrakt §9.1): wiele `values[]` sklejane przecinkiem,
	 * `rangeValue {from,to}` → string zakresu `from–to`. Parametry mapowane wprost
	 * gdzie indziej (Marka, Stan…) ZOSTAJĄ też tu — surowy podgląd oryginału
	 * (dopuszczone wprost w kontrakcie §9.1, „decyzja parsera przy sync").
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return array<int,array{etykieta:string,wartosc:string}>
	 */
	public static function specification( array $offer ): array {
		$rows = array();

		foreach ( self::product_parameters( $offer ) as $param ) {
			if ( ! is_array( $param ) || ! isset( $param['name'] ) || ! is_string( $param['name'] ) ) {
				continue;
			}

			$value = '';

			if ( isset( $param['values'] ) && is_array( $param['values'] ) ) {
				$parts = array();

				foreach ( $param['values'] as $part ) {
					if ( is_string( $part ) && '' !== trim( $part ) ) {
						$parts[] = trim( $part );
					}
				}

				$value = implode( ', ', $parts );
			}

			if ( '' === $value && isset( $param['rangeValue'] ) && is_array( $param['rangeValue'] ) ) {
				$from = $param['rangeValue']['from'] ?? null;
				$to   = $param['rangeValue']['to'] ?? null;

				if ( is_string( $from ) && is_string( $to ) && '' !== $from && '' !== $to ) {
					$value = $from . '–' . $to;
				}
			}

			if ( '' === $value ) {
				continue;
			}

			$rows[] = array(
				'etykieta' => $param['name'],
				'wartosc'  => $value,
			);
		}

		return $rows;
	}

	/**
	 * `nazwa => id` dla kandydatów wagowo-wymiarowych ({@see self::WEIGHT_DIMENSION_CANDIDATES},
	 * D-21.3.1, kontrakt §16) FAKTYCZNIE obecnych w parametrach produktowych
	 * tej oferty. Zwraca `[]`, gdy oferta nie ma żadnego kandydata — sygnał
	 * dla wołającego (P-21.3b, `ImportOffersCommand`), że nie trzeba odpytywać
	 * słownika parametrów kategorii ({@see \Qutlet\Allegro\OfferSync\CategoryParameterUnits}),
	 * unika zbędnego żądania HTTP per oferta/kategoria.
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return array<string,string>
	 */
	public static function weight_dimension_param_ids( array $offer ): array {
		$ids = array();

		foreach ( self::product_parameters( $offer ) as $param ) {
			if ( ! is_array( $param ) || ! isset( $param['name'], $param['id'] ) || ! is_string( $param['name'] ) ) {
				continue;
			}

			if ( ! isset( self::WEIGHT_DIMENSION_CANDIDATES[ $param['name'] ] ) ) {
				continue;
			}

			$ids[ $param['name'] ] = (string) $param['id'];
		}

		return $ids;
	}

	/**
	 * `nazwa => "wartość jednostka"` (np. `"0.83 kg"`) dla kandydatów
	 * wagowo-wymiarowych z tej oferty (D-21.3.1, kontrakt §16) — wartość
	 * PRZELICZONA do jednostki sklepu, gdy jednostka Allegro (rozstrzygnięta
	 * WYŁĄCZNIE przez `id` w `$category_units`, §15) się różni. Dotyczy tylko
	 * warstwy przerobionej (atrybuty WC) — {@see self::specification()}
	 * (warstwa surowa) zostaje nietknięta, wołający wywołuje obie osobno.
	 *
	 * Kandydat bez rozstrzygniętej jednostki (`id` nieobecne w
	 * `$category_units`, albo jednostka źródłowa/docelowa nierozpoznana przez
	 * {@see self::LENGTH_TO_CM}/{@see self::WEIGHT_TO_G}) jest POMIJANY w
	 * wyniku — wołający (P-21.3b, `ProductWriter`) zostawia oryginalną
	 * wartość specyfikacji bez zmian i loguje ostrzeżenie (D-21.3.1 pkt 3).
	 *
	 * @param array<string,mixed>  $offer           Pełna zwrotka oferty.
	 * @param array<string,string> $category_units  `id => unit` ze słownika parametrów kategorii tej oferty.
	 * @param string                $dimension_unit Docelowa jednostka długości (`woocommerce_dimension_unit`).
	 * @param string                $weight_unit    Docelowa jednostka wagi (`woocommerce_weight_unit`).
	 * @return array<string,string>
	 */
	public static function weight_dimension_attributes( array $offer, array $category_units, string $dimension_unit, string $weight_unit ): array {
		$result = array();

		foreach ( self::weight_dimension_param_ids( $offer ) as $name => $id ) {
			$unit = $category_units[ $id ] ?? null;

			if ( null === $unit ) {
				continue;
			}

			$raw_value = self::parameter_value( self::product_parameters( $offer ), $name );

			if ( null === $raw_value || ! is_numeric( $raw_value ) ) {
				continue;
			}

			$kind        = self::WEIGHT_DIMENSION_CANDIDATES[ $name ];
			$target_unit = self::KIND_DIMENSION === $kind ? $dimension_unit : $weight_unit;
			$table       = self::KIND_DIMENSION === $kind ? self::LENGTH_TO_CM : self::WEIGHT_TO_G;
			$converted   = self::convert_unit( (float) $raw_value, $unit, $target_unit, $table );

			if ( null === $converted ) {
				continue;
			}

			$result[ $name ] = self::format_converted_value( $converted ) . ' ' . $target_unit;
		}

		return $result;
	}

	/**
	 * Przelicza wartość między dwiema jednostkami przez wspólną bazę
	 * kanoniczną (`$table`: jednostka → mnożnik do bazy). `null`, gdy
	 * jednostka źródłowa LUB docelowa nie jest w tabeli — degradacja
	 * (D-21.3.1 pkt 3), nie zgadywanie.
	 *
	 * @param float                $value Wartość źródłowa.
	 * @param string                $from Jednostka źródłowa (Allegro, ze słownika kategorii).
	 * @param string                $to   Jednostka docelowa (ustawienie sklepu).
	 * @param array<string,float>  $table Mnożniki jednostka → baza kanoniczna ({@see self::LENGTH_TO_CM}/{@see self::WEIGHT_TO_G}).
	 * @return float|null
	 */
	private static function convert_unit( float $value, string $from, string $to, array $table ): ?float {
		if ( ! isset( $table[ $from ], $table[ $to ] ) ) {
			return null;
		}

		return $value * $table[ $from ] / $table[ $to ];
	}

	/**
	 * Formatuje liczbę przeliczoną bez zbędnych zer (wzorem {@see self::vat_rate()}
	 * — `rtrim` ogonków), max 3 miejsca po przecinku (wystarcza na precyzję
	 * `g → kg`, §15: wartości wagowe w próbce mają precyzję całkowitą/2-miejscową).
	 *
	 * @param float $value Wartość przeliczona.
	 * @return string
	 */
	private static function format_converted_value( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	/**
	 * Stawka VAT z `taxSettings.rates[0].rate` (mapping §4d; w 503/555 ofert
	 * snapshotu niepuste, w zredagowanej próbce `null` — ufać snapshotowi).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return string|null Stawka znormalizowana (np. `"23"`, `"8.5"`) albo null.
	 */
	public static function vat_rate( array $offer ): ?string {
		$rates = $offer['taxSettings']['rates'] ?? null;

		if ( ! is_array( $rates ) || array() === $rates ) {
			return null;
		}

		$first = reset( $rates );
		$rate  = is_array( $first ) ? ( $first['rate'] ?? null ) : null;

		if ( ! is_string( $rate ) || ! is_numeric( $rate ) ) {
			return null;
		}

		// Normalizacja do postaci bez ogonków zer ("23.00" → "23", "8.50" → "8.5").
		$trimmed = rtrim( rtrim( number_format( (float) $rate, 2, '.', '' ), '0' ), '.' );

		return '' === $trimmed ? '0' : $trimmed;
	}

	/**
	 * Offer-level `parameters[]` (tu żyje „Stan" — mapping §2).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return array<int,mixed>
	 */
	private static function offer_parameters( array $offer ): array {
		$params = $offer['parameters'] ?? null;

		return is_array( $params ) ? array_values( $params ) : array();
	}

	/**
	 * Parametry PRODUKTOWE `productSet[0].product.parameters[]` (marka, EAN, MPN,
	 * specyfikacja — mapping §3/§4b). Mapping zakłada pojedynczy `productSet[0]`;
	 * ofertę o `count > 1` odrzuca wcześniej komenda (mapping §6).
	 *
	 * @param array<string,mixed> $offer Pełna zwrotka oferty.
	 * @return array<int,mixed>
	 */
	private static function product_parameters( array $offer ): array {
		$params = $offer['productSet'][0]['product']['parameters'] ?? null;

		return is_array( $params ) ? array_values( $params ) : array();
	}

	/**
	 * Pierwsza wartość (`values[0]`) parametru o podanej NAZWIE (dokładne `===`).
	 *
	 * @param array<int,mixed> $parameters Lista parametrów.
	 * @param string           $name       Nazwa parametru (VERBATIM, case-sensitive).
	 * @return string|null
	 */
	private static function parameter_value( array $parameters, string $name ): ?string {
		foreach ( $parameters as $param ) {
			if ( ! is_array( $param ) || ( $param['name'] ?? null ) !== $name ) {
				continue;
			}

			$value = $param['values'][0] ?? null;

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}

			return null;
		}

		return null;
	}
}
