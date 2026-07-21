# PixelHunter Google Login — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a self-built "Continuar com Google" login/registration to the WooCommerce store (login drawer + My Account page) using OAuth 2.0 / OpenID Connect, entirely inside one WordPress plugin.

**Architecture:** A self-contained plugin renders a Google button via WooCommerce form hooks. Clicking it hits a server-side REST `/start` endpoint that redirects to Google (Authorization Code flow); Google returns to a REST `/callback` endpoint that verifies the `id_token`, then logs in / creates / securely links the account. Security-critical logic (token claim validation, account-linking decision) is pure PHP, unit-tested; WordPress glue is verified against the live Studio site.

**Tech Stack:** PHP 8.0+, WordPress 6.0+, WooCommerce, `firebase/php-jwt` (vendored), Studio CLI (SQLite).

## Global Constraints

- `Requires PHP: 8.0` (firebase/php-jwt needs PHP ≥ 8.0), `Requires at least: 6.0`.
- Plugin header MUST include `Update URI: false`.
- **All `wp` commands run through `studio wp`** (Studio site).
- **Do NOT auto-commit feature/layout changes** beyond the commit steps written in this plan; the plan's commits are explicit and expected. Never `git push` unless asked.
- Client Secret is read from constant `PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET` (wp-config); the constant wins over any stored value and the admin field locks when it exists. Never log or echo the secret.
- Function/hook prefix `pixelhunter_google_` / short `phgl_`; class prefix `PixelHunter_Google_Login_`; constants `PIXELHUNTER_GOOGLE_LOGIN_*`; text domain `pixelhunter-google-login`; CSS classes `.pc-google-…`.
- Sanitize all input, escape all output; use WP nonces on every form; cookies `HttpOnly` + `SameSite=Lax`.
- No inline CSS in output; button styles live in `assets/google-login.css` and consume theme vars with fallbacks (`var(--pc-radius, 8px)`).
- REST namespace: `pixelhunter-google-login/v1`. Option key: `pixelhunter_google_login_settings`. User meta: `_pixelhunter_google_sub`, `_pixelhunter_google_linked_at`.
- Working dir for all commands: `/Users/miguelcarneiro/Studio/pixelhunterclithes`. Plugin dir: `wp-content/plugins/pixelhunter-google-login`.

---

## File Structure

```
wp-content/plugins/pixelhunter-google-login/
├── pixelhunter-google-login.php   Header, constants, bootstrap (require + init each module)
├── includes/
│   ├── class-settings.php   Config accessor (constant-wins secret, redirect URI, start URL)
│   ├── class-token.php      id_token: validate_claims() [pure] + decode() [JWKS signature]
│   ├── class-accounts.php   decide() [pure] + WP lookups/create/link + resolve()
│   ├── class-oauth.php      REST /start + /callback; state/nonce store; code exchange
│   ├── class-link.php       Confirm-link screen (password prove) + admin-post handler
│   ├── class-button.php     Injects button on WC form hooks; enqueues CSS
│   └── class-admin.php      Settings page + setup guide + live status
├── assets/
│   ├── google-login.css     Button styles (light/dark via modifier)
│   └── g-logo.svg           Official 4-colour G (also inlined by the button)
├── tests/
│   ├── assert.php           Tiny assertion harness (defines dummy ABSPATH)
│   ├── test-token-claims.php
│   └── test-linking-decision.php
├── vendor/…                 firebase/php-jwt (committed)
├── composer.json / composer.lock
├── README.md
└── .gitignore
```

---

## Task 1: Plugin scaffold, constants, settings accessor

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/pixelhunter-google-login.php`
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-settings.php`
- Create: `wp-content/plugins/pixelhunter-google-login/.gitignore`
- Create: `wp-content/plugins/pixelhunter-google-login/README.md`

**Interfaces:**
- Produces: constants `PIXELHUNTER_GOOGLE_LOGIN_VERSION`, `PIXELHUNTER_GOOGLE_LOGIN_DIR`, `PIXELHUNTER_GOOGLE_LOGIN_URL`, `PIXELHUNTER_GOOGLE_LOGIN_FILE`.
- Produces: `PixelHunter_Google_Login_Settings` with static methods `get(): array`, `is_enabled(): bool`, `client_id(): string`, `client_secret(): string`, `secret_from_constant(): bool`, `button_theme(): string`, `redirect_uri(): string`, `start_url(string $redirect_to): string`.

- [ ] **Step 1: Create `.gitignore`**

```gitignore
# OS / editor
.DS_Store
Thumbs.db
*.log

# Keep vendor/ committed (code-distributed plugin) — do NOT ignore it.
```

- [ ] **Step 2: Create the settings accessor** `includes/class-settings.php`

```php
<?php
/**
 * Config accessor. The Client Secret ALWAYS prefers the wp-config constant.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Settings {

	const OPTION          = 'pixelhunter_google_login_settings';
	const SECRET_CONSTANT = 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET';
	const REST_NAMESPACE  = 'pixelhunter-google-login/v1';

	/** Merged settings with defaults. */
	public static function get(): array {
		$defaults = array(
			'enabled'       => false,
			'client_id'     => '',
			'client_secret' => '',
			'button_theme'  => 'light',
		);
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function is_enabled(): bool {
		return (bool) self::get()['enabled'];
	}

	public static function client_id(): string {
		return (string) self::get()['client_id'];
	}

	/** Constant wins; falls back to the stored value. */
	public static function client_secret(): string {
		if ( self::secret_from_constant() ) {
			return (string) constant( self::SECRET_CONSTANT );
		}
		return (string) self::get()['client_secret'];
	}

	public static function secret_from_constant(): bool {
		return defined( self::SECRET_CONSTANT ) && '' !== (string) constant( self::SECRET_CONSTANT );
	}

	public static function button_theme(): string {
		return 'dark' === self::get()['button_theme'] ? 'dark' : 'light';
	}

	/** The Redirect URI to register in Google Cloud Console. */
	public static function redirect_uri(): string {
		return rest_url( self::REST_NAMESPACE . '/callback' );
	}

	/** URL the button points at; carries the origin to return to. */
	public static function start_url( string $redirect_to ): string {
		return add_query_arg(
			'redirect_to',
			rawurlencode( $redirect_to ),
			rest_url( self::REST_NAMESPACE . '/start' )
		);
	}
}
```

- [ ] **Step 3: Create the main plugin file** `pixelhunter-google-login.php`

```php
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

/**
 * Bootstrap. Later tasks add require_once + init lines below this marker.
 */
add_action(
	'plugins_loaded',
	function () {
		// PHGL_BOOTSTRAP_INIT (modules wire themselves here in later tasks).
	}
);
```

- [ ] **Step 4: Create `README.md`**

```markdown
# PixelHunter Google Login

Login/registo com Google (OAuth 2.0 / OpenID Connect), construído internamente.
Ver `docs/superpowers/specs/2026-07-21-google-login-design.md` para o design.

## Segredo
Define no `wp-config.php`:
`define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-...' );`

## Testes de lógica pura
`php tests/test-token-claims.php && php tests/test-linking-decision.php`
```

- [ ] **Step 5: Initialise the plugin's own git repo**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git init -q && git add -A
```

- [ ] **Step 6: Verify the plugin activates cleanly**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp plugin activate pixelhunter-google-login && studio wp plugin list --status=active --format=csv | grep pixelhunter-google-login && studio wp eval 'echo defined("PIXELHUNTER_GOOGLE_LOGIN_VERSION") && class_exists("PixelHunter_Google_Login_Settings") ? "OK" : "FAIL";'
```
Expected: the plugin appears in the list and the eval prints `OK`.

- [ ] **Step 7: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: plugin scaffold, constants, settings accessor"
```

---

## Task 2: Vendor firebase/php-jwt

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/composer.json`, `composer.lock`, `vendor/…`
- Modify: `pixelhunter-google-login.php` (add autoload require)

**Interfaces:**
- Produces: classes `Firebase\JWT\JWT`, `Firebase\JWT\JWK`, `Firebase\JWT\Key` available at runtime.

- [ ] **Step 1: Require the library (vendored)**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && composer require firebase/php-jwt:^7.0
```
Expected: creates `composer.json`, `composer.lock`, `vendor/firebase/php-jwt/` (v7.1.0), `vendor/autoload.php`. Note: v7 (not v6) is required because Composer's advisory policy blocks every 6.x release (advisory PKSA-y2cr-5h3j-g3ys); v7 is API-identical for our `JWK::parseKeySet()` / `JWT::decode()` calls and keeps the PHP ^8.0 floor.

- [ ] **Step 2: Load Composer autoload from the bootstrap**

In `pixelhunter-google-login.php`, immediately after the `require_once …/class-settings.php` line, add:

```php
if ( is_readable( PIXELHUNTER_GOOGLE_LOGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'vendor/autoload.php';
}
```

- [ ] **Step 3: Verify the JWT classes load**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp eval 'echo class_exists("Firebase\\JWT\\JWT") && class_exists("Firebase\\JWT\\JWK") ? "OK" : "FAIL";'
```
Expected: `OK`.

- [ ] **Step 4: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "chore: vendor firebase/php-jwt for id_token verification"
```

---

## Task 3: Token claim validation (pure logic, TDD)

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/tests/assert.php`
- Create: `wp-content/plugins/pixelhunter-google-login/tests/test-token-claims.php`
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-token.php`

**Interfaces:**
- Produces: `PixelHunter_Google_Login_Token::validate_claims( array $payload, string $expected_aud, string $expected_nonce, int $now ): array` returning `['ok'=>bool, 'error'=>?string, 'sub'=>?string, 'email'=>?string, 'name'=>?string]`.
- Produces (Task 7 consumes): `PixelHunter_Google_Login_Token::decode( string $id_token, string $expected_aud, string $expected_nonce ): array` — same return shape.

- [ ] **Step 1: Create the assertion harness** `tests/assert.php`

```php
<?php
// Minimal test harness for pure-logic files. Defines a dummy ABSPATH so the
// `defined('ABSPATH') || exit;` guard in included class files does not exit.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

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
```

- [ ] **Step 2: Write the failing test** `tests/test-token-claims.php`

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run:
```bash
php /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login/tests/test-token-claims.php
```
Expected: FATAL error — class `PixelHunter_Google_Login_Token` not found.

- [ ] **Step 4: Implement** `includes/class-token.php`

```php
<?php
/**
 * id_token verification. `validate_claims()` is pure (no WordPress); `decode()`
 * adds signature verification against Google's JWKS.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class PixelHunter_Google_Login_Token {

	const JWKS_URL   = 'https://www.googleapis.com/oauth2/v3/certs';
	const ISS_ALLOWED = array( 'https://accounts.google.com', 'accounts.google.com' );

	/**
	 * Validate the decoded payload. Pure: takes claims + expectations, returns a result.
	 *
	 * @return array{ok:bool,error:?string,sub:?string,email:?string,name:?string}
	 */
	public static function validate_claims( array $payload, string $expected_aud, string $expected_nonce, int $now ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		if ( ! isset( $payload['iss'] ) || ! in_array( $payload['iss'], self::ISS_ALLOWED, true ) ) {
			return $fail( 'iss' );
		}
		if ( ! isset( $payload['aud'] ) || ! hash_equals( $expected_aud, (string) $payload['aud'] ) ) {
			return $fail( 'aud' );
		}
		if ( ! isset( $payload['exp'] ) || (int) $payload['exp'] <= $now ) {
			return $fail( 'expired' );
		}
		if ( ! isset( $payload['nonce'] ) || ! hash_equals( $expected_nonce, (string) $payload['nonce'] ) ) {
			return $fail( 'nonce' );
		}
		if ( empty( $payload['email_verified'] ) || true !== $payload['email_verified'] ) {
			return $fail( 'email_verified' );
		}
		if ( empty( $payload['email'] ) || ! is_string( $payload['email'] ) ) {
			return $fail( 'email' );
		}
		if ( empty( $payload['sub'] ) ) {
			return $fail( 'sub' );
		}

		return array(
			'ok'    => true,
			'error' => null,
			'sub'   => (string) $payload['sub'],
			'email' => (string) $payload['email'],
			'name'  => isset( $payload['name'] ) ? (string) $payload['name'] : '',
		);
	}

	/**
	 * Verify signature against Google's JWKS, then validate claims.
	 * Throws no exceptions upward: any failure returns ['ok'=>false, ...].
	 */
	public static function decode( string $id_token, string $expected_aud, string $expected_nonce ): array {
		$fail = static function ( string $error ): array {
			return array( 'ok' => false, 'error' => $error, 'sub' => null, 'email' => null, 'name' => null );
		};

		$jwks = self::get_jwks();
		if ( empty( $jwks ) ) {
			return $fail( 'jwks' );
		}

		try {
			$keys    = JWK::parseKeySet( $jwks );
			$decoded = (array) JWT::decode( $id_token, $keys );
		} catch ( \Throwable $e ) {
			return $fail( 'signature' );
		}

		return self::validate_claims( $decoded, $expected_aud, $expected_nonce, time() );
	}

	/** Fetch + cache Google's public keys. */
	private static function get_jwks(): array {
		$cached = get_transient( 'pixelhunter_google_jwks' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$resp = wp_remote_get( self::JWKS_URL, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['keys'] ) ) {
			return array();
		}
		set_transient( 'pixelhunter_google_jwks', $body, HOUR_IN_SECONDS );
		return $body;
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run:
```bash
php /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login/tests/test-token-claims.php
```
Expected: `10 passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: id_token claim validation with tests"
```

---

## Task 4: Account-linking decision tree (pure logic, TDD)

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/tests/test-linking-decision.php`
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-accounts.php` (pure part only)

**Interfaces:**
- Produces: `PixelHunter_Google_Login_Accounts::decide( bool $email_verified, ?int $uid_by_sub, ?int $uid_by_email ): array` returning `['action'=>'reject'|'login'|'create'|'confirm_link', 'user_id'=>?int]`.
- Produces (later tasks): constants `META_SUB='_pixelhunter_google_sub'`, `META_LINKED_AT='_pixelhunter_google_linked_at'`.

- [ ] **Step 1: Write the failing test** `tests/test-linking-decision.php`

```php
<?php
require __DIR__ . '/assert.php';
require dirname( __DIR__ ) . '/includes/class-accounts.php';

$A = 'PixelHunter_Google_Login_Accounts';

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
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login/tests/test-linking-decision.php
```
Expected: FATAL — class not found.

- [ ] **Step 3: Implement the pure part** `includes/class-accounts.php`

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run:
```bash
php /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login/tests/test-linking-decision.php
```
Expected: `5 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: account-linking decision tree with tests"
```

---

## Task 5: Account operations (WordPress integration)

**Files:**
- Modify: `wp-content/plugins/pixelhunter-google-login/includes/class-accounts.php`

**Interfaces:**
- Consumes: `PixelHunter_Google_Login_Accounts::decide()` (Task 4); token claims shape (Task 3).
- Produces: `find_by_sub(string $sub): ?int`, `find_by_email(string $email): ?int`, `create_from_google(string $email, string $sub, string $name): int`, `link_sub(int $user_id, string $sub): void`, and instance method `resolve(array $claims): array` returning `['action'=>string,'user_id'=>?int]` (executing login/create; returning intent for confirm_link/reject).

- [ ] **Step 1: Add the WordPress methods** to `includes/class-accounts.php` (before the closing `}` of the class)

```php
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
```

- [ ] **Step 2: Verify against the live site** (create → login intent, meta written)

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp eval '
require_once WP_PLUGIN_DIR . "/pixelhunter-google-login/includes/class-accounts.php";
$A = new PixelHunter_Google_Login_Accounts();
$claims = array( "sub" => "test-sub-123", "email" => "phgl-test@example.com", "name" => "PHGL Test" );
$r = $A->resolve( $claims );
echo $r["action"] . ":" . ( $r["user_id"] ?? "null" ) . "\n";
$again = $A->resolve( $claims );
echo $again["action"] . ":" . ( $again["user_id"] ?? "null" ) . "\n";
echo get_user_meta( $r["user_id"], "_pixelhunter_google_sub", true ) . "\n";
'
```
Expected: first line `login:<id>` (created), second line `login:<same id>` (found by sub), third line `test-sub-123`.

- [ ] **Step 3: Clean up the test user**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp user delete phgl-test@example.com --yes --reassign=1 2>/dev/null; studio wp eval '$u=get_user_by("email","phgl-test@example.com"); echo $u ? "STILL EXISTS" : "removed";'
```
Expected: `removed`.

- [ ] **Step 4: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: account lookup/create/link + resolve()"
```

---

## Task 6: OAuth `/start` endpoint

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-oauth.php`
- Modify: `pixelhunter-google-login.php` (wire the module)

**Interfaces:**
- Consumes: `PixelHunter_Google_Login_Settings` (Task 1).
- Produces: `PixelHunter_Google_Login_OAuth::register()` (hooks `rest_api_init`); route `GET /pixelhunter-google-login/v1/start`. Sets cookie `phgl_oauth` and transient `pixelhunter_google_state_<id>` = `['state'=>,'nonce'=>,'redirect_to'=>]`.

- [ ] **Step 1: Implement the `/start` half** `includes/class-oauth.php`

```php
<?php
/**
 * OAuth endpoints. /start builds the Google authorization redirect; /callback
 * (Task 7) handles the return.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_OAuth {

	const COOKIE     = 'phgl_oauth';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const STATE_PREFIX = 'pixelhunter_google_state_';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		$ns = PixelHunter_Google_Login_Settings::REST_NAMESPACE;
		register_rest_route(
			$ns,
			'/start',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_start' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function route_start( WP_REST_Request $request ) {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() ) {
			return $this->redirect( wc_get_page_permalink( 'myaccount' ) );
		}

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		$redirect_to = $redirect_to ? wp_validate_redirect( rawurldecode( $redirect_to ), wc_get_page_permalink( 'myaccount' ) ) : wc_get_page_permalink( 'myaccount' );

		$state = wp_generate_password( 32, false );
		$nonce = wp_generate_password( 32, false );
		$id    = wp_generate_password( 32, false );

		set_transient(
			self::STATE_PREFIX . $id,
			array(
				'state'       => $state,
				'nonce'       => $nonce,
				'redirect_to' => $redirect_to,
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_cookie( self::COOKIE, $id );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( PixelHunter_Google_Login_Settings::client_id() ),
				'redirect_uri'  => rawurlencode( PixelHunter_Google_Login_Settings::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( 'openid email profile' ),
				'state'         => $state,
				'nonce'         => $nonce,
				'prompt'        => 'select_account',
				'access_type'   => 'online',
			),
			self::AUTH_URL
		);
		return $this->redirect( $url );
	}

	protected function set_cookie( string $name, string $value ): void {
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + 600,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	protected function clear_cookie( string $name ): void {
		setcookie( $name, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/' ) );
	}

	protected function redirect( string $url ) {
		wp_redirect( $url ); // Google URL / same-host account URL; not user-controlled beyond validated redirect_to.
		exit;
	}
}
```

- [ ] **Step 2: Wire the module** — in `pixelhunter-google-login.php`, replace the `// PHGL_BOOTSTRAP_INIT` marker line with:

```php
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-accounts.php';
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-token.php';
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-oauth.php';
		( new PixelHunter_Google_Login_OAuth() )->register();
		// PHGL_BOOTSTRAP_INIT
```

- [ ] **Step 3: Enable the plugin's settings and verify the redirect**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp option update pixelhunter_google_login_settings '{"enabled":true,"client_id":"test-client.apps.googleusercontent.com","client_secret":"","button_theme":"light"}' --format=json && REST=$(studio wp eval 'echo rest_url("pixelhunter-google-login/v1/start");') && curl -s -o /dev/null -D - "${REST}?redirect_to=%2F" | grep -iE '^location:|^set-cookie: phgl_oauth'
```
Expected: a `location:` header pointing to `https://accounts.google.com/o/oauth2/v2/auth?...` (containing `client_id=test-client…`, `scope=openid…`, `state=`, `nonce=`, `prompt=select_account`) and a `set-cookie: phgl_oauth=` header.

- [ ] **Step 4: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: OAuth /start endpoint (state/nonce + Google redirect)"
```

---

## Task 7: OAuth `/callback` endpoint

**Files:**
- Modify: `wp-content/plugins/pixelhunter-google-login/includes/class-oauth.php`
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-link.php` (pending-link storage only)
- Modify: `pixelhunter-google-login.php` (require class-link.php)

**Interfaces:**
- Consumes: `Token::decode()` (Task 3), `Accounts::resolve()` (Task 5), `Settings` (Task 1), the `/start` state store (Task 6).
- Produces: route `GET /pixelhunter-google-login/v1/callback` (the Redirect URI). `PixelHunter_Google_Login_Link::put(int $user_id, string $sub): void`, `pending(): ?array` (`['user_id'=>int,'sub'=>string]`), `clear(): void`. On failure/reject redirects to My Account with `?phgl_error=<code>`; on confirm_link redirects with `?phgl_link=1`.

- [ ] **Step 1: Create the pending-link store** `includes/class-link.php`

```php
<?php
/**
 * Pending-link token: bridges the callback (which detected an existing email)
 * to the moment the user proves ownership by logging in. Task 8 adds the
 * wp_login linker + the account-page notice.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Link {

	const COOKIE = 'phgl_link';
	const PREFIX = 'pixelhunter_google_link_';

	/** Store a pending link (user to attach the sub to) for ~15 min. */
	public static function put( int $user_id, string $sub ): void {
		$id = wp_generate_password( 32, false );
		set_transient( self::PREFIX . $id, array( 'user_id' => $user_id, 'sub' => $sub ), 15 * MINUTE_IN_SECONDS );
		setcookie(
			self::COOKIE,
			$id,
			array(
				'expires'  => time() + 900,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** @return array{user_id:int,sub:string}|null */
	public static function pending(): ?array {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $id ) {
			return null;
		}
		$data = get_transient( self::PREFIX . $id );
		return is_array( $data ) ? $data : null;
	}

	public static function clear(): void {
		$id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' !== $id ) {
			delete_transient( self::PREFIX . $id );
		}
		setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/' ) );
	}
}
```

- [ ] **Step 2: Register the `/callback` route** — in `includes/class-oauth.php`, inside `routes()`, after the `/start` `register_rest_route(...)` call, add:

```php
		register_rest_route(
			$ns,
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_callback' ),
				'permission_callback' => '__return_true',
			)
		);
```

- [ ] **Step 3: Implement the callback + code exchange** — in `includes/class-oauth.php`, add these methods to the class (before the final `}`):

```php
	public function route_callback( WP_REST_Request $request ) {
		$account_url = wc_get_page_permalink( 'myaccount' );

		// Consume the state store + cookie immediately (single use).
		$cookie_id = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$stored    = $cookie_id ? get_transient( self::STATE_PREFIX . $cookie_id ) : false;
		if ( $cookie_id ) {
			delete_transient( self::STATE_PREFIX . $cookie_id );
		}
		$this->clear_cookie( self::COOKIE );

		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );

		if ( ! is_array( $stored ) || '' === $state || ! hash_equals( (string) $stored['state'], $state ) ) {
			return $this->fail( $account_url, 'state' );
		}
		if ( '' === $code ) {
			return $this->fail( $account_url, 'code' );
		}

		$id_token = $this->exchange_code( $code );
		if ( '' === $id_token ) {
			return $this->fail( $account_url, 'exchange' );
		}

		$claims = PixelHunter_Google_Login_Token::decode(
			$id_token,
			PixelHunter_Google_Login_Settings::client_id(),
			(string) $stored['nonce']
		);
		if ( empty( $claims['ok'] ) ) {
			return $this->fail( $account_url, 'token_' . ( $claims['error'] ?? 'unknown' ) );
		}

		$result = ( new PixelHunter_Google_Login_Accounts() )->resolve( $claims );

		if ( 'login' === $result['action'] && $result['user_id'] ) {
			wp_set_current_user( (int) $result['user_id'] );
			wp_set_auth_cookie( (int) $result['user_id'], true );
			return $this->redirect( wp_validate_redirect( (string) $stored['redirect_to'], $account_url ) );
		}

		if ( 'confirm_link' === $result['action'] && $result['user_id'] ) {
			PixelHunter_Google_Login_Link::put( (int) $result['user_id'], (string) $claims['sub'] );
			return $this->redirect( add_query_arg( 'phgl_link', '1', $account_url ) );
		}

		return $this->fail( $account_url, 'reject' );
	}

	/** Exchange the authorization code for tokens; returns the id_token or ''. */
	protected function exchange_code( string $code ): string {
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => PixelHunter_Google_Login_Settings::client_id(),
					'client_secret' => PixelHunter_Google_Login_Settings::client_secret(),
					'redirect_uri'  => PixelHunter_Google_Login_Settings::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return '';
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ( is_array( $data ) && ! empty( $data['id_token'] ) ) ? (string) $data['id_token'] : '';
	}

	protected function fail( string $account_url, string $code ) {
		return $this->redirect( add_query_arg( 'phgl_error', rawurlencode( $code ), $account_url ) );
	}
```

- [ ] **Step 4: Require class-link.php** — in `pixelhunter-google-login.php`, add before the `class-oauth.php` require:

```php
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-link.php';
```

- [ ] **Step 5: Verify the state-mismatch path is rejected safely**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && CB=$(studio wp eval 'echo rest_url("pixelhunter-google-login/v1/callback");') && curl -s -o /dev/null -D - "${CB}?state=bogus&code=bogus" | grep -i '^location:'
```
Expected: a `location:` header to the My Account page containing `phgl_error=state` (no state cookie present → rejected, never reaches token exchange).

- [ ] **Step 6: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: OAuth /callback — verify token, resolve account, secure link handoff"
```

---

## Task 8: Complete the link — prove ownership on login + notices

**Files:**
- Modify: `wp-content/plugins/pixelhunter-google-login/includes/class-link.php`
- Modify: `pixelhunter-google-login.php` (wire the module)

**Interfaces:**
- Consumes: `Link::pending()/clear()` (Task 7), `Accounts::link_sub()` (Task 5).
- Produces: `PixelHunter_Google_Login_Link::register()` hooking `wp_login` (links the sub after ANY successful login of the pending user — the password proof) and `template_redirect` (renders the `?phgl_link` prompt and `?phgl_error` messages as WooCommerce notices, which the theme shows as toasts).

- [ ] **Step 1: Add the linker + notice renderer** — in `includes/class-link.php`, add these methods to the class (before the final `}`):

```php
	public function register(): void {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_notice' ) );
	}

	/** After a successful login, if a pending link targets THIS user, attach the sub. */
	public function on_login( $user_login, $user ): void {
		$pending = self::pending();
		if ( $pending && (int) $pending['user_id'] === (int) $user->ID ) {
			PixelHunter_Google_Login_Accounts::link_sub( (int) $user->ID, (string) $pending['sub'] );
			self::clear();
		}
	}

	/** Surface the confirm-link prompt / error messages as WooCommerce notices. */
	public function maybe_notice(): void {
		if ( ! function_exists( 'wc_add_notice' ) || is_admin() ) {
			return;
		}
		if ( isset( $_GET['phgl_link'] ) && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wc_add_notice(
				__( 'Já existe uma conta com este email. Inicia sessão com a tua password para ligar o Google (ou usa “Esqueceu-se da password?”).', 'pixelhunter-google-login' ),
				'notice'
			);
		} elseif ( isset( $_GET['phgl_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wc_add_notice( self::error_message( sanitize_text_field( wp_unslash( $_GET['phgl_error'] ) ) ), 'error' );
		}
	}

	protected static function error_message( string $code ): string {
		if ( 'token_email_verified' === $code ) {
			return __( 'A tua conta Google não tem o email verificado, por isso não é possível entrar por aqui.', 'pixelhunter-google-login' );
		}
		if ( 'reject' === $code ) {
			return __( 'Não foi possível iniciar sessão com o Google.', 'pixelhunter-google-login' );
		}
		return __( 'O início de sessão com o Google falhou. Tenta novamente.', 'pixelhunter-google-login' );
	}
```

- [ ] **Step 2: Wire the module** — in `pixelhunter-google-login.php`, add after the `( new PixelHunter_Google_Login_OAuth() )->register();` line:

```php
		( new PixelHunter_Google_Login_Link() )->register();
```

- [ ] **Step 3: Verify the class loads and hooks register without error**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp eval '
$l = new PixelHunter_Google_Login_Link();
$l->register();
echo has_action("wp_login") && has_action("template_redirect") ? "OK" : "FAIL";
'
```
Expected: `OK`.

- [ ] **Step 4: Verify the prompt notice renders on the account page** (browser)

Open the My Account page with `?phgl_link=1` appended (get the URL via `studio wp eval 'echo wc_get_page_permalink("myaccount");'`). Confirm the toast/notice "Já existe uma conta com este email…" appears above the login form.

- [ ] **Step 5: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: complete secure linking on login + user-facing notices"
```

---

## Task 9: The Google button + styles

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-button.php`
- Create: `wp-content/plugins/pixelhunter-google-login/assets/g-logo.svg`
- Create: `wp-content/plugins/pixelhunter-google-login/assets/google-login.css`
- Modify: `pixelhunter-google-login.php` (wire the module)

**Interfaces:**
- Consumes: `Settings::is_enabled()/client_id()/button_theme()/start_url()` (Task 1).
- Produces: `PixelHunter_Google_Login_Button::register()` hooking `woocommerce_login_form_start`, `woocommerce_register_form_start` (render), and `wp_enqueue_scripts` (CSS). Emits `.pc-google-btn` markup.

- [ ] **Step 1: Create the official G logo** `assets/g-logo.svg`

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
```

- [ ] **Step 2: Create the styles** `assets/google-login.css`

```css
.pc-google-login { margin: 0 0 var(--pc-space-4, 16px); }

.pc-google-btn {
	--pc-gbtn-bg: #fff;
	--pc-gbtn-border: #747775;
	--pc-gbtn-text: #1f1f1f;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	width: 100%;
	min-height: 40px;
	padding: 0 16px;
	border: 1px solid var(--pc-gbtn-border);
	border-radius: var(--pc-radius, 8px);
	background: var(--pc-gbtn-bg);
	color: var(--pc-gbtn-text);
	font: 500 14px/1 var(--pc-font, inherit);
	text-decoration: none;
	cursor: pointer;
}
.pc-google-btn:hover { filter: brightness(0.98); }
.pc-google-btn:focus-visible { outline: 2px solid var(--pc-focus, #1a73e8); outline-offset: 2px; }
.pc-google-btn--dark { --pc-gbtn-bg: #131314; --pc-gbtn-border: #8e918f; --pc-gbtn-text: #e3e3e3; }

.pc-google-btn__icon { display: inline-flex; width: 18px; height: 18px; }
.pc-google-btn__icon svg { display: block; width: 18px; height: 18px; }

.pc-google-login__divider { display: flex; align-items: center; text-align: center; color: var(--pc-muted, #6b7280); margin: var(--pc-space-3, 12px) 0; }
.pc-google-login__divider::before,
.pc-google-login__divider::after { content: ""; flex: 1; height: 1px; background: var(--pc-border, #e5e7eb); }
.pc-google-login__divider span { padding: 0 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
```

- [ ] **Step 3: Create the button module** `includes/class-button.php`

```php
<?php
/**
 * Renders the "Continuar com Google" button at the top of the login and register
 * forms (WooCommerce hooks), and enqueues its stylesheet. Self-contained: the
 * theme is not touched.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Button {

	public function register(): void {
		add_action( 'woocommerce_login_form_start', array( $this, 'render' ) );
		add_action( 'woocommerce_register_form_start', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() ) {
			return;
		}
		wp_enqueue_style(
			'pixelhunter-google-login',
			PIXELHUNTER_GOOGLE_LOGIN_URL . 'assets/google-login.css',
			array(),
			PIXELHUNTER_GOOGLE_LOGIN_VERSION
		);
	}

	public function render(): void {
		if ( ! PixelHunter_Google_Login_Settings::is_enabled() || '' === PixelHunter_Google_Login_Settings::client_id() || is_user_logged_in() ) {
			return;
		}
		$theme       = PixelHunter_Google_Login_Settings::button_theme();
		$redirect_to = home_url( add_query_arg( array() ) );
		$url         = PixelHunter_Google_Login_Settings::start_url( $redirect_to );
		$svg         = @file_get_contents( PIXELHUNTER_GOOGLE_LOGIN_DIR . 'assets/g-logo.svg' );
		?>
		<div class="pc-google-login">
			<a class="pc-google-btn pc-google-btn--<?php echo esc_attr( $theme ); ?>" href="<?php echo esc_url( $url ); ?>">
				<span class="pc-google-btn__icon" aria-hidden="true"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- local static SVG asset. ?></span>
				<span class="pc-google-btn__label"><?php esc_html_e( 'Continuar com Google', 'pixelhunter-google-login' ); ?></span>
			</a>
			<div class="pc-google-login__divider"><span><?php esc_html_e( 'ou', 'pixelhunter-google-login' ); ?></span></div>
		</div>
		<?php
	}
}
```

- [ ] **Step 4: Wire the module** — in `pixelhunter-google-login.php`, add after the `( new PixelHunter_Google_Login_Link() )->register();` line:

```php
		require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-button.php';
		( new PixelHunter_Google_Login_Button() )->register();
```

- [ ] **Step 5: Verify the button renders on the account page**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && MY=$(studio wp eval 'echo wc_get_page_permalink("myaccount");') && curl -s "$MY" | grep -c 'pc-google-btn'
```
Expected: `2` (both the login and register tab forms) — or `1` if WooCommerce registration is disabled. Then open the page + the login drawer in the browser to confirm the button appears and, with `button_theme` set to `dark`, uses the dark variant.

- [ ] **Step 6: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: Google button (light/dark, official G) via WooCommerce hooks"
```

---

## Task 10: Admin settings page + setup guide + live status

**Files:**
- Create: `wp-content/plugins/pixelhunter-google-login/includes/class-admin.php`
- Modify: `pixelhunter-google-login.php` (wire the module)

**Interfaces:**
- Consumes: `Settings` (Task 1).
- Produces: `PixelHunter_Google_Login_Admin::register()` hooking `admin_menu` (submenu under WooCommerce) + `admin_init` (register + sanitize the option). Sanitize refuses to store the secret when the constant is present.

- [ ] **Step 1: Create the admin module** `includes/class-admin.php`

```php
<?php
/**
 * Settings page under WooCommerce: credentials, setup guide with deep links,
 * auto-generated Redirect URI, and live status.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Google_Login_Admin {

	const SLUG = 'pixelhunter-google-login';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Login com Google', 'pixelhunter-google-login' ),
			__( 'Login com Google', 'pixelhunter-google-login' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function settings(): void {
		register_setting(
			self::SLUG,
			PixelHunter_Google_Login_Settings::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = array(
			'enabled'      => ! empty( $input['enabled'] ),
			'client_id'    => sanitize_text_field( $input['client_id'] ?? '' ),
			'button_theme' => ( 'dark' === ( $input['button_theme'] ?? 'light' ) ) ? 'dark' : 'light',
		);
		// Never persist the secret to the DB when the wp-config constant supplies it.
		$out['client_secret'] = PixelHunter_Google_Login_Settings::secret_from_constant()
			? ''
			: sanitize_text_field( $input['client_secret'] ?? '' );
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s            = PixelHunter_Google_Login_Settings::get();
		$redirect_uri = PixelHunter_Google_Login_Settings::redirect_uri();
		$from_const   = PixelHunter_Google_Login_Settings::secret_from_constant();
		$has_id       = '' !== PixelHunter_Google_Login_Settings::client_id();
		$has_secret   = '' !== PixelHunter_Google_Login_Settings::client_secret();
		$row          = static function ( bool $ok, string $label ): void {
			printf( '<li>%s %s</li>', $ok ? '✅' : '❌', esc_html( $label ) );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login com Google', 'pixelhunter-google-login' ); ?></h1>

			<h2><?php esc_html_e( 'Configuração', 'pixelhunter-google-login' ); ?></h2>
			<ol>
				<li><a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener"><?php esc_html_e( 'Criar / escolher projeto no Google Cloud', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" rel="noopener"><?php esc_html_e( 'Configurar o ecrã de consentimento OAuth (nome + logo “PixelHunter”)', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener"><?php esc_html_e( 'Criar credenciais → OAuth client ID → Web application', 'pixelhunter-google-login' ); ?></a></li>
			</ol>
			<p><strong><?php esc_html_e( 'Redirect URI (cola no Google):', 'pixelhunter-google-login' ); ?></strong></p>
			<input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $redirect_uri ); ?>" style="width:100%;max-width:640px;font-family:monospace;" />

			<h2><?php esc_html_e( 'Estado', 'pixelhunter-google-login' ); ?></h2>
			<ul>
				<?php
				$row( (bool) $s['enabled'], __( 'Ativo', 'pixelhunter-google-login' ) );
				$row( $has_id, __( 'Client ID definido', 'pixelhunter-google-login' ) );
				$row( $has_secret, $from_const ? __( 'Client Secret definido (via wp-config)', 'pixelhunter-google-login' ) : __( 'Client Secret definido', 'pixelhunter-google-login' ) );
				?>
			</ul>

			<form method="post" action="options.php">
				<?php settings_fields( self::SLUG ); ?>
				<?php $opt = PixelHunter_Google_Login_Settings::OPTION; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ativar', 'pixelhunter-google-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( (bool) $s['enabled'] ); ?> /> <?php esc_html_e( 'Mostrar o botão “Continuar com Google”', 'pixelhunter-google-login' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_client_id"><?php esc_html_e( 'Client ID', 'pixelhunter-google-login' ); ?></label></th>
						<td><input type="text" id="phgl_client_id" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[client_id]" value="<?php echo esc_attr( $s['client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_client_secret"><?php esc_html_e( 'Client Secret', 'pixelhunter-google-login' ); ?></label></th>
						<td>
							<?php if ( $from_const ) : ?>
								<input type="text" id="phgl_client_secret" class="regular-text" value="🔒 <?php esc_attr_e( 'Definido em wp-config.php', 'pixelhunter-google-login' ); ?>" disabled />
								<p class="description"><?php esc_html_e( 'A constante PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET tem prioridade; este campo fica bloqueado.', 'pixelhunter-google-login' ); ?></p>
							<?php else : ?>
								<input type="password" id="phgl_client_secret" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[client_secret]" value="<?php echo esc_attr( $s['client_secret'] ); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Recomendado: define antes em wp-config.php para não guardar o segredo na base de dados.', 'pixelhunter-google-login' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="phgl_theme"><?php esc_html_e( 'Tema do botão', 'pixelhunter-google-login' ); ?></label></th>
						<td>
							<select id="phgl_theme" name="<?php echo esc_attr( $opt ); ?>[button_theme]">
								<option value="light" <?php selected( 'light', $s['button_theme'] ); ?>><?php esc_html_e( 'Claro', 'pixelhunter-google-login' ); ?></option>
								<option value="dark" <?php selected( 'dark', $s['button_theme'] ); ?>><?php esc_html_e( 'Escuro', 'pixelhunter-google-login' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
```

- [ ] **Step 2: Wire the module** — in `pixelhunter-google-login.php`, add after the button wiring:

```php
		if ( is_admin() ) {
			require_once PIXELHUNTER_GOOGLE_LOGIN_DIR . 'includes/class-admin.php';
			( new PixelHunter_Google_Login_Admin() )->register();
		}
```

- [ ] **Step 3: Verify the page + sanitize behaviour**

Run (confirms the sanitize callback drops the secret when the constant is present):
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp eval '
define("PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET","GOCSPX-fromconfig");
$a = new PixelHunter_Google_Login_Admin();
$clean = $a->sanitize(array("enabled"=>"1","client_id"=>" id ","client_secret"=>"should-not-store","button_theme"=>"dark"));
echo ($clean["client_secret"] === "" && $clean["client_id"] === "id" && $clean["button_theme"] === "dark" && $clean["enabled"] === true) ? "OK" : "FAIL";
'
```
Expected: `OK`. Then open **wp-admin → WooCommerce → Login com Google** in the browser: the guide, the Redirect URI field, the live status list, and the form all render.

- [ ] **Step 4: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "feat: admin settings page with setup guide + live status"
```

---

## Task 11: End-to-end acceptance + reset to a clean state

**Files:**
- Modify: `wp-content/plugins/pixelhunter-google-login/README.md` (fill setup notes)

**Interfaces:** none new. This task validates the whole flow with real Google credentials and returns the plugin to an unconfigured, safe default.

- [ ] **Step 1: Run the pure-logic test suite once more (regression)**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && php tests/test-token-claims.php && php tests/test-linking-decision.php
```
Expected: both print `… 0 failed`.

- [ ] **Step 2: Real end-to-end (manual, needs real Google OAuth credentials)**

Prerequisites (done once by the site owner): create the OAuth Web client in Google Cloud Console; set the **Authorized redirect URI** to the value shown on the admin page; put `define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-…' );` in `wp-config.php`; enter the Client ID + enable on the admin page.

Verify each branch of the decision tree end-to-end in the browser:
- [ ] **New email:** sign in with a Google account that has no store account → account is created and you land back logged in.
- [ ] **Returning:** sign out, click Google again → instant login (no consent screen), same account.
- [ ] **Existing email:** create a normal password account with an email, sign out, then click Google with the same Google email → redirected to My Account with the "prova de posse" notice; log in with the password → account is now linked (subsequent Google clicks are instant). Confirm via `studio wp user meta get <id> _pixelhunter_google_sub`.
- [ ] **Error path:** confirm a tampered `state` (see Task 7 Step 5) shows the error toast, not a login.

- [ ] **Step 3: Fill the README setup section**

Replace the README body with the setup steps (Google Console + wp-config constant + admin page), the Redirect URI note, and the pure-test command. Keep it concise.

- [ ] **Step 4: Reset to a safe default (no half-configured live state)**

Run:
```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes && studio wp option update pixelhunter_google_login_settings '{"enabled":false,"client_id":"","client_secret":"","button_theme":"light"}' --format=json
```
Expected: option reset; the button no longer renders until the owner configures real credentials.

- [ ] **Step 5: Commit**

```bash
cd /Users/miguelcarneiro/Studio/pixelhunterclithes/wp-content/plugins/pixelhunter-google-login && git add -A && git commit -q -m "docs: README setup; v0.1.0 acceptance"
```

---

## Self-Review

**Spec coverage** (each spec section → task):
- §4 files/repo/secret → Task 1 (scaffold, settings, constant-wins) + Task 2 (vendor).
- §5 OAuth flow (/start, /callback) → Tasks 6 & 7.
- §6 token verification (JWKS + claims) → Task 3.
- §7 linking (decide + create/link + confirm screen) → Tasks 4, 5, 7 (handoff), 8 (prove-on-login).
- §8 button (hooks, text, light/dark, divider, G icon, no JS) → Task 9.
- §9 admin page (fields, guide, auto Redirect URI, live status, secret lock) → Task 10.
- §10 data (option, meta, transients, cookies) → Tasks 1, 5, 6, 7.
- §11 security checklist → enforced across Tasks 3, 6, 7, 8, 10.
- §13 local testing → Task 6/7 curl checks + Task 11 e2e.
- §14 dependency (php-jwt) → Task 2.

**Placeholder scan:** No TBD/TODO; every code step contains complete code. The only ellipses are inside example secret strings (`GOCSPX-…`).

**Type consistency:** `validate_claims`/`decode` return the same 5-key shape used by `resolve()` (`sub`,`email`,`name`); `decide()` returns `action`+`user_id` consumed by the callback; `Link::pending()` returns `user_id`+`sub` consumed by `on_login`. Settings/OAuth/Link/Button/Admin method names match between definition and call sites.

**Notes / assumptions carried from the spec:** confirm-link is completed by ANY successful login of the pending user (the password itself is the proof) via the `wp_login` hook — simpler and more robust than a bespoke password form, and still mitigates pre-hijacking (an attacker's pre-set password is displaced by the victim's password reset). The `?phgl_error` / `?phgl_link` query flags are non-sensitive routing hints rendered as WooCommerce notices (theme shows them as toasts).
