=== PixelHunter Social Login ===
Contributors: pixelhunter
Author: Miguel Carneiro
Author URI: https://pixelhunter.pt
Plugin URI: https://github.com/pixelhunter1/pixelhunter-google-login
Tags: woocommerce, login, google, microsoft, oauth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Google and Microsoft login/registration for WooCommerce (OAuth 2.0 / OpenID Connect) — self-contained, no third-party services.

== Description ==

Login and registration with **Google** and **Microsoft** (personal accounts: Hotmail, Outlook.com, Live) for WooCommerce stores, via **OAuth 2.0 / OpenID Connect** — self-contained, with no third-party plugins or intermediary services. Customer credentials never pass through the store: authentication happens at Google/Microsoft and the plugin only cryptographically validates the result.

= Features =

* **Two providers, one architecture** — every provider-specific fact (endpoints, claim policy, branding) lives in a single registry; the rest of the code is provider-agnostic. Adding a third provider is adding one registry entry.
* **Automatic account creation** — the first login creates a WooCommerce customer (role `customer`) with a strong random password.
* **Secure linking of existing accounts** — if the email already has a store account, the plugin does **not** log in directly: it asks for the password once to prove ownership, and only then links the external identity (prevents account takeover by email).
* **The same email on both providers lands on the same WP account** — linked identities are stored in distinct per-provider meta.
* **Full `id_token` validation** — signature against the provider's JWKS (with cache), `iss`, `aud`, `exp`, `nonce`, and per-provider `email_verified` policy.
* **CSRF protection** — single-use `state` + `nonce` in a transient with an `HttpOnly`/`SameSite=Lax` cookie.
* **Secrets outside the database (optional, recommended)** — constants in `wp-config.php` take priority and lock the admin field.
* **Organized admin** — page under WooCommerce → Social Login with per-provider tabs, a step-by-step guide with deep links to the consoles, a ready-to-copy Redirect URI, and live status.
* **Accessible, responsive buttons** — side by side when there's width, stacked when there isn't; short labels with full `aria-label`; light/dark themes.
* **No runtime dependencies beyond `firebase/php-jwt`** (vendored in the repo — the plugin installs by copy, with no `composer install`).

= How it works =

The customer authenticates **at the provider** (the store never sees the password); the plugin verifies the signed `id_token` (JWKS), validates the claims (`iss` / `aud` / `exp` / `nonce` / email), and resolves the account:

* Identity already linked (`sub` known) → immediate login
* New email → creates a WooCommerce customer + links the identity + login
* Email already exists, no linked identity → asks for password login once and links on success
* Email not verified at the provider → rejected with a message to the customer

== Installation ==

1. In wp-admin: **Plugins → Add New → Upload Plugin** → choose the `.zip` → **Install Now**. (Alternatively, unzip the folder into `wp-content/plugins/`.) The `vendor/` directory is bundled — no `composer install` needed.
2. Activate the plugin in Plugins.
3. Configure under **WooCommerce → Social Login** — each tab has the step-by-step guide for its console and the ready-to-copy Redirect URI.

The buttons appear automatically on the WooCommerce login and register forms (`woocommerce_login_form_start` / `woocommerce_register_form_start`). No theme changes needed.

You bring your own free OAuth credentials: Google Cloud Console and/or the Azure portal.

== Frequently Asked Questions ==

= Does the store ever see the customer's Google/Microsoft password? =

No. Authentication happens entirely at the provider. The plugin only receives and cryptographically verifies a signed `id_token`.

= What happens if the email already has an account in the store? =

The plugin does not log in directly. It requires a one-time password login to prove ownership before linking the external identity, which prevents account takeover by email address.

= Can I keep the client secrets out of the database? =

Yes, and it's recommended. Define the constants in `wp-config.php`; they take priority over the admin fields and lock them.

= Why is the `email_verified` policy different for Microsoft? =

Google emits the `email_verified` claim and the plugin requires `true`. Microsoft personal accounts (tenant `consumers`) do not emit the claim — the email is that of the Microsoft account itself — so its absence is accepted **only** for Microsoft, and the `iss` is validated against the fixed personal-accounts tenant GUID. An explicit `email_verified=false` is always rejected, whatever the source.

== Screenshots ==

1. Google and Microsoft buttons on the WooCommerce login/register form (light and dark themes, responsive).
2. Admin settings under WooCommerce → Social Login — Google tab with step-by-step guide, ready-to-copy Redirect URI, and live status.
3. Admin settings — Microsoft tab (Azure setup guide and secret-expiry note).

== Changelog ==

= 0.3.3 =
* Screenshots now render as images in the "View Details" modal. WordPress's readme parser strips `<img>` from the screenshots section, so the images are injected after parsing (from the repo assets) instead.

= 0.3.2 =
* Plugin metadata: author and plugin URI in the header, and a WordPress.org-format `readme.txt` that populates the "View Details" modal (Description, Installation, FAQ, Changelog).
* Screenshots of the login buttons and the admin settings.

= 0.3.1 =
* Auto-update via GitHub Releases (Plugin Update Checker): the release tag is compared against the plugin's `Version:` header and offered on the normal Plugins → Updates screen. `Update URI: false` keeps wordpress.org from hijacking the slug.

= 0.3.0 =
* Canonical WordPress i18n: English source strings with bundled pt_PT translation.
* Simplified account-linking decision logic.

= 0.2.1 =
* Multi-provider: generalized from Google-only to Google + Microsoft (personal accounts) on one provider-agnostic architecture.
* Layout/CSS refinements to the buttons.

= 0.2.0 =
* Fire `wp_login` on OAuth login; JWT clock-skew leeway; stored admin secret masked in the UI.

= 0.1.1 =
* `box-sizing` fix on the buttons; setup-instruction updates.

= 0.1.0 =
* Initial release: OAuth `/start` and `/callback` endpoints, single-use `state`/`nonce` CSRF protection, full `id_token` claim validation against the provider JWKS, secure account lookup/create/link, the Google button via WooCommerce hooks, and the admin settings page with setup guide and live status.

== Upgrade Notice ==

= 0.3.3 =
Screenshots now show as images in the plugin's "View Details" screen. No functional changes.

= 0.3.2 =
Adds full plugin details (description, changelog, screenshots) to the WordPress "View Details" screen. No functional changes.

= 0.3.1 =
Enables one-click updates straight from the Plugins screen via GitHub Releases.
