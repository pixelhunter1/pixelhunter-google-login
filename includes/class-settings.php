<?php
/**
 * Config accessor por provider. O Client Secret prefere SEMPRE a constante do
 * wp-config quando definida.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Settings {

	// Legado: o Redirect URI do Google registado na consola usa este namespace.
	const REST_NAMESPACE = 'pixelhunter-google-login/v1';

	const APPEARANCE_OPTION = 'pixelhunter_login_appearance';

	/** Settings do provider, com defaults. */
	public static function get( array $provider ): array {
		$defaults = array(
			'enabled'       => false,
			'client_id'     => '',
			'client_secret' => '',
		);
		$saved    = get_option( $provider['option'], array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function is_enabled( array $provider ): bool {
		return (bool) self::get( $provider )['enabled'];
	}

	public static function client_id( array $provider ): string {
		return (string) self::get( $provider )['client_id'];
	}

	/** Ativo E com Client ID — o mínimo para o botão/rotas funcionarem. */
	public static function is_ready( array $provider ): bool {
		return self::is_enabled( $provider ) && '' !== self::client_id( $provider );
	}

	/** A constante ganha; cai para o valor guardado. */
	public static function client_secret( array $provider ): string {
		if ( self::secret_from_constant( $provider ) ) {
			return (string) constant( $provider['secret_constant'] );
		}
		return (string) self::get( $provider )['client_secret'];
	}

	public static function secret_from_constant( array $provider ): bool {
		return defined( $provider['secret_constant'] ) && '' !== (string) constant( $provider['secret_constant'] );
	}

	/** Tema partilhado pelos botões. */
	public static function button_theme(): string {
		$saved = get_option( self::APPEARANCE_OPTION, array() );
		$theme = is_array( $saved ) ? (string) ( $saved['button_theme'] ?? '' ) : '';
		if ( '' === $theme ) {
			// Fallback legado: o tema vivia dentro da opção do Google.
			$google = PixelHunter_Login_Providers::get( 'google' );
			$theme  = (string) ( self::get( $google )['button_theme'] ?? '' );
		}
		return 'dark' === $theme ? 'dark' : 'light';
	}

	/** O Redirect URI a registar na consola do provider. */
	public static function redirect_uri( array $provider ): string {
		return rest_url( self::REST_NAMESPACE . $provider['callback_path'] );
	}

	/** URL do botão; transporta a origem para onde voltar. */
	public static function start_url( array $provider, string $redirect_to ): string {
		return add_query_arg(
			'redirect_to',
			rawurlencode( $redirect_to ),
			rest_url( self::REST_NAMESPACE . $provider['start_path'] )
		);
	}
}
