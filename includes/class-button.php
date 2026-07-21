<?php
/**
 * Renders the "Continuar com Google" button at the top of the login and register
 * forms (WooCommerce hooks), and enqueues its stylesheet. Self-contained: the
 * theme is not touched.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Button {

	public function register(): void {
		add_action( 'woocommerce_login_form_start', array( $this, 'render' ) );
		add_action( 'woocommerce_register_form_start', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() ) {
			return;
		}
		wp_enqueue_style(
			'pixelhunter-google-login',
			PIXELHUNTER_GOOGLE_LOGIN_URL . 'assets/google-login.css',
			array(),
			PIXELHUNTER_GOOGLE_LOGIN_VERSION
		);
	}

	public function render(): void {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() || is_user_logged_in() ) {
			return;
		}
		$theme       = PixelHunter_Google_Login_Settings::button_theme();
		$redirect_to = home_url( add_query_arg( array() ) );
		$url         = PixelHunter_Google_Login_Settings::start_url( $redirect_to );
		$svg         = @file_get_contents( PIXELHUNTER_GOOGLE_LOGIN_DIR . 'assets/g-logo.svg' );
		?>
		<div class="pc-google-login">
			<a class="pc-google-btn pc-google-btn--<?php echo esc_attr( $theme ); ?>" href="<?php echo esc_url( $url ); ?>">
				<span class="pc-google-btn__icon" aria-hidden="true"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local static SVG asset. ?></span>
				<span class="pc-google-btn__label"><?php esc_html_e( 'Continuar com Google', 'pixelhunter-google-login' ); ?></span>
			</a>
			<div class="pc-google-login__divider"><span><?php esc_html_e( 'ou', 'pixelhunter-google-login' ); ?></span></div>
		</div>
		<?php
	}
}
