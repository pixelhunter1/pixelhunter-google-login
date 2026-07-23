<?php
require __DIR__ . '/assert.php';
require dirname( __DIR__ ) . '/includes/class-token.php';

$now = 1000;

// ---- Google (exige email_verified) ----

$g_expect = array(
	'iss_allowed'            => array( 'https://accounts.google.com', 'accounts.google.com' ),
	'aud'                    => 'client-abc.apps.googleusercontent.com',
	'nonce'                  => 'nonce-123',
	'require_email_verified' => true,
);

$g_valid = array(
	'iss'            => 'https://accounts.google.com',
	'aud'            => $g_expect['aud'],
	'exp'            => 2000,
	'nonce'          => $g_expect['nonce'],
	'email_verified' => true,
	'email'          => 'ana@gmail.com',
	'sub'            => '10769150350006150',
	'name'           => 'Ana',
);

$r = PixelHunter_Login_Token::validate_claims( $g_valid, $g_expect, $now );
phgl_assert( true === $r['ok'], 'google: valid token passes' );
phgl_assert( 'ana@gmail.com' === $r['email'], 'google: email extracted' );
phgl_assert( '10769150350006150' === $r['sub'], 'google: sub extracted' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $g_valid, array( 'aud' => 'other' ) ), $g_expect, $now );
phgl_assert( false === $r['ok'] && 'aud' === $r['error'], 'google: wrong aud rejected' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $g_valid, array( 'exp' => 500 ) ), $g_expect, $now );
phgl_assert( false === $r['ok'] && 'expired' === $r['error'], 'google: expired rejected' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $g_valid, array( 'nonce' => 'x' ) ), $g_expect, $now );
phgl_assert( false === $r['ok'] && 'nonce' === $r['error'], 'google: wrong nonce rejected' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $g_valid, array( 'email_verified' => false ) ), $g_expect, $now );
phgl_assert( false === $r['ok'] && 'email_verified' === $r['error'], 'google: unverified email rejected' );

$g_noverify = $g_valid;
unset( $g_noverify['email_verified'] );
$r = PixelHunter_Login_Token::validate_claims( $g_noverify, $g_expect, $now );
phgl_assert( false === $r['ok'] && 'email_verified' === $r['error'], 'google: missing email_verified rejected (claim exigida)' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $g_valid, array( 'iss' => 'https://evil.example' ) ), $g_expect, $now );
phgl_assert( false === $r['ok'] && 'iss' === $r['error'], 'google: bad issuer rejected' );

$noemail = $g_valid;
unset( $noemail['email'] );
$r = PixelHunter_Login_Token::validate_claims( $noemail, $g_expect, $now );
phgl_assert( false === $r['ok'] && 'email' === $r['error'], 'google: missing email rejected' );

$nosub = $g_valid;
unset( $nosub['sub'] );
$r = PixelHunter_Login_Token::validate_claims( $nosub, $g_expect, $now );
phgl_assert( false === $r['ok'] && 'sub' === $r['error'], 'google: missing sub rejected' );

// ---- Microsoft / MSA (não emite email_verified) ----

$m_expect = array(
	'iss_allowed'            => array( 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0' ),
	'aud'                    => '11112222-3333-4444-5555-666677778888',
	'nonce'                  => 'nonce-456',
	'require_email_verified' => false,
);

$m_valid = array(
	'iss'   => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0',
	'aud'   => $m_expect['aud'],
	'exp'   => 2000,
	'nonce' => $m_expect['nonce'],
	'email' => 'rui@hotmail.com',
	'sub'   => 'AAAAAAAAAAAAAAAAAAAAAKzq1v0',
	'name'  => 'Rui',
);

$r = PixelHunter_Login_Token::validate_claims( $m_valid, $m_expect, $now );
phgl_assert( true === $r['ok'], 'msa: valid token without email_verified passes' );
phgl_assert( 'rui@hotmail.com' === $r['email'], 'msa: email extracted' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $m_valid, array( 'email_verified' => false ) ), $m_expect, $now );
phgl_assert( false === $r['ok'] && 'email_verified' === $r['error'], 'msa: explicit email_verified=false still rejected' );

$m_noemail = $m_valid;
unset( $m_noemail['email'] );
$r = PixelHunter_Login_Token::validate_claims( $m_noemail, $m_expect, $now );
phgl_assert( false === $r['ok'] && 'email' === $r['error'], 'msa: missing email rejected' );

$r = PixelHunter_Login_Token::validate_claims( array_merge( $m_valid, array( 'iss' => 'https://login.microsoftonline.com/other-tenant/v2.0' ) ), $m_expect, $now );
phgl_assert( false === $r['ok'] && 'iss' === $r['error'], 'msa: issuer de outro tenant rejected' );

phgl_summary();
