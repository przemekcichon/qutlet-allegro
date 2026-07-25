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
 * Tabela ustabilizowana kuracją P-6.8b (sesja 2026-07-25, mapping §7e) na
 * podstawie pełnego raportu 120 liści (`wp qutlet-allegro category-report`).
 * Zastępuje startową, jawnie ILUSTRACYJNĄ tabelę z P-4.2 (mapping §7d), która
 * kluczowała się tylko dwiema szerokimi gałęziami („Komputery" → `laptopy`,
 * „Telefony i Akcesoria" → `smartfony`) i łapała w nie produkty spoza
 * deklarowanej domeny (247 i 148 produktów, w większości NIE laptopy/telefony).
 * Oferta bez żadnej reguły dostaje term-kosz `pozostale` (D-6.1.2), a komenda
 * loguje nierozwiązaną gałąź (id + nazwy), żeby kurator dopisał tu regułę —
 * tabela ROŚNIE dalej w toku kuracji; ustabilizowane slugi wracają do
 * `kontrakt-danych.md` §1.1.
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
		'85166'  => 'audio',     // „Bezprzewodowe" — słuchawki BT (oferta 18780385602, P-3.1).
		'4575'   => 'peryferia', // Myszy (P-3.1 index.csv); dziś pokryte też branżowo przez `4564`.
		'4569'   => 'gaming',    // Pady (kontroler gier) — nadpisuje gałąź `4564` (peryferia).
		'259427' => 'audio',     // Słuchawki przewodowe (komputerowe) — nadpisuje gałąź `259422` (peryferia).
		'259426' => 'audio',     // Słuchawki bezprzewodowe (komputerowe) — jw.
		'259434' => 'audio',     // Głośniki (komputerowe) — bezpośrednie dziecko gałęzi `2`.
		'82326'  => 'gaming',    // „Gry na konsole" (gałąź „Gry", bez własnej reguły gałęzi).
		'260043' => 'gaming',    // Gogle VR — nadpisuje fallback gałęzi `2`.
		'257064' => 'gaming',    // Fotele gamingowe — nadpisuje gałąź `497` (peryferia).
		'491'    => 'komputery', // Laptopy (realne) — nadpisuje fallback gałęzi `2`.
		'486'    => 'komputery', // Komputery stacjonarne (realne) — jw.
		'147906' => 'zasilanie', // Zasilacze do laptopów — nadpisuje fallback gałęzi `2`.
	);

	/**
	 * Reguły gałęzi: id przodka → slug `product_cat` (priorytet wg bliskości
	 * liścia). Klucz `array-key` — jak wyżej. Kuracja P-6.8b (mapping §7e):
	 * gałęzie węższe (np. `4226`) trafiają zanim dopasowanie dojdzie do
	 * szerszego fallbacku tej samej domeny (np. `2`), bo ścieżka idzie od
	 * liścia do korzenia — bliższy węzeł zawsze wygrywa.
	 *
	 * @var array<array-key,string>
	 */
	private const BRANCH_RULES = array(
		// Telefony.
		'4'      => 'telefony-akcesoria', // „Telefony i Akcesoria" — 100% akcesoriów, 0 telefonów w katalogu.
		// Komputery — węższe gałęzie NAJPIERW (bliżej liścia niż `2`).
		'4226'   => 'komputery-i-podzespoly', // „Podzespoły komputerowe".
		'4475'   => 'komputery-i-podzespoly', // „Dyski i pamięci przenośne".
		'260017' => 'monitory',               // „Monitory komputerowe".
		'4564'   => 'peryferia',              // „Urządzenia wskazujące" (myszki/klawiatury/pady).
		'259422' => 'peryferia',              // „Mikrofony i słuchawki" (domyślnie mikrofon; słuchawki → wyjątek audio).
		'89253'  => 'peryferia',              // „Tablety" (akcesoria).
		'497'    => 'peryferia',              // „Akcesoria (Laptop, PC)" — fallback (torby, stacje dokujące, podstawki).
		'4689'   => 'kable-i-adaptery',        // „Kable, taśmy, przedłużacze" (PC).
		'4691'   => 'kable-i-adaptery',        // „Przejściówki, śledzie" (PC).
		'4413'   => 'urzadzenia-sieciowe',     // „Urządzenia sieciowe" (routery, karty sieciowe, kamery IP, huby USB).
		'4578'   => 'drukowanie',              // „Drukarki i skanery" (tonery/tusze/bębny).
		'4551'   => 'zasilanie',               // „Listwy zasilające i UPS".
		'2'      => 'komputery-i-podzespoly',  // „Komputery" — fallback (był `laptopy`); realne komputery → wyjątki liść.
		// Konsole / audio estradowe (bez zmian względem P-4.2).
		'122233' => 'gaming', // „Konsole i automaty".
		'122332' => 'audio',  // „Sprzęt estradowy, studyjny i DJ-ski".
		// RTV i AGD — węższe gałęzie NAJPIERW (bliżej liścia niż `10`).
		'67193'  => 'kable-i-adaptery', // „Elektronika" (domena kabli/przewodów RTV).
		'67414'  => 'agd-drobne',       // „AGD drobne".
		'67093'  => 'gps-i-lokalizacja', // „GPS i akcesoria".
		'10'     => 'agd-drobne', // „RTV i AGD" — fallback (TV-uchwyty, car audio, akcesoria kamer — marginalne).
		// Dom i Ogród.
		'5317'   => 'oswietlenie', // „Oświetlenie".
		'1532'   => 'ogrod',       // „Ogród".
		'5'      => 'ogrod',       // „Dom i Ogród" — fallback (budownictwo/ogrzewanie — marginalne).
		// Zdrowie.
		'121882' => 'higiena-i-zdrowie', // „Zdrowie".
	);

	/**
	 * Czytelne nazwy termów per slug (do utworzenia termu przy pierwszym użyciu).
	 *
	 * @var array<string,string>
	 */
	private const TERM_NAMES = array(
		'smartfony'              => 'Smartfony',
		'telefony-akcesoria'     => 'Akcesoria do telefonów',
		'komputery'              => 'Komputery',
		'komputery-i-podzespoly' => 'Podzespoły komputerowe',
		'monitory'               => 'Monitory',
		'peryferia'              => 'Peryferia',
		'kable-i-adaptery'       => 'Kable i adaptery',
		'urzadzenia-sieciowe'    => 'Urządzenia sieciowe',
		'drukowanie'             => 'Drukowanie',
		'zasilanie'              => 'Zasilanie',
		'audio'                  => 'Audio',
		'gaming'                 => 'Gaming',
		'agd-drobne'             => 'AGD drobne',
		'oswietlenie'            => 'Oświetlenie',
		'ogrod'                  => 'Ogród',
		'gps-i-lokalizacja'      => 'GPS i lokalizacja',
		'higiena-i-zdrowie'      => 'Higiena i zdrowie',
		'pozostale'              => 'Pozostałe',
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
		$match = self::resolve( $path );

		return null !== $match ? $match['slug'] : null;
	}

	/**
	 * Jak {@see self::resolve_slug()}, ale zwraca też TYP dopasowania (`leaf`/`branch`)
	 * i id węzła reguły — samego sluga nie starcza raportowi kuratora (P-6.8a), który
	 * musi pokazać, CZY to wyjątek per-liść, czy reguła gałęzi, i którego węzła.
	 *
	 * @param array<int,array{id:string,name:string}> $path Ścieżka liść→korzeń (mapping §7b).
	 * @return array{slug:string,type:'leaf'|'branch',rule_id:string}|null Null = żadna reguła nie łapie.
	 */
	public static function resolve( array $path ): ?array {
		if ( array() === $path ) {
			return null;
		}

		$leaf_id = $path[0]['id'];

		if ( isset( self::LEAF_RULES[ $leaf_id ] ) ) {
			return array(
				'slug'    => self::LEAF_RULES[ $leaf_id ],
				'type'    => 'leaf',
				'rule_id' => (string) $leaf_id,
			);
		}

		// Od liścia w górę — pierwsze trafienie to najbliższy przodek z regułą.
		foreach ( $path as $node ) {
			if ( isset( self::BRANCH_RULES[ $node['id'] ] ) ) {
				return array(
					'slug'    => self::BRANCH_RULES[ $node['id'] ],
					'type'    => 'branch',
					'rule_id' => (string) $node['id'],
				);
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
