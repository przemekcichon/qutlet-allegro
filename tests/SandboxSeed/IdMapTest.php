<?php
/**
 * Testy jednostkowe SandboxSeed\IdMap (P-6.0 — warunek wejścia FAZY 6).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Tests\SandboxSeed;

use Qutlet\Allegro\SandboxSeed\IdMap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Charakteryzuje niezmiennik, na którym stoi cały zasiew (P-3A.2): „brak cichego
 * fallbacku do tożsamości". Brak wpisu w mapie MUSI dać `null` (oferta/parametr
 * pominięty), a NIE „pewnie to samo" — inaczej pierwsze kwartalne przetasowanie
 * kategorii przechodzi bezgłośnie i zasiew wysyła nieistniejące id.
 *
 * Powód spisania TERAZ (nie w P-3A.2): refaktor P-6.0 przepina komendy obu slice'ów
 * na wspólną powierzchnię HTTP/CLI i jest zdarzeniem, które ten niezmiennik mogłoby
 * po cichu złamać. Do dziś trzymał się wyłącznie na czytaniu kodu.
 *
 * Testy są BEZ SIECI — {@see IdMap} czyta wyłącznie plik JSON.
 */
final class IdMapTest extends TestCase {

	/**
	 * Katalog roboczy na pliki-mapy tego testu (sprzątany w tearDown).
	 *
	 * @var string
	 */
	private $work_dir = '';

	protected function setUp(): void {
		parent::setUp();

		$dir = sys_get_temp_dir() . '/qutlet-idmap-' . uniqid( '', true );

		if ( ! mkdir( $dir ) && ! is_dir( $dir ) ) {
			self::fail( 'Nie mogę utworzyć katalogu roboczego testu: ' . $dir );
		}

		$this->work_dir = $dir;
	}

	protected function tearDown(): void {
		if ( '' !== $this->work_dir && is_dir( $this->work_dir ) ) {
			foreach ( (array) glob( $this->work_dir . '/*' ) as $file ) {
				unlink( (string) $file );
			}

			rmdir( $this->work_dir );
		}

		$this->work_dir = '';

		parent::tearDown();
	}

	/**
	 * Brak pliku mapy → wyjątek (nie ciche puste mapowanie).
	 */
	public function test_missing_file_throws(): void {
		$missing = $this->work_dir . '/nie-ma-mnie.json';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nie mogę odczytać mapy identyfikatorów' );

		IdMap::from_file( $missing );
	}

	/**
	 * Plik nie-JSON → wyjątek (nie milcząca degradacja do pustej mapy).
	 */
	public function test_non_json_file_throws(): void {
		$path = $this->write_map( 'to nie jest json' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'nie jest poprawnym JSON-em' );

		IdMap::from_file( $path );
	}

	/**
	 * Pusta sekcja `categories` → wyjątek: bez ani jednej kategorii zasiew nie miałby
	 * czego wysłać, a cicha pusta mapa wyglądałaby jak „wszystko pominięte".
	 */
	public function test_empty_categories_section_throws(): void {
		$path = $this->write_map(
			(string) json_encode(
				array(
					'categories'      => array(),
					'parameters'      => array( '111' => '222' ),
					'parameterValues' => array( '333' => '444' ),
				)
			)
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'nie zawiera ani jednej kategorii' );

		IdMap::from_file( $path );
	}

	/**
	 * Brak KLUCZA `categories` w ogóle → ten sam wyjątek co pusta sekcja
	 * (sekcja nieobecna jest traktowana jak pusta).
	 */
	public function test_absent_categories_key_throws(): void {
		$path = $this->write_map(
			(string) json_encode( array( 'parameters' => array( '111' => '222' ) ) )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'nie zawiera ani jednej kategorii' );

		IdMap::from_file( $path );
	}

	/**
	 * Kategoria bez wpisu → `null` (NIE tożsamość). To rdzeń niezmiennika.
	 */
	public function test_unmapped_category_returns_null(): void {
		$map = IdMap::from_file( $this->valid_map() );

		self::assertNull( $map->category( '99999' ) );
	}

	/**
	 * Kategoria z wpisem → zmapowane sandboxowe id.
	 */
	public function test_mapped_category_returns_sandbox_id(): void {
		$map = IdMap::from_file( $this->valid_map() );

		self::assertSame( '260041-sb', $map->category( '260041' ) );
	}

	/**
	 * Parametr i wartość słownikowa: brak wpisu → `null`, wpis → zmapowane id.
	 */
	public function test_parameter_and_value_mapping(): void {
		$map = IdMap::from_file( $this->valid_map() );

		self::assertSame( '11323-sb', $map->parameter( '11323' ) );
		self::assertNull( $map->parameter( 'brak' ) );

		self::assertSame( 'v-2-sb', $map->value( 'v-1' ) );
		self::assertNull( $map->value( 'brak' ) );
	}

	/**
	 * Klucze numeryczne: PHP zamienia `"260041"` w kluczu tablicy na `int`, ale odczyt
	 * stringiem musi trafić, a zwracana wartość musi być `string` (kontrakt `?string`).
	 */
	public function test_numeric_string_keys_normalize(): void {
		$map = IdMap::from_file( $this->valid_map() );

		$result = $map->category( '260041' );

		self::assertIsString( $result );
		self::assertSame( '260041-sb', $result );
	}

	/**
	 * `section()` przepuszcza wyłącznie skalarne, niepuste stringi — wpisy nie-string
	 * i puste są odrzucane, więc nie da się przez nie przemycić „mapowania".
	 */
	public function test_non_string_and_empty_values_are_dropped(): void {
		$path = $this->write_map(
			(string) json_encode(
				array(
					'categories'      => array(
						'good'   => 'good-sb',
						'empty'  => '',
						'nested' => array( 'x' => 'y' ),
						'numish' => 123,
					),
					'parameterValues' => array( 'p' => 'p-sb' ),
				)
			)
		);

		$map = IdMap::from_file( $path );

		self::assertSame( 'good-sb', $map->category( 'good' ) );
		self::assertNull( $map->category( 'empty' ) );
		self::assertNull( $map->category( 'nested' ) );
		self::assertNull( $map->category( 'numish' ) );
		self::assertSame(
			array(
				'categories'       => 1,
				'parameters'       => 0,
				'parameter_values' => 1,
			),
			$map->sizes()
		);
	}

	/**
	 * Zapisuje treść jako plik mapy w katalogu roboczym i zwraca jego ścieżkę.
	 *
	 * @param string $contents Treść pliku.
	 * @return string Ścieżka pliku.
	 */
	private function write_map( string $contents ): string {
		$path = $this->work_dir . '/id-map-' . uniqid( '', true ) . '.json';

		file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Zapisuje minimalną, poprawną mapę i zwraca jej ścieżkę.
	 *
	 * @return string
	 */
	private function valid_map(): string {
		return $this->write_map(
			(string) json_encode(
				array(
					'generatedAt'     => '2026-07-22T00:00:00Z',
					'categories'      => array( '260041' => '260041-sb' ),
					'parameters'      => array( '11323' => '11323-sb' ),
					'parameterValues' => array( 'v-1' => 'v-2-sb' ),
				)
			)
		);
	}
}
