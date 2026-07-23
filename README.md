# PixelHunter Social Login

Login/registo com **Google** e **Microsoft** (contas pessoais: Hotmail, Outlook.com,
Live) via OAuth 2.0 / OpenID Connect para WooCommerce, construído internamente
(sem plugin de terceiros). Ver `docs/superpowers/specs/2026-07-21-google-login-design.md`
para o design original (Google-only; a generalização multi-provider veio depois).

> **Nota sobre nomes:** a pasta/slug continua `pixelhunter-google-login` (mudar
> desativava o plugin), e os nomes legados do Google — opção
> `pixelhunter_google_login_settings`, constante
> `PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET`, meta `_pixelhunter_google_sub`,
> rota `/callback` — mantêm-se para não partir config, contas ligadas e o
> Redirect URI já registado na Google. Tudo o que é novo usa o prefixo neutro
> `PixelHunter_Login_*`; os factos por provider vivem em
> `includes/class-providers.php`.

## Setup Google (Google Cloud Console)

1. Cria (ou reutiliza) um projeto em https://console.cloud.google.com/.
2. **APIs & Services → OAuth consent screen**: configura o nome da app e o logo
   (é o que os utilizadores veem no ecrã de consentimento da Google).
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID**,
   tipo **Web application**.
4. Em **Authorized redirect URIs**, cola o valor mostrado na página de admin do
   plugin (**WooCommerce → Login social**), secção Google. Em desenvolvimento
   local via Studio é tipicamente:
   `http://localhost:<porta>/wp-json/pixelhunter-google-login/v1/callback`
   (usa `studio status` para confirmar a porta atual — não é fixa).
5. Copia o **Client ID** e o **Client Secret** gerados.

## Setup Microsoft (portal Azure / Entra ID)

1. Abre diretamente o formulário **[Registar uma aplicação](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/CreateApplicationBlade)**.
   Em **Nome** escreve o nome da loja — é o que os clientes veem no ecrã de
   consentimento da Microsoft.
2. **Tipos de contas suportadas**: escolhe **Apenas contas pessoais** (a última
   opção do menu; cobre Hotmail/Outlook.com/Live — o plugin usa o tenant
   `consumers` e rejeita tokens de qualquer outro tenant).
3. **URI de Redirecionamento**: plataforma **Web**, cola o valor da tab
   Microsoft na página de admin:
   `http://localhost:<porta>/wp-json/pixelhunter-google-login/v1/microsoft/callback`
   Clica em **Registar**.
4. Na **Descrição Geral** da aplicação: copia o **ID de aplicação (cliente)** —
   é o Client ID.
5. No menu lateral, **Certificados e segredos → Novo segredo de cliente**:
   escolhe a validade (recomendado 730 dias / 24 meses — quando expirar, o
   login Microsoft deixa de funcionar até criares outro) → **Adicionar** →
   copia a coluna **Valor** (não o "ID secreto"; só é mostrada uma vez) — é o
   Client Secret.

Para voltar a uma app já criada: [lista de registos de aplicações](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade)
(se parecer vazia, muda para a tab "Todas as aplicações").

## Configurar o site

1. No `wp-config.php`, define os secrets (nunca são guardados na base de dados
   quando a constante existe):
   ```php
   define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', 'GOCSPX-...' );
   define( 'PIXELHUNTER_MICROSOFT_LOGIN_CLIENT_SECRET', '...' );
   ```
2. Em **WooCommerce → Login social**: cola os Client IDs, confirma o estado
   "secret definido via wp-config" e ativa cada botão (**Ativar**).
3. Guarda. Os botões aparecem automaticamente no `[woocommerce_my_account]`
   (login e registo), pela ordem Google → Microsoft.

## Checklist de aceitação manual (após configurar credenciais reais)

Percorre as 4 ramificações da árvore de decisão no browser, **por provider**:

- **Email novo:** login com uma conta sem conta na loja → cria conta e fica
  autenticado.
- **Regresso:** logout, clica outra vez → login instantâneo, mesma conta.
- **Email já existente (confirm-link):** conta do provider cujo email já tem
  conta na loja → notice a pedir login com password; após login, o provider
  fica ligado (próximo clique é instantâneo).
- **Cruzado Google/Microsoft:** o mesmo email nos dois providers deve cair na
  MESMA conta WP — o primeiro liga, o segundo passa pelo confirm-link (os
  `sub` são guardados em metas distintas: `_pixelhunter_google_sub` /
  `_pixelhunter_microsoft_sub`).

## Notas de claims (porque é que a validação difere)

- **Google** emite `email_verified` — o plugin exige `true`.
- **Microsoft (MSA)** não emite `email_verified`; o email é o da própria conta
  Microsoft, e o issuer é validado contra o GUID fixo do tenant de contas
  pessoais (`9188040d-6c67-4c5b-b112-36a304b66dad`). Um `email_verified=false`
  explícito é sempre rejeitado, venha de onde vier.

## Testes

Lógica pura (sem WordPress):

```bash
php tests/test-token-claims.php
php tests/test-linking-decision.php
php tests/test-providers.php
```
