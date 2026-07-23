<?php
// Minimal test harness for pure-logic files. Defines a dummy ABSPATH so the
// `defined('ABSPATH') || exit;` guard in included class files does not exit,
// and a `__()` shim so the providers registry loads without WordPress.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

$GLOBALS['phgl_pass'] = 0;
$GLOBALS['phgl_fail'] = 0;

function phgl_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		$GLOBALS['phgl_pass']++;
		echo "  PASS: {$msg}\n";
	} else {
		$GLOBALS['phgl_fail']++;
		echo "  FAIL: {$msg}\n";
	}
}

function phgl_summary(): void {
	$p = $GLOBALS['phgl_pass'];
	$f = $GLOBALS['phgl_fail'];
	echo "\n{$p} passed, {$f} failed\n";
	exit( $f > 0 ? 1 : 0 );
}
