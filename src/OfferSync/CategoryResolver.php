<?php
/**
 * Slice OfferSync — rozdzielczość kategorii Allegro `id` → ścieżka (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Rozwija `category.id` oferty do nazwanej ścieżki liść→korzeń przez API drzewa
 * (mapping §7b): `GET /sale/categories/{id}` i dalej po `parent.id`, dopóki
 * `parent != null`. Oferta niesie WYŁĄCZNIE `{ id }` — bez nazwy i przodków (§7a).
 *
 * Transport jest WSTRZYKNIĘTY (callable), bo HTTP żyje w komendzie (trait
 * `AllegroCliSupport`) — dzięki temu traversal i cache są testowalne bez sieci.
 * Cache per przebieg (mapa `id → węzeł`): drzewo jest stabilne, a 126 liści
 * katalogu dzieli przodków, więc bez cache import młóciłby te same węzły
 * (D-6.G2 — nie mielimy API bez potrzeby).
 *
 * Kształt węzła zwracany przez fetcher (klucze VERBATIM z `GET /sale/categories/{id}`,
 * próbka P-3.2): `{ id: string, name: string, parent: { id: string }|null }`.
 */
final class CategoryResolver {

	/**
	 * Bezpiecznik pętli: maksymalna głębokość drzewa (realne ścieżki mają 2–6
	 * węzłów; pętla dłuższa oznacza cykl albo uszkodzone dane).
	 */
	private const MAX_DEPTH = 20;

	/**
	 * Fetcher węzła: `fn( string $id ): ?array` — pełna zdekodowana zwrotka
	 * `GET /sale/categories/{id}` albo null przy błędzie HTTP.
	 *
	 * @var callable
	 */
	private $fetch_node;

	/**
	 * Cache węzłów per przebieg: id → `{id, name, parent_id}` albo null
	 * (zapamiętany błąd — nie ponawiamy żądania o ten sam węzeł).
	 *
	 * @var array<string,array{id:string,name:string,parent_id:?string}|null>
	 */
	private $cache = array();

	/**
	 * @param callable $fetch_node Fetcher węzła (patrz docblock klasy).
	 */
	public function __construct( callable $fetch_node ) {
		$this->fetch_node = $fetch_node;
	}

	/**
	 * Ścieżka liść→korzeń dla `category.id` oferty.
	 *
	 * Zwraca tyle, ile dało się rozwiązać: pusta tablica = nie rozwiązano nawet
	 * liścia (błąd HTTP); ścieżka bez korzenia = przerwany traversal (błąd w
	 * połowie drzewa) — wywołujący i tak dopasuje reguły do znanych węzłów,
	 * a nierozwiązaną resztę zaloguje.
	 *
	 * @param string $leaf_id `category.id` z oferty (opaque string).
	 * @return array<int,array{id:string,name:string}>
	 */
	public function path( string $leaf_id ): array {
		$path    = array();
		$current = $leaf_id;

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			if ( '' === $current ) {
				break;
			}

			$node = $this->node( $current );

			if ( null === $node ) {
				break;
			}

			$path[] = array(
				'id'   => $node['id'],
				'name' => $node['name'],
			);

			if ( null === $node['parent_id'] ) {
				break; // Korzeń.
			}

			$current = $node['parent_id'];
		}

		return $path;
	}

	/**
	 * Węzeł z cache albo od fetchera (wynik — także negatywny — jest zapamiętywany).
	 *
	 * @param string $id Id węzła.
	 * @return array{id:string,name:string,parent_id:?string}|null
	 */
	private function node( string $id ): ?array {
		if ( array_key_exists( $id, $this->cache ) ) {
			return $this->cache[ $id ];
		}

		$raw  = ( $this->fetch_node )( $id );
		$node = null;

		if ( is_array( $raw ) && isset( $raw['id'], $raw['name'] ) && is_string( $raw['name'] ) ) {
			$parent_id = null;

			if ( isset( $raw['parent']['id'] ) && is_string( $raw['parent']['id'] ) && '' !== $raw['parent']['id'] ) {
				$parent_id = $raw['parent']['id'];
			}

			$node = array(
				'id'        => (string) $raw['id'],
				'name'      => $raw['name'],
				'parent_id' => $parent_id,
			);
		}

		$this->cache[ $id ] = $node;

		return $node;
	}
}
