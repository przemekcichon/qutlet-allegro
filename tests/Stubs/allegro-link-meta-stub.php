<?php
/**
 * Test-only dubler `Qutlet\Core\AllegroLink\AllegroLinkMeta` (P-15.2) — harness
 * `qutlet-allegro` autoloaduje WYŁĄCZNIE `Qutlet\Allegro\` (`composer.json`),
 * więc klasa core (osobny plugin, osobny autoloader w runtime WP) nie istnieje
 * pod PHPUnit. `known_offer_ids()` ({@see \Qutlet\Allegro\OfferSync\ProductWriter})
 * odwołuje się do `META_OFFER_ID`, więc test tej metody potrzebuje minimalnego
 * dublera — WYŁĄCZNIE tej jednej stałej, literał VERBATIM z
 * `qutlet-core/src/AllegroLink/AllegroLinkMeta.php` (ground-truth P-15.1,
 * potwierdzone zgodne z `docs/kontrakt-danych.md`).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Core\AllegroLink;

if ( ! class_exists( __NAMESPACE__ . '\\AllegroLinkMeta' ) ) {
	final class AllegroLinkMeta {
		public const META_OFFER_ID = '_qutlet_allegro_offer_id';
	}
}
