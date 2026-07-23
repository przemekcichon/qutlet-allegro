<?php
/**
 * Slice OfferSync — reguły kolapsu kategorii Allegro → `product_cat` (P-6.1b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Kuratorski kolaps N:1 (D-4.2.1) z hybrydowym kluczowaniem (D-4.2.2): regułę
 * dopasowujemy po NAJBLIŻSZYM przodku z regułą, a pojedynczy liść może ją
 * nadpisać wyjątkiem. Priorytet: wyjątek per-liść > reguła gałęzi > reguła
 * gałęzi wyższej. Ścieżka wejściowa idzie od liścia do korzenia (mapping §7b),
 * więc pierwsze trafienie przy przejściu tablicy JEST najbliższe.
 *
 * Tabela startowa = węzły rozwiązywalne z próbek (mapping §7d — jawnie
 * ILUSTRACYJNA, nie pełna). Oferta bez żadnej reguły dostaje term-kosz
 * `pozostale` (D-6.1.2), a komenda loguje nierozwiązaną gałąź (id + nazwy),
 * żeby kurator dopisał tu regułę — tabela ROŚNIE w toku kuracji (mapping §7e);
 * ustabilizowane slugi wracają do `kontrakt-danych.md` §1.
 *
 * Id kategorii to opaque stringi (liść bywa numeryczny, korzeń bywa UUID — §7a).
 * Uwaga środowiska: id sandboxa są dziś 1:1 z produkcją (pomiar
 * `sandbox-preflight`, 126/126), więc jedna tabela obsługuje oba; po kwartalnym
 * przetasowaniu sandboxa rozjazd ujawni się jako wpisy w logu nierozwiązanych.
 */
final class CategoryMapRules {

	/**
	 * Slug termu-kosza dla ofert bez reguły (D-6.1.2).
	 */
	public const FALLBACK_SLUG = 'pozostale';

	/**
	 * Wyjątki per-liść: `category.id` oferty → slug `product_cat` (priorytet 1).
	 *
	 * Klucz `array-key`, nie `string`: id numeryczne PHP rzutuje na int w kluczu
	 * tablicy (ta sama pułapka co w `SandboxSeed\IdMap`); odczyt stringiem trafia,
	 * bo PHP normalizuje klucz po obu stronach.
	 *
	 * @var array<array-key,string>
	 */
	private const LEAF_RULES = array(
		'85166' => 'audio',     // „Bezprzewodowe" — słuchawki BT (oferta 18780385602, P-3.1).
		'4575'  => 'peryferia', // Myszy (P-3.1 index.csv) — term spoza czwórki prototypu (mapping §7e).
	);

	/**
	 * Reguły gałęzi: id przodka → slug `product_cat` (priorytet wg bliskości
	 * liścia). Klucz `array-key` — jak wyżej.
	 *
	 * @var array<array-key,string>
	 */
	private const BRANCH_RULES = array(
		'4'      => 'smartfony', // „Telefony i Akcesoria".
		'2'      => 'laptopy',   // „Komputery".
		'122233' => 'gaming',    // „Konsole i automaty".
		'122332' => 'audio',     // „Sprzęt estradowy, studyjny i DJ-ski".
	);

	/**
	 * Czytelne nazwy termów per slug (do utworzenia termu przy pierwszym użyciu).
	 *
	 * @var array<string,string>
	 */
	private const TERM_NAMES = array(
		'smartfony' => 'Smartfony',
		'laptopy'   => 'Laptopy',
		'audio'     => 'Audio',
		'gaming'    => 'Gaming',
		'peryferia' => 'Peryferia',
		'pozostale' => 'Pozostałe',
	);

	/**
	 * Dopasowuje slug `product_cat` do rozwiązanej ścieżki kategorii.
	 *
	 * Brak reguły → null (fallback `pozostale` + log to decyzja WYWOŁUJĄCEGO —
	 * komenda musi wiedzieć, że ścieżka jest nierozwiązana, żeby ją zalogować).
	 *
	 * @param array<int,array{id:string,name:string}> $path Ścieżka liść→korzeń (mapping §7b).
	 * @return string|null Slug termu albo null (żadna reguła nie łapie).
	 */
	public static function resolve_slug( array $path ): ?string {
		if ( array() === $path ) {
			return null;
		}

		$leaf_id = $path[0]['id'];

		if ( isset( self::LEAF_RULES[ $leaf_id ] ) ) {
			return self::LEAF_RULES[ $leaf_id ];
		}

		// Od liścia w górę — pierwsze trafienie to najbliższy przodek z regułą.
		foreach ( $path as $node ) {
			if ( isset( self::BRANCH_RULES[ $node['id'] ] ) ) {
				return self::BRANCH_RULES[ $node['id'] ];
			}
		}

		return null;
	}

	/**
	 * Czytelna nazwa termu dla sluga (fallback: slug z wielką literą).
	 *
	 * @param string $slug Slug termu `product_cat`.
	 * @return string
	 */
	public static function term_name( string $slug ): string {
		return self::TERM_NAMES[ $slug ] ?? ucfirst( $slug );
	}
}
