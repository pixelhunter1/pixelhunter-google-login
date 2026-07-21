<?php
/**
 * Config accessor. The Client Secret ALWAYS prefers the wp-config constant.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Settings {

	const OPTION          = 'pixelhunter_google_login_settings';
	const SECRET_CONSTANT = 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET';
	const REST_NAMESPACE  = 'pixelhunter-google-login/v1';

	/** Merged settings with defaults. */
	public static function get(): array {
		$defaults = array(
			'enabled'       => false,
			'client_id'     => '',
			'client_secret' => '',
			'button_theme'  => 'light',
		);
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function is_enabled(): bool {
		return (bool) self::get()['enabled'];
	}

	public static function client_id(): string {
		return (string) self::get()['client_id'];
	}

	/** Constant wins; falls back to the stored value. */
	public static function client_secret(): string {
		if ( self::secret_from_constant() ) {
			return (string) constant( self::SECRET_CONSTANT );
		}
		return (string) self::get()['client_secret'];
	}

	public static function secret_from_constant(): bool {
		return defined( self::SECRET_CONSTANT ) && '' !== (string) constant( self::SECRET_CONSTANT );
	}

	public static function button_theme(): string {
		return 'dark' === self::get()['button_theme'] ? 'dark' : 'light';
	}

	/** The Redirect URI to register in Google Cloud Console. */
	public static function redirect_uri(): string {
		return rest_url( self::REST_NAMESPACE . '/callback' );
	}

	/** URL the button points at; carries the origin to return to. */
	public static function start_url( string $redirect_to ): string {
		return add_query_arg(
			'redirect_to',
			rawurlencode( $redirect_to ),
			rest_url( self::REST_NAMESPACE . '/start' )
		);
	}
}
