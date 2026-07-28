<?php
/**
 * Plugin Name: PixelHunter Social Login
 * Plugin URI: https://github.com/pixelhunter1/pixelhunter-google-login
 * Description: Google and Microsoft login/registration for WooCommerce (OAuth 2.0 / OpenID Connect) — self-contained, no third-party services.
 * Version: 0.4.1
 * Author: Miguel Carneiro
 * Author URI: https://pixelhunter.pt
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: pixelhunter-social-login
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Distribuição pelo wordpress.org: pasta, ficheiro principal e Text Domain têm
 * de ser iguais ao slug, e não pode existir header Update URI nem update checker
 * próprio — as atualizações vêm do repositório. Os prefixos internos (phgl_,
 * PIXELHUNTER_LOGIN_*) e as chaves em base de dados (pixelhunter_*) mantêm-se:
 * são invisíveis para o diretório e mudá-los perdia a configuração existente.
 *
 * @package PixelHunter_Social_Login
 */

defined( 'ABSPATH' ) || exit;

define( 'PIXELHUNTER_LOGIN_VERSION', '0.4.1' );
define( 'PIXELHUNTER_LOGIN_FILE', __FILE__ );
define( 'PIXELHUNTER_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELHUNTER_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-providers.php';
require_once PIXELHUNTER_LOGIN_DIR . 'includes/class-settings.php';

if ( is_readable( PIXELHUNTER_LOGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PIXELHUNTER_LOGIN_DIR . 'vendor/autoload.php';
}

// Sem update checker próprio: no wordpress.org as atualizações vêm do
// repositório do diretório, e um updater embutido é motivo de rejeição.

// Sem load_plugin_textdomain(): o wordpress.org serve as traduções pelo slug
// e o WP carrega-as sozinho desde a 4.6. As screenshots passam a vir do
// assets/ do SVN, não injetadas por código.

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
