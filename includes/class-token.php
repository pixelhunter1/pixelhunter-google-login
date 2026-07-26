<?php
/**
 * Verificação do id_token. `validate_claims()` é pura (sem WordPress);
 * `decode()` acrescenta a verificação de assinatura contra o JWKS do provider.
 *
 * @package PixelHunter_Social_Login
 */

defined( 'ABSPATH' ) || exit;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class PixelHunter_Login_Token {

	/**
	 * Valida o payload descodificado. Pura: claims + expectativas → resultado.
	 *
	 * @param array $payload Claims do id_token.
	 * @param array $expect  {iss_allowed: string[], aud: string, nonce: string, require_email_verified: bool}.
	 * @param int   $now     Timestamp atual.
	 * @return array{ok:bool,error:?string,sub:?string,email:?string,name:?string}
	 */
	public static function validate_claims( array $payload, array $expect, int $now ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		if ( ! isset( $payload['iss'] ) || ! in_array( $payload['iss'], (array) $expect['iss_allowed'], true ) ) {
			return $fail( 'iss' );
		}
		if ( ! isset( $payload['aud'] ) || ! hash_equals( (string) $expect['aud'], (string) $payload['aud'] ) ) {
			return $fail( 'aud' );
		}
		if ( ! isset( $payload['exp'] ) || (int) $payload['exp'] <= $now ) {
			return $fail( 'expired' );
		}
		if ( ! isset( $payload['nonce'] ) || ! hash_equals( (string) $expect['nonce'], (string) $payload['nonce'] ) ) {
			return $fail( 'nonce' );
		}

		// Quando o provider emite email_verified (Google), exigimos true; quando
		// não emite de todo (Microsoft/MSA), aceitamos a ausência — mas um
		// email_verified=false explícito é sempre rejeitado.
		$verified = $payload['email_verified'] ?? null;
		if ( ! empty( $expect['require_email_verified'] ) && true !== $verified ) {
			return $fail( 'email_verified' );
		}
		if ( null !== $verified && true !== $verified ) {
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
	 * Verifica a assinatura contra o JWKS do provider e valida as claims.
	 * Não deixa subir exceções: qualquer falha devolve ['ok'=>false, ...].
	 */
	public static function decode( string $id_token, array $provider, string $expected_aud, string $expected_nonce ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		$jwks = self::get_jwks( $provider );
		if ( empty( $jwks ) ) {
			return $fail( 'jwks' );
		}

		try {
			// O JWKS da Microsoft não traz "alg" por chave; RS256 é o default de ambos.
			$keys        = JWK::parseKeySet( $jwks, 'RS256' );
			JWT::$leeway = 60; // tolerar pequeno desvio de relógio entre este host e o provider
			$decoded     = (array) JWT::decode( $id_token, $keys );
		} catch ( \Throwable $e ) {
			return $fail( 'signature' );
		}

		return self::validate_claims(
			$decoded,
			array(
				'iss_allowed'            => $provider['iss_allowed'],
				'aud'                    => $expected_aud,
				'nonce'                  => $expected_nonce,
				'require_email_verified' => $provider['require_email_verified'],
			),
			time()
		);
	}

	/** Busca + cache das chaves públicas do provider. */
	private static function get_jwks( array $provider ): array {
		$transient = 'pixelhunter_login_jwks_' . $provider['slug'];
		$cached    = get_transient( $transient );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$resp = wp_remote_get( $provider['jwks_url'], array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['keys'] ) ) {
			return array();
		}
		set_transient( $transient, $body, HOUR_IN_SECONDS );
		return $body;
	}
}
