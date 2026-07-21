<?php
/**
 * Pending-link token: bridges the callback (which detected an existing email)
 * to the moment the user proves ownership by logging in. Task 8 adds the
 * wp_login linker + the account-page notice.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Link {

	const COOKIE = 'phgl_link';
	const PREFIX = 'pixelhunter_google_link_';

	/** Store a pending link (user to attach the sub to) for ~15 min. */
	public static function put( int $user_id, string $sub ): void {
		$id = wp_generate_password( 32, false );
		set_transient( self::PREFIX . $id, array( 'user_id' => $user_id, 'sub' => $sub ), 15 * MINUTE_IN_SECONDS );
		setcookie(
			self::COOKIE,
			$id,
			array(
				'expires'  => time() + 900,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** @return array{user_id:int,sub:string}|null */
	public static function pending(): ?array {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $id ) {
			return null;
		}
		$data = get_transient( self::PREFIX . $id );
		return is_array( $data ) ? $data : null;
	}

	public static function clear(): void {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' !== $id ) {
			delete_transient( self::PREFIX . $id );
		}
		setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/' ) );
	}
}
