<?php
/**
 * Plugin Name: PixelHunter Google Login
 * Description: Login e registo com Google (OAuth 2.0 / OpenID Connect), auto-contido, sem terceiros.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: pixelhunter-google-login
 * Update URI: false
 *
 * Update URI: false impede o WordPress.org de sequestrar o slug numa atualização —
 * a distribuição deste plugin é por código, não pelo repositório.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

define( 'PIXELHUNTER_GOOGLE_LOGIN_VERSION', '0.1.0' );
define( 'PIXELHUNTER_GOOGLE_LOGIN_FILE', __FILE__ );
define( 'PIXELHUNTER_GOOGLE_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELHUNTER_GOOGLE_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-settings.php';

if ( is_readable( PIXELHUNTER_GOOGLE_LOGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'vendor/autoload.php';
}

/**
 * Bootstrap. Later tasks add require_once + init lines below this marker.
 */
add_action(
	'plugins_loaded',
	function () {
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-accounts.php';
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-token.php';
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-link.php';
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-oauth.php';
		( new PixelHunter_Google_Login_OAuth() )->register();
		( new PixelHunter_Google_Login_Link() )->register();
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-button.php';
		( new PixelHunter_Google_Login_Button() )->register();
		// PHGL_BOOTSTRAP_INIT
	}
);
