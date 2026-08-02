=== PixelHunter Social Login ===
Contributors: pixelhunter
Tags: woocommerce, login, google, microsoft, oauth
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.4.3
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

1. In wp-admin: **Plugins → Add New**, search for "PixelHunter Social Login", then **Install Now**. The `vendor/` directory is bundled — no `composer install` needed.
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

== External services ==

This plugin is an interface to the sign-in services of Google and Microsoft. It contacts them only when you, the site administrator, enable and configure a provider, and only while a visitor is actively signing in with that provider. There is no telemetry, no analytics, and no data is ever sent to PixelHunter or to any other third party.

= Google Sign-In (used only when the Google provider is enabled) =

* `accounts.google.com` — the visitor's browser is redirected here to sign in. The request carries the Client ID you configured, the redirect URI of your site, the requested scopes (`openid email profile`), and a single-use `state`/`nonce`. The visitor enters their credentials **at Google**; the store never sees them.
* `oauth2.googleapis.com` — server-to-server exchange of the authorization code for an `id_token`. Sends the Client ID, the Client Secret, the authorization code, and the redirect URI.
* `www.googleapis.com` — fetches Google's public signing keys (JWKS) to verify the `id_token` signature. No site or visitor data is sent; the response is cached.

Data received back from Google and stored on your site: the account identifier (`sub`), email address, name, and the `email_verified` flag. The `sub` is stored as user meta so the account can be recognised on the next sign-in.

Google terms of service: https://policies.google.com/terms — Google privacy policy: https://policies.google.com/privacy

= Microsoft identity platform (used only when the Microsoft provider is enabled) =

* `login.microsoftonline.com` — the same three roles as above (visitor sign-in redirect, code-for-token exchange, and JWKS key fetch), against the `consumers` tenant for personal Microsoft accounts.

Data received back from Microsoft and stored on your site: the account identifier (`sub`), email address, and name.

Microsoft services agreement: https://www.microsoft.com/servicesagreement — Microsoft privacy statement: https://privacy.microsoft.com/privacystatement

== Screenshots ==

1. Google and Microsoft buttons on the WooCommerce login/register form (light and dark themes, responsive).
2. Admin settings under WooCommerce → Social Login — Google tab with step-by-step guide, ready-to-copy Redirect URI, and live status.
3. Admin settings — Microsoft tab (Azure setup guide and secret-expiry note).

== Changelog ==

= 0.4.3 =
* **Fixed:** sign-in could fail permanently on hosts that force browser caching of HTML/PHP (WP-Optimize, LiteSpeed Cache, W3TC and similar). The browser replayed a cached redirect carrying an expired one-time state, so every attempt ended in "Signing in failed". The sign-in URL is now unique per page render and the OAuth routes send no-cache headers.
* Credential fields warn when the value does not match the provider's known format (for example, the Azure "Secret ID" pasted into the Client Secret field, which should be the secret's "Value"). The Status panel now distinguishes "filled in" from "correct format" instead of showing a green check for any non-empty value.
* Clearer sign-in error messages: an expired session now says so and tells the customer to try again from the account page, instead of a generic failure notice.

= 0.4.2 =
* First release from the WordPress.org Plugin Directory.
* Added the `Requires Plugins: woocommerce` header: the plugin only hooks into the WooCommerce login and registration forms, so WordPress now refuses to activate it without WooCommerce instead of activating and doing nothing.

= 0.4.1 =
* The Client Secret is no longer passed through `sanitize_text_field()` when saved: it is an opaque credential and sanitizing could corrupt valid secrets. Same for the OAuth authorization code on the callback.
* Translation files are no longer bundled; translations come from translate.wordpress.org.

= 0.4.0 =
* Prepared for the WordPress.org Plugin Directory: plugin folder, main file and text domain renamed to `pixelhunter-social-login`; the bundled update checker and the `Update URI` header were removed (updates now come from the directory).
* **Breaking:** the Redirect URI changed to `/wp-json/pixelhunter-social-login/v1/…`. Re-copy it from **WooCommerce → Social Login** into the Google Cloud Console and the Azure portal, otherwise sign-in fails with `redirect_uri_mismatch`.
* Added an "External services" section documenting every request made to Google and Microsoft and the data involved.
* No change to stored settings or to already-linked customer accounts.

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

= 0.4.3 =
Fixes sign-in failing permanently on hosts that force browser caching of HTML/PHP. Recommended for every store. No settings change.

= 0.4.0 =
The Redirect URI changed. After updating, copy the new one from WooCommerce → Social Login into the Google Cloud Console and the Azure portal, or sign-in will fail. Settings and linked accounts are preserved.

= 0.3.3 =
Screenshots now show as images in the plugin's "View Details" screen. No functional changes.

= 0.3.2 =
Adds full plugin details (description, changelog, screenshots) to the WordPress "View Details" screen. No functional changes.

= 0.3.1 =
Enables one-click updates straight from the Plugins screen via GitHub Releases.
