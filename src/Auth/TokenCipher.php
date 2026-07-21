<?php
/**
 * Slice Auth — szyfrowanie tokenów przy zapisie w bazie (P-2.1, D-2.1.1).
 *
 * @package Qutlet\Allegro
 */

declare( strict_types=1 );

namespace Qutlet\Allegro\Auth;

/**
 * Szyfrowanie symetryczne tokenów przed zapisem do opcji WP i deszyfrowanie przy
 * odczycie (D-2.1.1 — decyzja użytkownika: szyfrowane, klucz z `wp-config.php`).
 *
 * Motywacja: tokeny to bearer-credentiale do konta Allegro (para write mutuje
 * stan magazynowy, read czyta PII zamówień). Zgodnie z etosem projektu (D-2.G3,
 * D-7.G2 — „zero sekretów w DB") NIE trzymamy ich w bazie jawnie. Klucz żyje w
 * `wp-config.php` (system plików, poza bazą), więc sam zrzut bazy nie wystarcza
 * do odczytania tokenów.
 *
 * Prymityw: `sodium_crypto_secretbox` (XSalsa20-Poly1305) — uwierzytelnione
 * szyfrowanie z libsodium (wbudowane w PHP od 7.2). 32-bajtowy klucz wyprowadzamy
 * z dowolnie długiej stałej `QUTLET_ALLEGRO_TOKEN_KEY` przez `generichash`
 * (BLAKE2b) — dzięki temu użytkownik może wpisać sekret o dowolnej długości.
 *
 * Format zapisu: `base64( nonce || ciphertext )`. Nonce (24 B) losowany per
 * szyfrowanie, więc ten sam plaintext daje różne kryptogramy.
 */
final class TokenCipher {

	/**
	 * Nazwa stałej `wp-config.php` z materiałem klucza (D-2.1.1). Dowolnie długi,
	 * wysokoentropijny string — właściwy 32-bajtowy klucz wyprowadzamy z niego.
	 */
	public const KEY_CONSTANT = 'QUTLET_ALLEGRO_TOKEN_KEY';

	/**
	 * Czy szyfrowanie jest w ogóle dostępne: klucz zdefiniowany i niepusty oraz
	 * obecne funkcje libsodium. Bez tego magazyn nie zapisze/odczyta tokenów
	 * (świadomie NIE degradujemy do zapisu jawnego).
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return '' !== self::key_material()
			&& \function_exists( 'sodium_crypto_secretbox' )
			&& \function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Szyfruje plaintext. Zwraca `base64(nonce||ciphertext)` albo null, gdy
	 * szyfrowanie niedostępne.
	 *
	 * @param string $plaintext Dane do zaszyfrowania (JSON tokenów).
	 * @return string|null
	 */
	public static function encrypt( string $plaintext ): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		$key    = self::derive_key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		sodium_memzero( $key );

		return base64_encode( $nonce . $cipher );
	}

	/**
	 * Deszyfruje wartość z magazynu. Zwraca plaintext albo null, gdy dane są
	 * uszkodzone / niedeszyfrowalne (np. zmieniony klucz) lub szyfrowanie
	 * niedostępne.
	 *
	 * @param string $stored `base64(nonce||ciphertext)` z magazynu.
	 * @return string|null
	 */
	public static function decrypt( string $stored ): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		$raw = base64_decode( $stored, true );

		if ( false === $raw || \strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = self::derive_key();

		$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		sodium_memzero( $key );

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Wyprowadza 32-bajtowy klucz z materiału ze stałej (BLAKE2b).
	 *
	 * @return string Surowy 32-bajtowy klucz.
	 */
	private static function derive_key(): string {
		return sodium_crypto_generichash( self::key_material(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Odczyt materiału klucza ze stałej `wp-config.php` (string, '' gdy brak).
	 *
	 * @return string
	 */
	private static function key_material(): string {
		if ( ! \defined( self::KEY_CONSTANT ) ) {
			return '';
		}

		$value = \constant( self::KEY_CONSTANT );

		return \is_string( $value ) ? $value : '';
	}
}
