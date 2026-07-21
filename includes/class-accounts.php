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

	/** User ID whose linked Google sub matches, or null. */
	public static function find_by_sub( string $sub ): ?int {
		if ( '' === $sub ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_SUB,
				'meta_value' => $sub,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : null;
	}

	/** User ID for an email, or null. */
	public static function find_by_email( string $email ): ?int {
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : null;
	}

	/** Create a new customer from Google identity and link the sub. */
	public static function create_from_google( string $email, string $sub, string $name ): int {
		$name     = sanitize_text_field( $name );
		$base     = sanitize_user( current( explode( '@', $email ) ), true );
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			$i++;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => '' !== $name ? $name : $username,
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		self::link_sub( (int) $user_id, $sub );
		return (int) $user_id;
	}

	/** Attach a Google sub to a user. */
	public static function link_sub( int $user_id, string $sub ): void {
		update_user_meta( $user_id, self::META_SUB, $sub );
		update_user_meta( $user_id, self::META_LINKED_AT, time() );
	}

	/**
	 * Resolve verified Google claims into an action. Executes login/create;
	 * returns intent for confirm_link/reject (handled by the caller).
	 *
	 * @param array $claims Result of Token::validate_claims (must be ok=true).
	 * @return array{action:string,user_id:?int}
	 */
	public function resolve( array $claims ): array {
		$decision = self::decide(
			true,
			self::find_by_sub( (string) $claims['sub'] ),
			self::find_by_email( (string) $claims['email'] )
		);

		if ( 'create' === $decision['action'] ) {
			$user_id = self::create_from_google( (string) $claims['email'], (string) $claims['sub'], (string) ( $claims['name'] ?? '' ) );
			$decision['user_id'] = $user_id ?: null;
			$decision['action']  = $user_id ? 'login' : 'reject';
		}

		return $decision;
	}
}
