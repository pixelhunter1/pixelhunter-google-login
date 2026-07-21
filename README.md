# PixelHunter Google Login

Login/registo com Google (OAuth 2.0 / OpenID Connect) para WooCommerce, construído
internamente (sem plugin de terceiros). Ver
`docs/superpowers/specs/2026-07-21-google-login-design.md` para o design completo.

## Setup (Google Cloud Console)

1. Cria (ou reutiliza) um projeto em https://console.cloud.google.com/.
2. **APIs & Services → OAuth consent screen**: configura o nome da app e o logo
   (é o que os utilizadores veem no ecrã de consentimento da Google).
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID**,
   tipo **Web application**.
4. Em **Authorized redirect URIs**, cola o valor mostrado na página de admin do
   plugin (**WooCommerce → Login com Google**), campo "Redirect URI (cola no
   Google)". Em desenvolvimento local via Studio é tipicamente:
   `http://localhost:<porta>/wp-json/pixelhunter-google-login/v1/callback`
   (usa `studio status` para confirmar a porta atual — não é fixa).
5. Copia o **Client ID** e o **Client Secret** gerados.

## Configurar o site

1. No `wp-config.php`, define o secret (nunca é guardado na base de dados
   quando esta constante existe):
   ```php
   define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-...' );
   ```
2. Em **WooCommerce → Login com Google**: cola o **Client ID**, confirma o
   estado "secret definido via wp-config" e ativa o botão (**Enabled**).
3. Guarda. O botão de login com Google aparece automaticamente no
   `[woocommerce_my_account]` (login e registo).

## Checklist de aceitação manual (após configurar credenciais reais)

Percorre as 4 ramificações da árvore de decisão no browser:

- **Email novo:** login com uma conta Google sem conta na loja → cria conta e
  fica autenticado.
- **Regresso:** logout, clica em Google outra vez → login instantâneo (sem
  ecrã de consentimento), mesma conta.
- **Email existente:** cria uma conta normal por password com um email, faz
  logout, clica em Google com o mesmo email → redireciona para a My Account
  com o aviso de "prova de posse"; autentica com a password → a conta fica
  ligada (cliques seguintes em Google são instantâneos). Confirma com:
  `studio wp user meta get <id> _pixelhunter_google_sub`
- **Erro:** um `state` adulterado no callback mostra o toast de erro, nunca
  um login.

## Testes de lógica pura

```bash
php tests/test-token-claims.php && php tests/test-linking-decision.php
```
