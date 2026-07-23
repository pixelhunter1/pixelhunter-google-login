<?php
/**
 * Resolução de contas. `decide()` é pura; as pesquisas/criação em WordPress
 * usam as meta keys do provider (uma identidade ligada por provider).
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Accounts {

	/**
	 * Decide o que fazer dados os sinais de identidade. Pura: sem WordPress.
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

	/** User ID cujo sub ligado deste provider coincide, ou null. */
	public static function find_by_sub( array $provider, string $sub ): ?int {
		if ( '' === $sub ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => $provider['meta_sub'],
				'meta_value' => $sub,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return $users ? (int) $users[0] : null;
	}

	/** User ID para um email, ou null. */
	public static function find_by_email( string $email ): ?int {
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : null;
	}

	/** Cria um novo customer a partir da identidade do provider e liga o sub. */
	public static function create( array $provider, string $email, string $sub, string $name ): int {
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
		self::link_sub( $provider, (int) $user_id, $sub );
		return (int) $user_id;
	}

	/** Liga o sub do provider a um utilizador. */
	public static function link_sub( array $provider, int $user_id, string $sub ): void {
		update_user_meta( $user_id, $provider['meta_sub'], $sub );
		update_user_meta( $user_id, $provider['meta_linked_at'], time() );
	}

	/**
	 * Resolve claims verificadas numa ação. Executa login/create; devolve a
	 * intenção para confirm_link/reject (tratados pelo caller).
	 *
	 * @param array $provider Definição do provider.
	 * @param array $claims   Resultado de Token::validate_claims (tem de ser ok=true).
	 * @return array{action:string,user_id:?int}
	 */
	public function resolve( array $provider, array $claims ): array {
		$decision = self::decide(
			true,
			self::find_by_sub( $provider, (string) $claims['sub'] ),
			self::find_by_email( (string) $claims['email'] )
		);

		if ( 'create' === $decision['action'] ) {
			$user_id             = self::create( $provider, (string) $claims['email'], (string) $claims['sub'], (string) ( $claims['name'] ?? '' ) );
			$decision['user_id'] = $user_id ?: null;
			$decision['action']  = $user_id ? 'login' : 'reject';
		}

		return $decision;
	}
}
