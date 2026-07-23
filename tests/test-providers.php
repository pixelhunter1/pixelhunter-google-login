<?php
require __DIR__ . '/assert.php';
require dirname( __DIR__ ) . '/includes/class-providers.php';

$P = 'PixelHunter_Login_Providers';

phgl_assert( array( 'google', 'microsoft' ) === $P::slugs(), 'slugs: google primeiro, microsoft depois' );
phgl_assert( null === $P::get( 'facebook' ), 'provider desconhecido -> null' );

$required = array(
	'slug', 'label', 'button_label', 'icon', 'auth_url', 'token_url', 'jwks_url',
	'iss_allowed', 'require_email_verified', 'extra_auth_args', 'option',
	'secret_constant', 'meta_sub', 'meta_linked_at', 'start_path', 'callback_path',
);
foreach ( $P::slugs() as $slug ) {
	$provider = $P::get( $slug );
	$missing  = array_diff( $required, array_keys( (array) $provider ) );
	phgl_assert( array() === $missing, "{$slug}: definição completa (" . ( $missing ? 'falta ' . implode( ',', $missing ) : 'ok' ) . ')' );
}

// Nomes legados do Google têm de se manter: config real na BD, contas já
// ligadas por meta e o Redirect URI registado na Google Cloud Console.
$g = $P::get( 'google' );
phgl_assert( 'pixelhunter_google_login_settings' === $g['option'], 'google: opção legada preservada' );
phgl_assert( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET' === $g['secret_constant'], 'google: constante legada preservada' );
phgl_assert( '_pixelhunter_google_sub' === $g['meta_sub'], 'google: meta key legada preservada' );
phgl_assert( '/callback' === $g['callback_path'], 'google: rota /callback legada preservada (registada na consola)' );
phgl_assert( true === $g['require_email_verified'], 'google: exige email_verified' );

$m = $P::get( 'microsoft' );
phgl_assert( false !== strpos( $m['auth_url'], '/consumers/' ), 'microsoft: tenant consumers (contas pessoais)' );
phgl_assert( false !== strpos( $m['jwks_url'], '/consumers/' ), 'microsoft: JWKS do tenant consumers' );
phgl_assert( array( 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0' ) === $m['iss_allowed'], 'microsoft: issuer restrito ao tenant MSA' );
phgl_assert( false === $m['require_email_verified'], 'microsoft: não exige email_verified (MSA não a emite)' );
phgl_assert( '/microsoft/callback' === $m['callback_path'], 'microsoft: rota própria de callback' );
phgl_assert( $m['meta_sub'] !== $g['meta_sub'], 'meta keys distintas por provider' );

phgl_summary();
