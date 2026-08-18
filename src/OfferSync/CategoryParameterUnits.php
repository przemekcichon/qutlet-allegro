<?php
/**
 * Slice OfferSync — słownik jednostek parametrów kategorii Allegro (P-21.3b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Resolucja `id parametru → jednostka` per kategoria, przez
 * `GET /sale/categories/{id}/parameters` (D-21.3.1, kontrakt §16 —
 * jednostka wagi/wymiaru rozstrzygana WYŁĄCZNIE przez `id`, nigdy z nazwy,
 * bo ta sama nazwa bywa różnym `id` — i różną jednostką — w różnych
 * kategoriach, §15).
 *
 * Transport jest WSTRZYKNIĘTY (callable), bo HTTP żyje w komendzie (trait
 * `AllegroCliSupport`) — wzorem {@see CategoryResolver}, dzięki temu
 * resolucja i cache są testowalne bez sieci. Cache per przebieg (mapa
 * `categoryId → id→unit`): kategorie ofert w jednym imporcie się powtarzają,
 * więc bez cache import odpytywałby słownik tej samej kategorii wielokrotnie
 * (D-6.G2 — nie mielimy API bez potrzeby).
 *
 * Kształt zwrotki fetchera (klucze VERBATIM z `GET /sale/categories/{id}/parameters`,
 * zweryfikowane w `docs/allegro-api-samples/GET_sale-categories-id-parameters.json`):
 * `{ parameters: [ { id: string, unit: string|null, ... } ] }`.
 */
final class CategoryParameterUnits {

	/**
	 * Fetcher słownika parametrów kategorii: `fn( string $categoryId ): ?array` —
	 * pełna zdekodowana zwrotka `GET /sale/categories/{id}/parameters` albo
	 * `null` przy błędzie HTTP.
	 *
	 * @var callable
	 */
	private $fetch_parameters;

	/**
	 * Cache słowników per przebieg: `categoryId → (id → unit)`. Kategoria bez
	 * rozstrzygniętego słownika (błąd HTTP) jest zapamiętana jako `[]` — nie
	 * ponawiamy żądania o tę samą kategorię w tym przebiegu.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $cache = array();

	/**
	 * @param callable $fetch_parameters Fetcher słownika (patrz docblock klasy).
	 */
	public function __construct( callable $fetch_parameters ) {
		$this->fetch_parameters = $fetch_parameters;
	}

	/**
	 * `id parametru → jednostka` dla podanej kategorii. Parametry bez `unit`
	 * (np. wybory tekstowe bez miana) są pomijane — nie niosą jednostki do
	 * rozstrzygnięcia.
	 *
	 * @param string $category_id `category.id` z oferty (opaque string, §7a).
	 * @return array<string,string>
	 */
	public function units_for_category( string $category_id ): array {
		if ( array_key_exists( $category_id, $this->cache ) ) {
			return $this->cache[ $category_id ];
		}

		$raw        = ( $this->fetch_parameters )( $category_id );
		$parameters = is_array( $raw ) && isset( $raw['parameters'] ) && is_array( $raw['parameters'] )
			? $raw['parameters']
			: array();

		$units = array();

		foreach ( $parameters as $parameter ) {
			if ( ! is_array( $parameter ) || ! isset( $parameter['id'], $parameter['unit'] ) || ! is_string( $parameter['unit'] ) || '' === $parameter['unit'] ) {
				continue;
			}

			$units[ (string) $parameter['id'] ] = $parameter['unit'];
		}

		$this->cache[ $category_id ] = $units;

		return $units;
	}
}
