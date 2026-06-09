<?php
/**
 * Token encryption / decryption using AES-256-CBC.
 *
 * Key is derived from WordPress auth salts defined in wp-config.php so that
 * even a raw database dump cannot decode the stored tokens without the server's
 * wp-config.php.
 *
 * Format of encrypted values:  sasp_enc_v1:<base64(iv . ciphertext)>
 *
 * If openssl is unavailable the value is stored plain-text (fallback).
 * Legacy plain-text values stored before encryption was added are returned
 * as-is (backward compatibility) — save them again to trigger encryption.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SASP_Crypto {

	private const PREFIX = 'sasp_enc_v1:';
	private const CIPHER = 'AES-256-CBC';

	// ── Public API ────────────────────────────────────────────────────────────

	/** Encrypt $plaintext. Returns the encrypted string (or plain-text if openssl is absent). */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		// Already encrypted — do not double-encrypt.
		if ( self::is_encrypted( $plaintext ) ) {
			return $plaintext;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext; // openssl unavailable — store as-is
		}

		$key        = self::derive_key();
		$iv_len     = openssl_cipher_iv_length( self::CIPHER );
		$iv         = openssl_random_pseudo_bytes( $iv_len );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return $plaintext; // encryption failed — store as-is rather than lose the token
		}

		return self::PREFIX . base64_encode( $iv . $ciphertext );
	}

	/**
	 * Decrypt $value.
	 * Returns the original plain-text.
	 * If the value is not encrypted (legacy or plain-text) it is returned unchanged.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// Not our format — plain-text (legacy) value.
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return ''; // openssl unavailable — cannot decrypt
		}

		$data = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );

		if ( false === $data ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( strlen( $data ) <= $iv_len ) {
			return '';
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );
		$key        = self::derive_key();

		$result = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return ( false !== $result ) ? $result : '';
	}

	/** Returns true if $value was encrypted by this class. */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	// ── Key derivation ────────────────────────────────────────────────────────

	/**
	 * Derive a 32-byte AES key from WordPress auth salts.
	 * Salts live in wp-config.php — they are NOT stored in the database.
	 */
	private static function derive_key(): string {
		$material = '';

		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ] as $constant ) {
			if ( defined( $constant ) ) {
				$material .= constant( $constant );
			}
		}

		// Last-resort fallback (site URL + DB name) — weaker but avoids empty key.
		if ( '' === $material ) {
			$material = ( defined( 'DB_NAME' ) ? DB_NAME : '' )
						. ( defined( 'DB_HOST' ) ? DB_HOST : '' )
						. get_site_url();
		}

		// sha256 → 32 raw bytes → perfect AES-256 key.
		return substr( hash( 'sha256', $material, true ), 0, 32 );
	}
}
