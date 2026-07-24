<?php
/**
 * Slice OfferSync — czysta decyzja kierunku rekoncyliacji stanu (P-6.2b).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OfferSync;

/**
 * Tabela decyzyjna rekoncyliacji stanu (D-6.G3 + D-6.2.4) — wydzielona jako czysta
 * funkcja, bo to na niej trzyma się niezmiennik „nigdy nadsprzedaż": obniżenie
 * stanu w Woo stosujemy wprost (kierunek bezpieczny), podniesienie dopiero po
 * świeżym potwierdzeniu `GET .../parts` (lista ofert mogła być pobrana przed
 * chwilowym pushem z Woo — bez potwierdzenia wyścig przywróciłby stan właśnie
 * sprzedanego egzemplarza). Produkt z zaległym pushem najpierw domyka push —
 * pull na nim byłby nadpisaniem sprzedaży w sklepie stanem sprzed niej.
 *
 * Klasa celowo BEZ wywołań WP — testowalna PHPUnitem (jak {@see OfferMapper}).
 */
final class StockReconciler {

	/**
	 * Zastosuj stan z Allegro wprost (obniżenie / inicjalizacja — bezpieczne).
	 */
	public const APPLY = 'apply';

	/**
	 * Podniesienie stanu w Woo — wymaga świeżego potwierdzenia `/parts` (D-6.2.4).
	 */
	public const CONFIRM_INCREASE = 'confirm-increase';

	/**
	 * Produkt ma zaległy push Woo→Allegro — pull wstrzymany, aż push się domknie.
	 */
	public const PUSH_FIRST = 'push-first';

	/**
	 * Stany zgodne — nic do zrobienia.
	 */
	public const NOOP = 'noop';

	/**
	 * Decyzja pull dla pojedynczego produktu przy rekoncyliacji.
	 *
	 * @param int      $allegro_stock Stan `stock.available` po stronie Allegro.
	 * @param int|null $woo_stock     Stan Woo (`null` = produkt bez zarządzania stanem
	 *                                — inicjalizujemy z Allegro).
	 * @param bool     $push_pending  Czy produkt ma marker zaległego pusha (D-6.2.3).
	 * @return string Jedna ze stałych klasy.
	 */
	public static function pull_action( int $allegro_stock, ?int $woo_stock, bool $push_pending ): string {
		if ( $push_pending ) {
			return self::PUSH_FIRST;
		}

		if ( null === $woo_stock ) {
			return self::APPLY;
		}

		if ( $allegro_stock === $woo_stock ) {
			return self::NOOP;
		}

		return $allegro_stock < $woo_stock ? self::APPLY : self::CONFIRM_INCREASE;
	}
}
