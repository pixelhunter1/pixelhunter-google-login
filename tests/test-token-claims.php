<?php
require __DIR__ . '/assert.php';
require dirname( __DIR__ ) . '/includes/class-token.php';

$aud   = 'client-abc.apps.googleusercontent.com';
$nonce = 'nonce-123';
$now   = 1000;

$valid = array(
	'iss'            => 'https://accounts.google.com',
	'aud'            => $aud,
	'exp'            => 2000,
	'nonce'          => $nonce,
	'email_verified' => true,
	'email'          => 'ana@gmail.com',
	'sub'            => '10769150350006150',
	'name'           => 'Ana',
);

$r = PixelHunter_Google_Login_Token::validate_claims( $valid, $aud, $nonce, $now );
phgl_assert( true === $r['ok'], 'valid token passes' );
phgl_assert( 'ana@gmail.com' === $r['email'], 'email extracted' );
phgl_assert( '10769150350006150' === $r['sub'], 'sub extracted' );

$r = PixelHunter_Google_Login_Token::validate_claims( array_merge( $valid, array( 'aud' => 'other' ) ), $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'aud' === $r['error'], 'wrong aud rejected' );

$r = PixelHunter_Google_Login_Token::validate_claims( array_merge( $valid, array( 'exp' => 500 ) ), $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'expired' === $r['error'], 'expired rejected' );

$r = PixelHunter_Google_Login_Token::validate_claims( array_merge( $valid, array( 'nonce' => 'x' ) ), $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'nonce' === $r['error'], 'wrong nonce rejected' );

$r = PixelHunter_Google_Login_Token::validate_claims( array_merge( $valid, array( 'email_verified' => false ) ), $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'email_verified' === $r['error'], 'unverified email rejected' );

$r = PixelHunter_Google_Login_Token::validate_claims( array_merge( $valid, array( 'iss' => 'https://evil.example' ) ), $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'iss' === $r['error'], 'bad issuer rejected' );

$noemail = $valid; unset( $noemail['email'] );
$r = PixelHunter_Google_Login_Token::validate_claims( $noemail, $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'email' === $r['error'], 'missing email rejected' );

$nosub = $valid; unset( $nosub['sub'] );
$r = PixelHunter_Google_Login_Token::validate_claims( $nosub, $aud, $nonce, $now );
phgl_assert( false === $r['ok'] && 'sub' === $r['error'], 'missing sub rejected' );

phgl_summary();
