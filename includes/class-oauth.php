<?php
/**
 * Endpoints OAuth por provider: {start_path} constrói o redirect de
 * autorização; {callback_path} trata o regresso. As rotas vêm do registry —
 * este ficheiro não conhece nenhum provider em concreto.
 *
 * @package PixelHunter_Social_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_OAuth {

	const COOKIE       = 'phgl_oauth';
	const STATE_PREFIX = 'pixelhunter_login_state_';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$ns = PixelHunter_Login_Settings::REST_NAMESPACE;
		foreach ( PixelHunter_Login_Providers::all() as $provider ) {
			register_rest_route(
				$ns,
				$provider['start_path'],
				array(
					'methods'             => 'GET',
					'callback'            => function ( WP_REST_Request $request ) use ( $provider ) {
						return $this->route_start( $provider, $request );
					},
					'permission_callback' => '__return_true',
				)
			);

			register_rest_route(
				$ns,
				$provider['callback_path'],
				array(
					'methods'             => 'GET',
					'callback'            => function ( WP_REST_Request $request ) use ( $provider ) {
						return $this->route_callback( $provider, $request );
					},
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	public function route_start( array $provider, WP_REST_Request $request ) {
		if ( ! PixelHunter_Login_Settings::is_ready( $provider ) ) {
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
				'provider'    => $provider['slug'],
				'state'       => $state,
				'nonce'       => $nonce,
				'redirect_to' => $redirect_to,
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_cookie( self::COOKIE, $id );

		$url = add_query_arg(
			array_merge(
				array(
					'client_id'     => rawurlencode( PixelHunter_Login_Settings::client_id( $provider ) ),
					'redirect_uri'  => rawurlencode( PixelHunter_Login_Settings::redirect_uri( $provider ) ),
					'response_type' => 'code',
					'scope'         => rawurlencode( 'openid email profile' ),
					'state'         => $state,
					'nonce'         => $nonce,
				),
				$provider['extra_auth_args']
			),
			$provider['auth_url']
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
		// O 302 do /start transporta um state de uso único: cacheá-lo faz o
		// browser repetir um state já expirado, sem cookie novo, e o login
		// falha para sempre. O WP só manda nocache no REST a utilizadores
		// autenticados — aqui ninguém está. (Não chega sozinho: um servidor
		// com "Header set Cache-Control" sobrepõe-se. Ver o cache-buster em
		// class-button.php.)
		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- o destino é o endpoint do provider (externo por definição), construído a partir do registry; os destinos internos passam antes por wp_validate_redirect().
		wp_redirect( $url );
		exit;
	}

	public function route_callback( array $provider, WP_REST_Request $request ) {
		$account_url = wc_get_page_permalink( 'myaccount' );

		// Consome o state store + cookie imediatamente (uso único).
		$cookie_id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$stored    = $cookie_id ? get_transient( self::STATE_PREFIX . $cookie_id ) : false;
		if ( $cookie_id ) {
			delete_transient( self::STATE_PREFIX . $cookie_id );
		}
		$this->clear_cookie( self::COOKIE );

		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		// O authorization code é opaco (só viaja para o provider, nunca é
		// guardado nem impresso): sanitizá-lo podia partir códigos válidos.
		$code = trim( (string) $request->get_param( 'code' ) );

		if ( ! is_array( $stored ) || '' === $state || ! hash_equals( (string) $stored['state'], $state ) ) {
			return $this->fail( $account_url, $provider, 'state' );
		}
		// O fluxo tem de terminar no provider em que começou.
		if ( ( $stored['provider'] ?? '' ) !== $provider['slug'] ) {
			return $this->fail( $account_url, $provider, 'state' );
		}
		if ( '' === $code ) {
			return $this->fail( $account_url, $provider, 'code' );
		}

		$id_token = $this->exchange_code( $provider, $code );
		if ( '' === $id_token ) {
			return $this->fail( $account_url, $provider, 'exchange' );
		}

		$claims = PixelHunter_Login_Token::decode(
			$id_token,
			$provider,
			PixelHunter_Login_Settings::client_id( $provider ),
			(string) $stored['nonce']
		);
		if ( empty( $claims['ok'] ) ) {
			return $this->fail( $account_url, $provider, 'token_' . ( $claims['error'] ?? 'unknown' ) );
		}

		$result = ( new PixelHunter_Login_Accounts() )->resolve( $provider, $claims );

		if ( 'login' === $result['action'] && $result['user_id'] ) {
			$user = get_user_by( 'id', (int) $result['user_id'] );
			wp_set_current_user( (int) $result['user_id'] );
			wp_set_auth_cookie( (int) $result['user_id'], true );
			if ( $user ) {
				do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook do core, disparado de propósito depois do login programático.
			}
			return $this->redirect( wp_validate_redirect( (string) $stored['redirect_to'], $account_url ) );
		}

		if ( 'confirm_link' === $result['action'] && $result['user_id'] ) {
			PixelHunter_Login_Link::put( (int) $result['user_id'], (string) $claims['sub'], $provider['slug'] );
			return $this->redirect( add_query_arg( 'phgl_link', $provider['slug'], $account_url ) );
		}

		return $this->fail( $account_url, $provider, 'reject' );
	}

	/** Troca o authorization code por tokens; devolve o id_token ou ''. */
	protected function exchange_code( array $provider, string $code ): string {
		$resp = wp_remote_post(
			$provider['token_url'],
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => PixelHunter_Login_Settings::client_id( $provider ),
					'client_secret' => PixelHunter_Login_Settings::client_secret( $provider ),
					'redirect_uri'  => PixelHunter_Login_Settings::redirect_uri( $provider ),
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

	protected function fail( string $account_url, array $provider, string $code ) {
		return $this->redirect(
			add_query_arg(
				array(
					'phgl_error'    => rawurlencode( $code ),
					'phgl_provider' => $provider['slug'],
				),
				$account_url
			)
		);
	}
}
