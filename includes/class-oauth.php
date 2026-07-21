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

		register_rest_route(
			$ns,
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_callback' ),
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

	public function route_callback( WP_REST_Request $request ) {
		$account_url = wc_get_page_permalink( 'myaccount' );

		// Consume the state store + cookie immediately (single use).
		$cookie_id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$stored    = $cookie_id ? get_transient( self::STATE_PREFIX . $cookie_id ) : false;
		if ( $cookie_id ) {
			delete_transient( self::STATE_PREFIX . $cookie_id );
		}
		$this->clear_cookie( self::COOKIE );

		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );

		if ( ! is_array( $stored ) || '' === $state || ! hash_equals( (string) $stored['state'], $state ) ) {
			return $this->fail( $account_url, 'state' );
		}
		if ( '' === $code ) {
			return $this->fail( $account_url, 'code' );
		}

		$id_token = $this->exchange_code( $code );
		if ( '' === $id_token ) {
			return $this->fail( $account_url, 'exchange' );
		}

		$claims = PixelHunter_Google_Login_Token::decode(
			$id_token,
			PixelHunter_Google_Login_Settings::client_id(),
			(string) $stored['nonce']
		);
		if ( empty( $claims['ok'] ) ) {
			return $this->fail( $account_url, 'token_' . ( $claims['error'] ?? 'unknown' ) );
		}

		$result = ( new PixelHunter_Google_Login_Accounts() )->resolve( $claims );

		if ( 'login' === $result['action'] && $result['user_id'] ) {
			wp_set_current_user( (int) $result['user_id'] );
			wp_set_auth_cookie( (int) $result['user_id'], true );
			return $this->redirect( wp_validate_redirect( (string) $stored['redirect_to'], $account_url ) );
		}

		if ( 'confirm_link' === $result['action'] && $result['user_id'] ) {
			PixelHunter_Google_Login_Link::put( (int) $result['user_id'], (string) $claims['sub'] );
			return $this->redirect( add_query_arg( 'phgl_link', '1', $account_url ) );
		}

		return $this->fail( $account_url, 'reject' );
	}

	/** Exchange the authorization code for tokens; returns the id_token or ''. */
	protected function exchange_code( string $code ): string {
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => PixelHunter_Google_Login_Settings::client_id(),
					'client_secret' => PixelHunter_Google_Login_Settings::client_secret(),
					'redirect_uri'  => PixelHunter_Google_Login_Settings::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return '';
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ( is_array( $data ) && ! empty( $data['id_token'] ) ) ? (string) $data['id_token'] : '';
	}

	protected function fail( string $account_url, string $code ) {
		return $this->redirect( add_query_arg( 'phgl_error', rawurlencode( $code ), $account_url ) );
	}
}
