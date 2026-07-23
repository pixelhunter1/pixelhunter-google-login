<?php
/**
 * Renderiza os botões "Continuar com …" (um por provider ativo) no topo dos
 * formulários de login e registo (hooks WooCommerce) e enfileira a stylesheet.
 * Auto-contido: o tema não é tocado.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Button {

	public function register(): void {
		add_action( 'woocommerce_login_form_start', array( $this, 'render' ) );
		add_action( 'woocommerce_register_form_start', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** @return array[] Providers prontos a mostrar. */
	protected function ready_providers(): array {
		return array_values( array_filter( PixelHunter_Login_Providers::all(), array( 'PixelHunter_Login_Settings', 'is_ready' ) ) );
	}

	public function enqueue(): void {
		if ( ! $this->ready_providers() ) {
			return;
		}
		wp_enqueue_style(
			'pixelhunter-social-login',
			PIXELHUNTER_LOGIN_URL . 'assets/social-login.css',
			array(),
			PIXELHUNTER_LOGIN_VERSION
		);
	}

	public function render(): void {
		$providers = $this->ready_providers();
		if ( ! $providers || is_user_logged_in() ) {
			return;
		}
		$theme       = PixelHunter_Login_Settings::button_theme();
		$redirect_to = home_url( add_query_arg( array() ) );
		?>
		<div class="pc-social-login">
			<?php foreach ( $providers as $provider ) : ?>
				<?php
				$url = PixelHunter_Login_Settings::start_url( $provider, $redirect_to );
				$svg = @file_get_contents( PIXELHUNTER_LOGIN_DIR . 'assets/' . $provider['icon'] );
				?>
				<a class="pc-social-btn pc-social-btn--<?php echo esc_attr( $provider['slug'] ); ?> pc-social-btn--<?php echo esc_attr( $theme ); ?>" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $provider['button_label'] ); ?>">
					<span class="pc-social-btn__icon" aria-hidden="true"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG estático local. ?></span>
					<span class="pc-social-btn__label"><?php echo esc_html( $provider['button_label_short'] ); ?></span>
				</a>
			<?php endforeach; ?>
			<div class="pc-social-login__divider"><span><?php esc_html_e( 'ou', 'pixelhunter-google-login' ); ?></span></div>
		</div>
		<?php
	}
}
