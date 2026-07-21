# PixelHunter Google Login

Login/registo com Google (OAuth 2.0 / OpenID Connect), construído internamente.
Ver `docs/superpowers/specs/2026-07-21-google-login-design.md` para o design.

## Segredo
Define no `wp-config.php`:
`define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-...' );`

## Testes de lógica pura
`php tests/test-token-claims.php && php tests/test-linking-decision.php`
