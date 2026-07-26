<?php
/**
 * Provider registry: todos os factos específicos de cada provider (endpoints,
 * política de claims, nomes de opção/meta/rotas, branding) vivem aqui. O resto
 * do plugin é agnóstico e recebe um destes arrays.
 *
 * @package PixelHunter_Social_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Providers {

	/** @return string[] Slugs pela ordem de apresentação dos botões. */
	public static function slugs(): array {
		return array( 'google', 'microsoft' );
	}

	/** @return array[] Todas as definições, pela ordem de slugs(). */
	public static function all(): array {
		return array_map( array( __CLASS__, 'get' ), self::slugs() );
	}

	public static function get( string $slug ): ?array {
		$providers = array(
			'google'    => array(
				'slug'                   => 'google',
				'label'                  => 'Google',
				'button_label'           => __( 'Continue with Google', 'pixelhunter-social-login' ),
				'button_label_short'     => 'Google',
				'icon'                   => 'g-logo.svg',
				'auth_url'               => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token_url'              => 'https://oauth2.googleapis.com/token',
				'jwks_url'               => 'https://www.googleapis.com/oauth2/v3/certs',
				'iss_allowed'            => array( 'https://accounts.google.com', 'accounts.google.com' ),
				'require_email_verified' => true,
				'extra_auth_args'        => array(
					'prompt'      => 'select_account',
					'access_type' => 'online',
				),
				// Nomes legados do tempo em que o plugin era Google-only: a opção
				// tem config real na BD, a meta tem contas já ligadas e o
				// /callback está registado na Google Cloud Console. Não renomear.
				'option'                 => 'pixelhunter_google_login_settings',
				'secret_constant'        => 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET',
				'meta_sub'               => '_pixelhunter_google_sub',
				'meta_linked_at'         => '_pixelhunter_google_linked_at',
				'start_path'             => '/start',
				'callback_path'          => '/callback',
			),
			'microsoft' => array(
				'slug'                   => 'microsoft',
				'label'                  => 'Microsoft',
				'button_label'           => __( 'Continue with Microsoft', 'pixelhunter-social-login' ),
				'button_label_short'     => 'Microsoft',
				'icon'                   => 'ms-logo.svg',
				// Tenant "consumers" = contas Microsoft pessoais (Hotmail, Outlook.com, Live).
				'auth_url'               => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize',
				'token_url'              => 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token',
				'jwks_url'               => 'https://login.microsoftonline.com/consumers/discovery/v2.0/keys',
				// GUID fixo do tenant de contas pessoais em todos os id_tokens MSA.
				'iss_allowed'            => array( 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0' ),
				// MSA não emite a claim email_verified; o email é o da própria conta Microsoft.
				'require_email_verified' => false,
				'extra_auth_args'        => array( 'prompt' => 'select_account' ),
				'option'                 => 'pixelhunter_microsoft_login_settings',
				'secret_constant'        => 'PIXELHUNTER_MICROSOFT_LOGIN_CLIENT_SECRET',
				'meta_sub'               => '_pixelhunter_microsoft_sub',
				'meta_linked_at'         => '_pixelhunter_microsoft_linked_at',
				'start_path'             => '/microsoft/start',
				'callback_path'          => '/microsoft/callback',
			),
		);
		return $providers[ $slug ] ?? null;
	}
}
