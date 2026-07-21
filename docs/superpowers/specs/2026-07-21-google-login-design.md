# PixelHunter Google Login — Design

- **Data:** 2026-07-21
- **Estado:** Aprovado (design). Próximo passo: plano de implementação (writing-plans).
- **Autor:** Miguel + Claude (brainstorming)

## 1. Objetivo

Adicionar "Continuar com Google" ao e-commerce (drawer de login e página *A minha
conta*), construído por nós, sem plugin de terceiros e sem serviço externo. Usa
OAuth 2.0 / OpenID Connect com **Authorization Code flow** server-side. O
WordPress/WooCommerce é o backend; a única terceira parte é o próprio Google.

Requisito transversal do dono: **"isto não pode falhar"** — segurança e correção
acima de conveniência.

## 2. Âmbito

### Dentro (núcleo v1)
- Botão "Continuar com Google" no drawer de login **e** na página *A minha conta*
  (mesmo template `woocommerce/myaccount/form-login.php`, dois separadores).
- Utilizador novo (email desconhecido) → cria conta + entra.
- Utilizador Google recorrente (`sub` conhecido) → entra.
- Email já existente (com password) → **linking seguro** com prova de posse.
- Página de administração com guia de configuração e estado ao vivo.
- Botão nas variantes **light** e **dark** (configurável), com o ícone G oficial.

### Fora (deferido)
- Ligar/desligar Google nas definições da conta (utilizador já autenticado).
- Google One Tap / fluxo em popup.
- Outros provedores (Facebook, Apple, etc.).
- Guardar tokens do Google por utilizador (não é preciso: só autenticamos).

## 3. Princípio-chave que reduz o risco

Só fazemos **autenticação**, não autorização contínua. O `id_token` é usado **uma
vez** para descobrir quem é o utilizador; depois é descartado. **Não guardamos
`access_token` nem `refresh_token`.** O único dado persistido por utilizador é o
`sub` do Google (identificador estável, **não é segredo**). Isto encolhe muito a
superfície de ataque.

## 4. Arquitetura & ficheiros

Plugin próprio, a espelhar o `pixelhunter-shipping`. Repo:
`github.com/pixelhunter1/pixelhunter-google-login`.

```
wp-content/plugins/pixelhunter-google-login/
├── pixelhunter-google-login.php   Header (Update URI: false) + bootstrap + constantes
├── includes/
│   ├── class-oauth.php       Rotas REST /start e /callback; state/nonce; troca de code
│   ├── class-token.php       Verificação do id_token (assinatura JWKS, claims)
│   ├── class-accounts.php    Árvore de decisão de linking; criação/ligação de contas
│   ├── class-button.php      Injeta o botão nos hooks do WooCommerce; enqueue do CSS
│   └── class-admin.php       Página de definições + guia + estado ao vivo
├── assets/
│   ├── google-login.css      Estilo do botão (consome var(--pc-…, fallback))
│   └── g-logo.svg            Ícone G oficial (4 cores), inline no markup
├── vendor/firebase/php-jwt/  Lib de verificação JWT (vendored, commitada)
├── README.md
└── .gitignore
```

**Convenções:** prefixo de funções/hooks `pixelhunter_google_`, constantes
`PIXELHUNTER_GOOGLE_LOGIN_*`, text domain `pixelhunter-google-login`, classes CSS
`.pc-google-…`. Cada classe tem uma responsabilidade única e testável.

### Onde vivem as credenciais
| Credencial | Segredo? | Onde |
|---|---|---|
| Client ID | Não (público) | option `pixelhunter_google_login_settings` (BD) |
| Client Secret | **Sim** | Constante `PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET` no `wp-config.php` |
| Tema light/dark, ativar | Não | option `pixelhunter_google_login_settings` |

O plugin **lê primeiro a constante**; se existir, o campo do secret na admin fica
bloqueado ("🔒 Definido em wp-config.php") e a BD nunca guarda o segredo. Campo de
fallback existe para comodidade, com aviso do trade-off.

```php
// wp-config.php (fora do git)
define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-...' );
```

## 5. Fluxo OAuth (2 rotas REST)

Namespace REST: `pixelhunter-google-login/v1`.

### 5.1 `GET /start`
- Recebe `redirect_to` (URL de origem, validado com `wp_validate_redirect` para o
  próprio host).
- Gera `state` (aleatório) e `nonce` (aleatório).
- Guarda numa **transient curta** (≤10 min), com `nonce` + `redirect_to`, indexada
  por um id aleatório colocado num **cookie HttpOnly + SameSite=Lax**.
- Redireciona para `https://accounts.google.com/o/oauth2/v2/auth` com:
  `client_id`, `redirect_uri` (a rota /callback), `response_type=code`,
  `scope=openid email profile`, `state`, `nonce`, `prompt=select_account`.

### 5.2 `GET /callback` (este é o **Redirect URI** registado no Google)
1. Valida `state` contra a transient (via cookie). Falha → recusa.
2. Troca `code` por tokens: `POST https://oauth2.googleapis.com/token`
   (server-to-server, com `client_id` + `client_secret`), via `wp_remote_post`.
3. **Verifica o `id_token`** (secção 6).
4. Corre a lógica de linking (secção 7).
5. `wp_set_auth_cookie()` + redireciona para `redirect_to`.

Todos os erros → limpa a transient/cookie e devolve o utilizador ao login com um
notice (toast — ver [[pixelhunter-dynamic-notices]]).

## 6. Verificação do id_token (`class-token.php`)

O `id_token` é um JWT. Verificar **tudo**:
- **Assinatura** contra as chaves públicas do Google (JWKS de
  `https://www.googleapis.com/oauth2/v3/certs`), com cache das chaves (respeitando
  `Cache-Control`) via transient. Usa `firebase/php-jwt`.
- `iss` ∈ { `https://accounts.google.com`, `accounts.google.com` }.
- `aud` === o nosso Client ID.
- `exp` no futuro (e `iat`/`nbf` sãos).
- `nonce` === o nonce guardado na transient (anti-replay).
- **`email_verified === true`** — obrigatório; senão recusa.

Claims usadas depois: `sub`, `email`, `email_verified`, `name` (opcional, para o
display name na criação).

## 7. Modelo de conta & linking (`class-accounts.php`)

**Chave de ligação:** user meta `_pixelhunter_google_sub`. O email é único no
WordPress (`user_email`), logo nunca há contas duplicadas — só há *ligar* ou
*recusar*. "Ligar" = a mesma conta com uma segunda forma de entrar.

Árvore de decisão no callback (depois de o token estar verificado):

```
email_verified !== true                          → RECUSA (notice)
existe user com este google_sub                  → ENTRA
senão, NÃO existe user com este email            → CRIA conta + grava sub + ENTRA
senão, existe user com este email (tem password) → ECRÃ "confirmar posse"
```

### 7.1 Criação de conta (email novo)
`wp_insert_user` com email do Google, username gerado, password aleatória forte,
role `customer`. Grava `_pixelhunter_google_sub`. Dispara os hooks normais de
registo do WooCommerce onde aplicável.

### 7.2 Ecrã "confirmar posse" (único momento com fricção)
Motivo: ligar dá acesso a uma conta com dados reais → tem de haver prova de posse
(mitiga *pre-hijacking*). Fluxo:
- Callback deteta email existente → guarda um **pending-link token** curto
  (transient + cookie) com `sub` + `user_id` + `email`.
- Redireciona para a página de conta em modo "confirmar", onde o plugin mostra:
  > *"Já existe uma conta com este email. Introduz a password para ligar o Google."*
  > → [Ligar]  ·  *Esqueci a password*
- Submissão (com nonce próprio) → `wp_check_password`:
  - **Certa** → grava `_pixelhunter_google_sub` + `wp_set_auth_cookie()` + entra.
  - **Errada** → notice de erro, mantém o ecrã.
- Fallback: quem não sabe a password usa a **reposição por email normal do WP**
  (controla o inbox → recupera → depois liga).

Segurança: a ligação exige saber a password **ou** controlar o email — nunca só o
match de email. Não é mais fraco do que o "recuperar password" que já existe.

## 8. Botão (`class-button.php`)

- Injetado por `add_action('woocommerce_login_form_start', …)` **e**
  `add_action('woocommerce_register_form_start', …)` → aparece no topo dos dois
  separadores (Entrar / Criar conta), com um divisor "— ou —" antes dos campos.
- **Texto:** "Continuar com Google" (serve entrar e criar).
- **Ícone G** oficial (SVG inline, 4 cores, sem recolorir nem deformar).
- **Zero JavaScript:** é um `<a href="…/start?redirect_to=…">`. O `redirect_to`
  é a origem atual (o mesmo conceito do helper de redirect já usado no drawer).
- **Light/dark:** configurável (option), default **light**. Um só componente com
  modificador `.pc-google-btn--dark` que troca apenas as vars de cor:
  - light: bg `#FFFFFF`, borda `#747775`, texto `#1F1F1F`
  - dark: bg `#131314`, borda `#8E918F`, texto `#E3E3E3`
- **A11y:** `<a>` com texto real; altura ~40px; foco visível; contraste conforme.
- **Compliance:** segue as guidelines de marca do Google (ícone oficial inalterado,
  texto permitido, tamanhos mínimos) para não ser rejeitado na verificação.
- CSS num ficheiro do plugin, enfileirado só onde o botão aparece; consome vars do
  tema com fallback (`var(--pc-radius, 8px)`), respeitando "no inline CSS".

## 9. Página de administração (`class-admin.php`)

Submenu sob **WooCommerce**. Campos:
- Ativar login com Google (toggle)
- Client ID
- Client Secret (bloqueado + "🔒 Definido em wp-config.php" se a constante existir)
- Tema do botão: light / dark

Painel-guia (onboarding):
- Passos numerados com **links diretos** para o Google Cloud Console:
  - Criar projeto → `console.cloud.google.com/projectcreate`
  - Ecrã de consentimento → `console.cloud.google.com/apis/credentials/consent`
  - Criar credenciais OAuth → `console.cloud.google.com/apis/credentials`
- **Redirect URI gerado automaticamente** (a partir do URL atual do site) com botão
  "Copiar" — elimina o erro nº1 (URI que não bate certo).
- **Estado ao vivo** ✓/✗ de cada requisito (Client ID presente, Secret definido,
  ativo) e um estado geral com o que falta.

## 10. Dados

| Tipo | Chave | Conteúdo |
|---|---|---|
| Option | `pixelhunter_google_login_settings` | `enabled`, `client_id`, `client_secret` (fallback), `button_theme` |
| Constante | `PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET` | Client Secret (preferido) |
| User meta | `_pixelhunter_google_sub` | `sub` do Google (chave de ligação) |
| User meta | `_pixelhunter_google_linked_at` | timestamp da ligação (auditoria) |
| Transient+cookie | `pixelhunter_google_state_<id>` | `nonce`, `redirect_to` (fluxo OAuth) |
| Transient+cookie | `pixelhunter_google_link_<id>` | `sub`, `user_id`, `email` (pending-link) |
| Transient | `pixelhunter_google_jwks` | chaves públicas do Google (cache) |

## 11. Segurança (checklist garantida pelo plugin)

- Authorization Code flow (nunca implicit).
- Client Secret só no servidor (nunca em JS/frontend).
- `state` validado (CSRF); `nonce` validado (replay).
- Verificação completa do `id_token` (assinatura + iss + aud + exp + nonce).
- `email_verified === true` obrigatório.
- Linking com prova de posse (password ou reposição por email) → mitiga
  pre-hijacking.
- Cookies HttpOnly + SameSite=Lax; transients curtas; limpeza após uso.
- Nonces do WordPress nos formulários; sanitização de input, escape de output.
- HTTPS obrigatório em produção (localhost em http permitido só em dev).

## 12. Configuração no Google Cloud Console (fora do código)

- Tipo de credencial: **OAuth client ID → Web application**.
- **Authorized redirect URIs:** o valor mostrado pela página admin.
- **Ecrã de consentimento:** nome da app "PixelHunter" + logótipo (é o que o
  utilizador vê na página do Google).
- Scopes: `openid`, `email`, `profile` — **não é preciso ativar nenhuma API**
  (o email/nome vêm no id_token).

## 13. Testar em local (Studio)

- O Google aceita `http://localhost` como Redirect URI em desenvolvimento (sem
  HTTPS). Registamos o valor exato que a página admin mostra
  (`http://localhost:<porta>/wp-json/pixelhunter-google-login/v1/callback`).
- A porta do Studio é dinâmica: se mudar, atualiza-se **um** campo no Google
  Console (a página admin mostra sempre o valor atual).
- Verificação: correr o fluxo completo com uma conta Google real e confirmar os 4
  ramos da árvore de decisão (novo, recorrente, email existente, email_verified
  falso).

## 14. Dependências

- `firebase/php-jwt` (vendored/commitado) para verificação de assinatura JWT — não
  escrever crypto à mão.
- WooCommerce (hooks do formulário de login/registo).
- WordPress 6.0+ / PHP 7.4+ (a par do plugin de portes).

## 15. Pressupostos & notas

- O template `form-login.php` do tema mantém os hooks `woocommerce_login_form_start`
  e `woocommerce_register_form_start` (confirmado no código atual).
- Se o registo do WooCommerce estiver desativado, o separador "Criar conta" não
  existe; o botão continua a funcionar no separador "Entrar" (o Google cria a conta
  na mesma pelo ramo "email novo").
- Base de dados SQLite (Studio) — só usamos APIs do WordPress (`$wpdb`, options,
  meta, transients); nada específico de MySQL.
