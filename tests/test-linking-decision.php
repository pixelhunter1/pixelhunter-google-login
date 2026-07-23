<?php
require __DIR__ . '/assert.php';
require dirname( __DIR__ ) . '/includes/class-accounts.php';

$A = 'PixelHunter_Login_Accounts';

$r = $A::decide( false, null, null );
phgl_assert( 'reject' === $r['action'], 'email_verified=false -> reject' );

$r = $A::decide( true, 42, 42 );
phgl_assert( 'login' === $r['action'] && 42 === $r['user_id'], 'known sub -> login (sub wins over email)' );

$r = $A::decide( true, 7, null );
phgl_assert( 'login' === $r['action'] && 7 === $r['user_id'], 'known sub, no email match -> login' );

$r = $A::decide( true, null, null );
phgl_assert( 'create' === $r['action'], 'new email -> create' );

$r = $A::decide( true, null, 99 );
phgl_assert( 'confirm_link' === $r['action'] && 99 === $r['user_id'], 'existing email, no sub -> confirm_link' );

phgl_summary();
