<?php
/**
 * Slice SandboxSeed — tablica mapowania identyfikatorów produkcja → sandbox (D-3A.G5).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\SandboxSeed;

use RuntimeException;

/**
 * Tłumaczy identyfikatory kategorii, parametrów i wartości słownikowych z produkcji na
 * sandbox. Warstwa istnieje NA MOCY DECYZJI (D-3A.G5, sesja 2026-07-22), mimo że pomiar
 * z `sandbox-preflight` pokazał dziś odwzorowanie 1:1 (126/126 kategorii pod tym samym id,
 * 555/555 ofert z parametrami walidującymi się wobec słowników sandboxa). Powód: Allegro
 * odświeża listę kategorii i parametrów sandboxa kwartalnie, więc tożsamość jest stanem
 * ZMIERZONYM DZIŚ, nie własnością środowiska — a bez tej warstwy pierwsze przetasowanie
 * przechodzi bezgłośnie i zasiew wysyła id, których już nie ma.
 *
 * Semantyka celowo BEZ cichego fallbacku do tożsamości: brak wpisu = brak mapowania, a nie
 * „pewnie to samo". Zasiew pomija taki byt i odnotowuje go w raporcie, dzięki czemu kwartalne
 * przetasowanie widać jako listę pominięć, zamiast jako serię odrzuceń z API.
 *
 * Plik mapy jest GENEROWANY z pomiaru (`sandbox-preflight --write-id-map`), nie pisany
 * ręcznie — zawiera wyłącznie identyfikatory, których obecność w sandboxie potwierdzono
 * żądaniem. Po kwartalnym czyszczeniu: uruchom preflight ponownie i zdiffuj mapę.
 *
 * Kształt pliku:
 * {
 *   "generatedAt": "2026-07-22T…Z",
 *   "categories":      { "<prodCategoryId>":  "<sandboxCategoryId>" },
 *   "parameters":      { "<prodParameterId>": "<sandboxParameterId>" },
 *   "parameterValues": { "<prodValueId>":     "<sandboxValueId>" }
 * }
 *
 * Uwaga na klucze: identyfikatory kategorii i parametrów są napisami numerycznymi, a PHP
 * zamienia taki klucz tablicy na `int`. Odczyt stringiem i tak trafia (PHP normalizuje klucz
 * po obu stronach), ale zwracana wartość jest rzutowana na `string` — nigdy nie wypuszczamy
 * `int` do kodu, który dalej deklaruje `string` przy `strict_types`.
 */
final class IdMap {

	/**
	 * Mapowanie kategorii: id produkcyjne → id sandboxowe.
	 *
	 * @var array<array-key,string>
	 */
	private $categories;

	/**
	 * Mapowanie parametrów: id produkcyjne → id sandboxowe.
	 *
	 * @var array<array-key,string>
	 */
	private $parameters;

	/**
	 * Mapowanie wartości słownikowych: id produkcyjne → id sandboxowe.
	 *
	 * @var array<array-key,string>
	 */
	private $values;

	/**
	 * @param array<array-key,string> $categories Mapowanie kategorii.
	 * @param array<array-key,string> $parameters Mapowanie parametrów.
	 * @param array<array-key,string> $values     Mapowanie wartości słownikowych.
	 */
	private function __construct( array $categories, array $parameters, array $values ) {
		$this->categories = $categories;
		$this->parameters = $parameters;
		$this->values     = $values;
	}

	/**
	 * Wczytuje mapę z pliku JSON.
	 *
	 * @param string $path Ścieżka pliku mapy.
	 * @return self
	 *
	 * @throws RuntimeException Gdy pliku nie ma, nie da się go sparsować albo nie ma kategorii.
	 */
	public static function from_file( string $path ): self {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException(
				sprintf(
					'Nie mogę odczytać mapy identyfikatorów: %s. Wygeneruj ją pomiarem: '
					. 'wp qutlet-allegro sandbox-preflight --write-id-map=<plik>.',
					$path
				)
			);
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalny plik konfiguracji narzędzia CLI.

		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( sprintf( 'Mapa identyfikatorów nie jest poprawnym JSON-em: %s', $path ) );
		}

		$categories = self::section( $decoded, 'categories' );

		if ( array() === $categories ) {
			throw new RuntimeException(
				sprintf( 'Mapa %s nie zawiera ani jednej kategorii — zasiew nie miałby czego wysłać.', $path )
			);
		}

		return new self(
			$categories,
			self::section( $decoded, 'parameters' ),
			self::section( $decoded, 'parameterValues' )
		);
	}

	/**
	 * Sandboxowe id kategorii albo `null`, gdy kategoria nie jest zmapowana.
	 *
	 * @param string $production_id Id kategorii z produkcji.
	 * @return string|null
	 */
	public function category( string $production_id ): ?string {
		return isset( $this->categories[ $production_id ] ) ? (string) $this->categories[ $production_id ] : null;
	}

	/**
	 * Sandboxowe id parametru albo `null`, gdy parametr nie jest zmapowany.
	 *
	 * @param string $production_id Id parametru z produkcji.
	 * @return string|null
	 */
	public function parameter( string $production_id ): ?string {
		return isset( $this->parameters[ $production_id ] ) ? (string) $this->parameters[ $production_id ] : null;
	}

	/**
	 * Sandboxowe id wartości słownikowej albo `null`, gdy wartość nie jest zmapowana.
	 *
	 * @param string $production_id Id wartości z produkcji.
	 * @return string|null
	 */
	public function value( string $production_id ): ?string {
		return isset( $this->values[ $production_id ] ) ? (string) $this->values[ $production_id ] : null;
	}

	/**
	 * Rozmiary sekcji mapy — do raportu przebiegu.
	 *
	 * @return array{categories:int,parameters:int,parameter_values:int}
	 */
	public function sizes(): array {
		return array(
			'categories'       => count( $this->categories ),
			'parameters'       => count( $this->parameters ),
			'parameter_values' => count( $this->values ),
		);
	}

	/**
	 * Wyciąga sekcję mapy, przepuszczając wyłącznie skalarne pary klucz → wartość.
	 *
	 * @param array<array-key,mixed> $decoded Sparsowany plik mapy.
	 * @param string                 $key     Nazwa sekcji.
	 * @return array<array-key,string>
	 */
	private static function section( array $decoded, string $key ): array {
		if ( ! isset( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
			return array();
		}

		$section = array();

		foreach ( $decoded[ $key ] as $from => $to ) {
			if ( is_string( $to ) && '' !== $to ) {
				$section[ $from ] = $to;
			}
		}

		return $section;
	}
}
