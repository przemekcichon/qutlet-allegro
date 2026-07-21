<?php
/**
 * Slice Auth — widok podstrony „Połączenia Allegro" (P-2.2, UI stanu połączeń).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Czysta warstwa prezentacji podstrony stanu połączeń OAuth (pod menu WooCommerce,
 * capability `manage_woocommerce`). NIE zawiera logiki flow — dane slotów oraz URL-e
 * akcji przygotowuje {@see OAuthController} i przekazuje tu gotowe do wyświetlenia.
 *
 * Odpowiedzialność widoku: bezpieczne wyprowadzenie (escaping) i układ. Każdy slot
 * (środowisko × rola) = jeden wiersz: czy połączony, przyznane scope'y, kiedy wygasa
 * access i (orientacyjnie) refresh, oraz akcja „Połącz" / „Rozłącz".
 */
final class ConnectionsPage {

	/**
	 * Renderuje całą podstronę.
	 *
	 * @param array<int,array<string,mixed>> $rows   Wiersze slotów (patrz {@see OAuthController::view_rows()}).
	 * @param array{type:string,message:string}|null $notice Komunikat po akcji (sukces/błąd) albo null.
	 * @return void
	 */
	public static function render( array $rows, ?array $notice ): void {
		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Połączenia Allegro (OAuth)', 'qutlet-allegro' ) );

		printf(
			'<p>%s</p>',
			esc_html__(
				'Każdy slot (środowisko × rola) autoryzujesz osobno. Odczyt nigdy nie dostaje prawa zapisu, a operacje na sandboksie nie sięgają poświadczeń produkcji.',
				'qutlet-allegro'
			)
		);

		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				'success' === $notice['type'] ? 'success' : 'error',
				esc_html( $notice['message'] )
			);
		}

		echo '<table class="widefat striped" style="max-width:960px;margin-top:1em;">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Slot', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Stan', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Przyznane zakresy', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Wygaśnięcia', 'qutlet-allegro' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Akcja', 'qutlet-allegro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			self::render_row( $row );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renderuje pojedynczy wiersz slotu.
	 *
	 * @param array<string,mixed> $row Dane wiersza.
	 * @return void
	 */
	private static function render_row( array $row ): void {
		$connected       = ! empty( $row['connected'] );
		$has_credentials = ! empty( $row['has_credentials'] );

		echo '<tr>';

		// Kolumna: slot.
		printf(
			'<td><strong>%1$s</strong><br><code>%2$s</code></td>',
			esc_html( (string) $row['label'] ),
			esc_html( (string) $row['slot'] )
		);

		// Kolumna: stan.
		echo '<td>';
		if ( $connected ) {
			printf(
				'<span style="color:#008a20;font-weight:600;">%s</span>',
				esc_html__( 'Połączony', 'qutlet-allegro' )
			);
		} elseif ( ! $has_credentials ) {
			printf(
				'<span style="color:#b32d2e;">%s</span>',
				esc_html__( 'Brak sekretów w wp-config.php', 'qutlet-allegro' )
			);
		} else {
			printf(
				'<span style="color:#646970;">%s</span>',
				esc_html__( 'Niepołączony', 'qutlet-allegro' )
			);
		}
		echo '</td>';

		// Kolumna: zakresy.
		echo '<td>';
		if ( $connected && '' !== (string) $row['scope'] ) {
			echo '<code style="white-space:normal;">' . esc_html( str_replace( ' ', "\u{00A0}· ", (string) $row['scope'] ) ) . '</code>';
		} else {
			echo '&mdash;';
		}
		echo '</td>';

		// Kolumna: wygaśnięcia.
		echo '<td>';
		if ( $connected ) {
			printf(
				'%1$s<br><span style="color:#646970;">%2$s</span>',
				esc_html( self::format_expiry( __( 'access', 'qutlet-allegro' ), (int) $row['expires_at'] ) ),
				esc_html( self::format_expiry( __( 'refresh (orient.)', 'qutlet-allegro' ), (int) $row['refresh_expires_at'] ) )
			);
		} else {
			echo '&mdash;';
		}
		echo '</td>';

		// Kolumna: akcja.
		echo '<td>';
		if ( $connected ) {
			self::render_disconnect_form( $row );
		} elseif ( $has_credentials ) {
			printf(
				'<a href="%1$s" class="button button-primary">%2$s</a>',
				esc_url( (string) $row['connect_url'] ),
				esc_html__( 'Połącz', 'qutlet-allegro' )
			);
		} else {
			echo '&mdash;';
		}
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Formularz POST „Rozłącz" (usunięcie tokenów slotu) — akcja zmieniająca stan,
	 * więc POST + nonce, przez `admin-post.php`.
	 *
	 * @param array<string,mixed> $row Dane wiersza.
	 * @return void
	 */
	private static function render_disconnect_form( array $row ): void {
		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( (string) $row['disconnect_action'] ) );
		printf( '<input type="hidden" name="environment" value="%s">', esc_attr( (string) $row['environment'] ) );
		printf( '<input type="hidden" name="role" value="%s">', esc_attr( (string) $row['role'] ) );
		wp_nonce_field( (string) $row['disconnect_nonce'] );
		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html__( 'Rozłącz', 'qutlet-allegro' )
		);
		echo '</form>';
	}

	/**
	 * Formatuje wygaśnięcie: etykieta + data lokalna + orientacyjny dystans czasowy.
	 *
	 * @param string $label Etykieta (np. „access").
	 * @param int    $ts    Absolutny znacznik uniksowy wygaśnięcia (0 = brak).
	 * @return string
	 */
	private static function format_expiry( string $label, int $ts ): string {
		if ( $ts <= 0 ) {
			return $label . ': —';
		}

		$date = wp_date( 'Y-m-d H:i', $ts );
		$now  = time();

		if ( $ts <= $now ) {
			$relative = __( 'wygasł', 'qutlet-allegro' );
		} else {
			/* translators: %s: human-readable time distance, e.g. "3 hours". */
			$relative = sprintf( __( 'za %s', 'qutlet-allegro' ), human_time_diff( $now, $ts ) );
		}

		return sprintf( '%1$s: %2$s (%3$s)', $label, (string) $date, $relative );
	}
}
