<?php
/**
 * Settings page under WooCommerce: credentials, setup guide with deep links,
 * auto-generated Redirect URI, and live status.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Admin {

	const SLUG = 'pixelhunter-google-login';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Login com Google', 'pixelhunter-google-login' ),
			__( 'Login com Google', 'pixelhunter-google-login' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function settings(): void {
		register_setting(
			self::SLUG,
			PixelHunter_Google_Login_Settings::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array(
			'enabled'      => ! empty( $input['enabled'] ),
			'client_id'    => sanitize_text_field( $input['client_id'] ?? '' ),
			'button_theme' => ( 'dark' === ( $input['button_theme'] ?? 'light' ) ) ? 'dark' : 'light',
		);
		// Never persist the secret to the DB when the wp-config constant supplies it.
		$out['client_secret'] = PixelHunter_Google_Login_Settings::secret_from_constant()
			? ''
			: sanitize_text_field( $input['client_secret'] ?? '' );
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s            = PixelHunter_Google_Login_Settings::get();
		$redirect_uri = PixelHunter_Google_Login_Settings::redirect_uri();
		$from_const   = PixelHunter_Google_Login_Settings::secret_from_constant();
		$has_id       = '' !== PixelHunter_Google_Login_Settings::client_id();
		$has_secret   = '' !== PixelHunter_Google_Login_Settings::client_secret();
		$row          = static function ( bool $ok, string $label ): void {
			printf( '<li>%s %s</li>', $ok ? '✅' : '❌', esc_html( $label ) );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login com Google', 'pixelhunter-google-login' ); ?></h1>

			<h2><?php esc_html_e( 'Configuração', 'pixelhunter-google-login' ); ?></h2>
			<ol>
				<li><a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener"><?php esc_html_e( 'Criar / escolher projeto no Google Cloud', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener"><?php esc_html_e( 'Configurar o ecrã de consentimento OAuth (nome + logo “PixelHunter”)', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener"><?php esc_html_e( 'Criar credenciais → OAuth client ID → Web application', 'pixelhunter-google-login' ); ?></a></li>
			</ol>
			<p><strong><?php esc_html_e( 'Redirect URI (cola no Google):', 'pixelhunter-google-login' ); ?></strong></p>
			<input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $redirect_uri ); ?>" style="width:100%;max-width:640px;font-family:monospace;" />

			<h2><?php esc_html_e( 'Estado', 'pixelhunter-google-login' ); ?></h2>
			<ul>
				<?php
				$row( (bool) $s['enabled'], __( 'Ativo', 'pixelhunter-google-login' ) );
				$row( $has_id, __( 'Client ID definido', 'pixelhunter-google-login' ) );
				$row( $has_secret, $from_const ? __( 'Client Secret definido (via wp-config)', 'pixelhunter-google-login' ) : __( 'Client Secret definido', 'pixelhunter-google-login' ) );
				?>
			</ul>

			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>
				<?php $opt = PixelHunter_Google_Login_Settings::OPTION; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ativar', 'pixelhunter-google-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( (bool) $s['enabled'] ); ?> /> <?php esc_html_e( 'Mostrar o botão “Continuar com Google”', 'pixelhunter-google-login' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_client_id"><?php esc_html_e( 'Client ID', 'pixelhunter-google-login' ); ?></label></th>
						<td><input type="text" id="phgl_client_id" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_client_secret"><?php esc_html_e( 'Client Secret', 'pixelhunter-google-login' ); ?></label></th>
						<td>
							<?php if ( $from_const ) : ?>
								<input type="text" id="phgl_client_secret" class="regular-text" value="🔒 <?php esc_attr_e( 'Definido em wp-config.php', 'pixelhunter-google-login' ); ?>" disabled />
								<p class="description"><?php esc_html_e( 'A constante PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET tem prioridade; este campo fica bloqueado.', 'pixelhunter-google-login' ); ?></p>
							<?php else : ?>
								<input type="password" id="phgl_client_secret" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[client_secret]" value="<?php echo esc_attr( $s['client_secret'] ); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Recomendado: define antes em wp-config.php para não guardar o segredo na base de dados.', 'pixelhunter-google-login' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_theme"><?php esc_html_e( 'Tema do botão', 'pixelhunter-google-login' ); ?></label></th>
						<td>
							<select id="phgl_theme" name="<?php echo esc_attr( $opt ); ?>[button_theme]">
								<option value="light" <?php selected( 'light', $s['button_theme'] ); ?>><?php esc_html_e( 'Claro', 'pixelhunter-google-login' ); ?></option>
								<option value="dark" <?php selected( 'dark', $s['button_theme'] ); ?>><?php esc_html_e( 'Escuro', 'pixelhunter-google-login' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
