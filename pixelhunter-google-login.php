<?php
/**
 * Plugin Name: PixelHunter Social Login
 * Plugin URI: https://github.com/pixelhunter1/pixelhunter-google-login
 * Description: Google and Microsoft login/registration for WooCommerce (OAuth 2.0 / OpenID Connect) — self-contained, no third-party services.
 * Version: 0.3.3
 * Author: Miguel Carneiro
 * Author URI: https://pixelhunter.pt
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: pixelhunter-google-login
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: false
 *
 * Update URI: false impede o WordPress.org de sequestrar o slug numa atualização —
 * a distribuição deste plugin é por código, não pelo repositório.
 *
 * A pasta/slug mantém o nome original (pixelhunter-google-login) porque mudar
 * desativava o plugin e partia a opção de plugins ativos; só o nome mudou.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

define( 'PIXELHUNTER_LOGIN_VERSION', '0.3.3' );
define( 'PIXELHUNTER_LOGIN_FILE', __FILE__ );
define( 'PIXELHUNTER_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELHUNTER_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-providers.php';
require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-settings.php';

if ( is_readable( PIXELHUNTER_LOGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PIXELHUNTER_LOGIN_DIR . 'vendor/autoload.php';
}

// Atualizações a partir dos GitHub Releases (repo público, sem token).
// Compara a tag do release com o header Version: e injeta o update no
// ecrã normal "Plugins → Atualizações". Update URI: false continua a
// bloquear o wordpress.org — o PUC injeta pelo transient, não colide.
if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
	$phgl_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/pixelhunter1/pixelhunter-google-login/',
		__FILE__,
		'pixelhunter-google-login'
	);
	$phgl_update_checker->setBranch( 'master' );

	// O parser de readme do PUC remove as tags <img>, por isso as screenshots
	// não sobrevivem no readme.txt (só as legendas). Injetamo-las aqui, depois
	// do parse, a apontar para os PNGs no repo (raw GitHub). O wp_kses do modal
	// "Ver detalhes" deixa passar <img src|alt>, logo renderizam.
	$phgl_update_checker->addResultFilter(
		function ( $info ) {
			if ( ! is_object( $info ) ) {
				return $info;
			}
			$base  = 'https://raw.githubusercontent.com/pixelhunter1/pixelhunter-google-login/master/assets/';
			$shots = array(
				'screenshot-1.png' => __( 'Google and Microsoft buttons on the WooCommerce login/register form.', 'pixelhunter-google-login' ),
				'screenshot-2.png' => __( 'Admin — Google tab: setup guide, ready-to-copy Redirect URI, and live status.', 'pixelhunter-google-login' ),
				'screenshot-3.png' => __( 'Admin — Microsoft tab (Azure setup guide and secret-expiry note).', 'pixelhunter-google-login' ),
			);
			$html = '';
			foreach ( $shots as $file => $caption ) {
				$html .= '<p><img src="' . esc_url( $base . $file ) . '" alt="' . esc_attr( $caption ) . '" />'
					. '<br />' . esc_html( $caption ) . '</p>';
			}
			$info->sections                 = is_array( $info->sections ) ? $info->sections : array();
			$info->sections['screenshots']  = $html;
			return $info;
		}
	);
}

// Fonte em inglês; traduções em languages/ (plugin fora do wordpress.org, o
// carregamento automático do WP não se aplica). Em init por causa do WP 6.7+.
add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'pixelhunter-google-login',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/**
 * Bootstrap. Later tasks add require_once + init lines below this marker.
 */
add_action(
	'plugins_loaded',
	function () {
		require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-accounts.php';
		require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-token.php';
		require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-link.php';
		require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-oauth.php';
		( new PixelHunter_Login_OAuth() )->register();
		( new PixelHunter_Login_Link() )->register();
		require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-button.php';
		( new PixelHunter_Login_Button() )->register();
		if ( is_admin() ) {
			require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-admin.php';
			( new PixelHunter_Login_Admin() )->register();
		}
		// PHGL_BOOTSTRAP_INIT
	}
);
