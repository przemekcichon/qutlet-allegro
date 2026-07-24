<?php
/**
 * Slice OrderSync — jednorazowy backfill atrybucji „Origin" (P-6.6b, D-6.6.2).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\OrderSync;

use WP_CLI;
use function WP_CLI\Utils\get_flag_value;

/**
 * `wp qutlet-allegro backfill-order-attribution` — jednorazowa migracja: ustawia
 * atrybucję Origin „Allegro" ({@see OrderWriter::backfill_attribution()}, kontrakt
 * §12.6, D-6.6.1) na zamówieniach zaimportowanych PRZED P-6.6b, którym import wtedy
 * jeszcze tej meta nie zapisywał. Od P-6.6b nowe importy/przebudowy treści dostają
 * atrybucję automatycznie przez {@see OrderWriter::apply()} — ta komenda domyka
 * już istniejącą historię (D-6.6.2).
 *
 * ## Czysto lokalna operacja — bez API Allegro
 * Inaczej niż {@see SyncOrdersCommand}: operuje wyłącznie na już zaimportowanych
 * `WC_Order` (meta pod ręką), zero żądań HTTP do Allegro. Stąd brak flag
 * `--environment`/tokenu/locka — środowisko Allegro nie ma tu znaczenia (jedna baza
 * WooCommerce niezależnie od tego, z którego konta Allegro dane zaimportowano).
 *
 * ## Zakres i idempotencja
 * Zapytanie samo filtruje kandydatów: klucz idempotencji importu
 * `_qutlet_allegro_checkout_form_id` (§12.1, `EXISTS`) ORAZ brak atrybucji
 * (`_wc_order_attribution_source_type`, `NOT EXISTS`) — więc powtórne uruchomienie
 * nie znajdzie już nic do zrobienia (bezpieczne w cronie/ręcznie, wielokrotnie).
 * Status `any` OPRÓCZ kosza (D-6.2.1/D-6.3.4 — wycofane ręcznie zamówienia zostają
 * nietknięte, jak w reszcie slice'a).
 *
 * Rejestracja: pod guardem `WP_CLI` w bootstrapie wtyczki (jak {@see SyncOrdersCommand}).
 */
final class BackfillOrderAttributionCommand {

	/**
	 * Rozmiar strony iteracji zamówień.
	 */
	private const PAGE_LIMIT = 100;

	/**
	 * Bezpiecznik pętli paginacji (jak pozostałe komendy repo).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Uzupełnia atrybucję Origin „Allegro" na zaimportowanych zamówieniach, które jej
	 * jeszcze nie mają.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Policz kandydatów i pokaż ich id — bez jednego zapisu (wzorzec `SandboxSeedCommand`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp qutlet-allegro backfill-order-attribution --dry-run
	 *     wp qutlet-allegro backfill-order-attribution
	 *
	 * @param array<int,string>         $args       Argumenty pozycyjne (nieużywane).
	 * @param array<string,string|bool> $assoc_args Flagi.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = (bool) get_flag_value( $assoc_args, 'dry-run', false );
		$writer  = new OrderWriter();
		$checked = 0;
		$updated = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$orders = wc_get_orders(
				array(
					'limit'      => self::PAGE_LIMIT,
					'paged'      => $page,
					'status'     => array_keys( wc_get_order_statuses() ), // bez kosza (D-6.2.1/D-6.3.4).
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- jednorazowa migracja (D-6.6.2), nie ścieżka na krytycznym torze.
						array(
							'key'     => OrderWriter::META_CHECKOUT_FORM_ID,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => OrderWriter::META_ATTRIBUTION_SOURCE_TYPE,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			if ( ! is_array( $orders ) || array() === $orders ) {
				break;
			}

			foreach ( $orders as $order ) {
				++$checked;

				if ( $dry_run ) {
					WP_CLI::log( sprintf( '  (dry-run) zamówienie %d dostałoby atrybucję „Referral: Allegro".', $order->get_id() ) );
					continue;
				}

				if ( $writer->backfill_attribution( $order ) ) {
					++$updated;
				}
			}

			if ( count( $orders ) < self::PAGE_LIMIT ) {
				break;
			}

			if ( self::MAX_PAGES === $page ) {
				WP_CLI::warning( sprintf( 'Przerwano backfill na bezpieczniku %d stron — uruchom komendę ponownie (idempotentna, dokończy resztę).', self::MAX_PAGES ) );
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( 'backfill-order-attribution (dry-run): %d zamówień dostałoby atrybucję.', $checked ) );

			return;
		}

		WP_CLI::success( sprintf( 'backfill-order-attribution: sprawdzone %d, zaktualizowane %d.', $checked, $updated ) );
	}
}
