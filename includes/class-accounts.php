<?php
/**
 * Account resolution. `decide()` is pure; WordPress lookups/creation are added
 * in Task 5.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Accounts {

	const META_SUB       = '_pixelhunter_google_sub';
	const META_LINKED_AT = '_pixelhunter_google_linked_at';

	/**
	 * Decide what to do given identity signals. Pure: no WordPress calls.
	 *
	 * @return array{action:string,user_id:?int}
	 */
	public static function decide( bool $email_verified, ?int $uid_by_sub, ?int $uid_by_email ): array {
		if ( ! $email_verified ) {
			return array( 'action' => 'reject', 'user_id' => null );
		}
		if ( null !== $uid_by_sub ) {
			return array( 'action' => 'login', 'user_id' => $uid_by_sub );
		}
		if ( null === $uid_by_email ) {
			return array( 'action' => 'create', 'user_id' => null );
		}
		return array( 'action' => 'confirm_link', 'user_id' => $uid_by_email );
	}
}
