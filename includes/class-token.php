<?php
/**
 * id_token verification. `validate_claims()` is pure (no WordPress); `decode()`
 * adds signature verification against Google's JWKS.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class PixelHunter_Google_Login_Token {

	const JWKS_URL   = 'https://www.googleapis.com/oauth2/v3/certs';
	const ISS_ALLOWED = array( 'https://accounts.google.com', 'accounts.google.com' );

	/**
	 * Validate the decoded payload. Pure: takes claims + expectations, returns a result.
	 *
	 * @return array{ok:bool,error:?string,sub:?string,email:?string,name:?string}
	 */
	public static function validate_claims( array $payload, string $expected_aud, string $expected_nonce, int $now ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		if ( ! isset( $payload['iss'] ) || ! in_array( $payload['iss'], self::ISS_ALLOWED, true ) ) {
			return $fail( 'iss' );
		}
		if ( ! isset( $payload['aud'] ) || ! hash_equals( $expected_aud, (string) $payload['aud'] ) ) {
			return $fail( 'aud' );
		}
		if ( ! isset( $payload['exp'] ) || (int) $payload['exp'] <= $now ) {
			return $fail( 'expired' );
		}
		if ( ! isset( $payload['nonce'] ) || ! hash_equals( $expected_nonce, (string) $payload['nonce'] ) ) {
			return $fail( 'nonce' );
		}
		if ( empty( $payload['email_verified'] ) || true !== $payload['email_verified'] ) {
			return $fail( 'email_verified' );
		}
		if ( empty( $payload['email'] ) || ! is_string( $payload['email'] ) ) {
			return $fail( 'email' );
		}
		if ( empty( $payload['sub'] ) ) {
			return $fail( 'sub' );
		}

		return array(
			'ok'    => true,
			'error' => null,
			'sub'   => (string) $payload['sub'],
			'email' => (string) $payload['email'],
			'name'  => isset( $payload['name'] ) ? (string) $payload['name'] : '',
		);
	}

	/**
	 * Verify signature against Google's JWKS, then validate claims.
	 * Throws no exceptions upward: any failure returns ['ok'=>false, ...].
	 */
	public static function decode( string $id_token, string $expected_aud, string $expected_nonce ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		$jwks = self::get_jwks();
		if ( empty( $jwks ) ) {
			return $fail( 'jwks' );
		}

		try {
			$keys    = JWK::parseKeySet( $jwks );
			JWT::$leeway = 60; // tolerate small clock skew between this host and Google
			$decoded = (array) JWT::decode( $id_token, $keys );
		} catch ( \Throwable $e ) {
			return $fail( 'signature' );
		}

		return self::validate_claims( $decoded, $expected_aud, $expected_nonce, time() );
	}

	/** Fetch + cache Google's public keys. */
	private static function get_jwks(): array {
		$cached = get_transient( 'pixelhunter_google_jwks' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$resp = wp_remote_get( self::JWKS_URL, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['keys'] ) ) {
			return array();
		}
		set_transient( 'pixelhunter_google_jwks', $body, HOUR_IN_SECONDS );
		return $body;
	}
}
