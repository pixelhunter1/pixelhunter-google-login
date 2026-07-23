<?php
/**
 * Token de link pendente: faz a ponte entre o callback (que detetou um email
 * já existente) e o momento em que o utilizador prova a posse fazendo login.
 * Guarda o provider para ligar a meta certa.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Link {

	const COOKIE = 'phgl_link';
	const PREFIX = 'pixelhunter_login_link_';

	/** Guarda um link pendente (utilizador a quem ligar o sub) por ~15 min. */
	public static function put( int $user_id, string $sub, string $provider_slug ): void {
		$id = wp_generate_password( 32, false );
		set_transient(
			self::PREFIX . $id,
			array(
				'user_id'  => $user_id,
				'sub'      => $sub,
				'provider' => $provider_slug,
			),
			15 * MINUTE_IN_SECONDS
		);
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

	/** @return array{user_id:int,sub:string,provider:string}|null */
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

	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_notice' ) );
	}

	/** Após login com sucesso, se um link pendente aponta para ESTE utilizador, liga o sub. */
	public function on_login( $user_login, $user ): void {
		$pending = self::pending();
		if ( ! $pending || (int) $pending['user_id'] !== (int) $user->ID ) {
			return;
		}
		$provider = PixelHunter_Login_Providers::get( (string) ( $pending['provider'] ?? '' ) );
		if ( $provider ) {
			PixelHunter_Login_Accounts::link_sub( $provider, (int) $user->ID, (string) $pending['sub'] );
		}
		self::clear();
	}

	/** Mostra o aviso de confirm-link / mensagens de erro como notices WooCommerce. */
	public function maybe_notice(): void {
		if ( ! function_exists( 'wc_add_notice' ) || is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- parâmetros de redirect OAuth, só leitura para mensagens.
		if ( isset( $_GET['phgl_link'] ) && ! is_user_logged_in() ) {
			$label = self::provider_label( sanitize_text_field( wp_unslash( $_GET['phgl_link'] ) ) );
			wc_add_notice(
				sprintf(
					/* translators: %s: provider name (Google/Microsoft). */
					__( 'An account with this email already exists. Log in with your password to link %s (or use “Lost your password?”).', 'pixelhunter-google-login' ),
					$label
				),
				'notice'
			);
		} elseif ( isset( $_GET['phgl_error'] ) ) {
			$label = self::provider_label( isset( $_GET['phgl_provider'] ) ? sanitize_text_field( wp_unslash( $_GET['phgl_provider'] ) ) : '' );
			wc_add_notice( self::error_message( sanitize_text_field( wp_unslash( $_GET['phgl_error'] ) ), $label ), 'error' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Nome apresentável do provider; fallback genérico para slugs desconhecidos. */
	protected static function provider_label( string $slug ): string {
		$provider = PixelHunter_Login_Providers::get( $slug );
		return $provider ? $provider['label'] : 'Google';
	}

	protected static function error_message( string $code, string $label ): string {
		if ( 'token_email_verified' === $code ) {
			/* translators: %s: provider name (Google/Microsoft). */
			return sprintf( __( 'Your %s account email is not verified, so it cannot be used to sign in here.', 'pixelhunter-google-login' ), $label );
		}
		if ( 'reject' === $code ) {
			/* translators: %s: provider name (Google/Microsoft). */
			return sprintf( __( 'Could not sign in with %s.', 'pixelhunter-google-login' ), $label );
		}
		/* translators: %s: provider name (Google/Microsoft). */
		return sprintf( __( 'Signing in with %s failed. Please try again.', 'pixelhunter-google-login' ), $label );
	}
}
