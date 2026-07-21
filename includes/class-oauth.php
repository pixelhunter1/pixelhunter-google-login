<?php
/**
 * OAuth endpoints. /start builds the Google authorization redirect; /callback
 * (Task 7) handles the return.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_OAuth {

	const COOKIE     = 'phgl_oauth';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const STATE_PREFIX = 'pixelhunter_google_state_';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$ns = PixelHunter_Google_Login_Settings::REST_NAMESPACE;
		register_rest_route(
			$ns,
			'/start',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_start' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function route_start( WP_REST_Request $request ) {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() ) {
			return $this->redirect( wc_get_page_permalink( 'myaccount' ) );
		}

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		$redirect_to = $redirect_to ? wp_validate_redirect( rawurldecode( $redirect_to ), wc_get_page_permalink( 'myaccount' ) ) : wc_get_page_permalink( 'myaccount' );

		$state = wp_generate_password( 32, false );
		$nonce = wp_generate_password( 32, false );
		$id    = wp_generate_password( 32, false );

		set_transient(
			self::STATE_PREFIX . $id,
			array(
				'state'       => $state,
				'nonce'       => $nonce,
				'redirect_to' => $redirect_to,
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_cookie( self::COOKIE, $id );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( PixelHunter_Google_Login_Settings::client_id() ),
				'redirect_uri'  => rawurlencode( PixelHunter_Google_Login_Settings::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'nonce'         => $nonce,
				'prompt'        => 'select_account',
				'access_type'   => 'online',
			),
			self::AUTH_URL
		);
		return $this->redirect( $url );
	}

	protected function set_cookie( string $name, string $value ): void {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + 600,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	protected function clear_cookie( string $name ): void {
		setcookie( $name, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/' ) );
	}

	protected function redirect( string $url ) {
		wp_redirect( $url ); // Google URL / same-host account URL; not user-controlled beyond validated redirect_to.
		exit;
	}
}
