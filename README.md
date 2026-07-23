# PixelHunter Social Login

![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-ready-96588a?logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777bb3?logo=php&logoColor=white)
![Tests](https://img.shields.io/badge/tests-36%20asserts-brightgreen)

Login e registo com **Google** e **Microsoft** (contas pessoais: Hotmail, Outlook.com, Live) para lojas WooCommerce, via **OAuth 2.0 / OpenID Connect** — auto-contido, sem plugins de terceiros nem serviços intermediários. As credenciais dos clientes nunca passam pela loja: a autenticação acontece no Google/Microsoft e o plugin apenas valida criptograficamente o resultado.

## Funcionalidades

- **Dois providers, uma arquitetura** — todos os factos específicos de cada provider (endpoints, política de claims, branding) vivem num único registry (`includes/class-providers.php`); o resto do código é agnóstico. Acrescentar um terceiro provider é acrescentar uma entrada ao registry.
- **Criação automática de conta** — primeiro login cria um cliente WooCommerce (role `customer`) com password aleatória forte.
- **Linking seguro de contas existentes** — se o email já tem conta na loja, o plugin **não** entra diretamente: pede o login com password uma vez para provar a posse, e só então liga a identidade externa (previne account takeover por email).
- **O mesmo email nos dois providers cai na mesma conta WP** — as identidades ligadas guardam-se em metas distintas por provider.
- **Validação completa do `id_token`** — assinatura contra o JWKS do provider (com cache), `iss`, `aud`, `exp`, `nonce` e política de `email_verified` por provider.
- **Proteção CSRF** — `state` + `nonce` de uso único em transient com cookie `HttpOnly`/`SameSite=Lax`.
- **Secrets fora da base de dados (opcional, recomendado)** — constantes em `wp-config.php` têm prioridade e bloqueiam o campo no admin.
- **Admin organizado** — página em WooCommerce → Login social com tabs por provider, guia passo-a-passo com deep links para as consolas, Redirect URI pronto a copiar e estado ao vivo.
- **Botões acessíveis e responsivos** — lado a lado quando há largura, empilhados quando não há; rótulos curtos com `aria-label` completo; temas claro/escuro.
- **Sem dependências de runtime além de [`firebase/php-jwt`](https://github.com/firebase/php-jwt)** (vendorizada no repo — o plugin instala-se por cópia, sem `composer install`).

## Como funciona

```
Botão "Continuar com …"
  → GET /wp-json/pixelhunter-google-login/v1/{provider}/start
      gera state+nonce (transient de uso único + cookie HttpOnly)
      → redirect para o ecrã de autorização do provider
  → o cliente autentica-se NO provider (a loja nunca vê a password)
  → GET …/{provider}/callback?code=…&state=…
      valida state → troca o code por id_token → verifica assinatura (JWKS)
      → valida claims (iss/aud/exp/nonce/email)
      → resolve a conta:
```

| Situação | Ação |
|---|---|
| Identidade já ligada (`sub` conhecido) | Login imediato |
| Email novo | Cria cliente WooCommerce + liga identidade + login |
| Email já existe, sem identidade ligada | Pede login com password 1× e liga no sucesso (`confirm_link`) |
| Email não verificado no provider | Rejeita com mensagem ao cliente |

**Política de `email_verified`:** o Google emite a claim e o plugin exige `true`. As contas Microsoft pessoais (tenant `consumers`) não emitem a claim — o email é o da própria conta Microsoft — por isso a ausência é aceite *apenas* para a Microsoft, e o `iss` é validado contra o GUID fixo do tenant de contas pessoais (`9188040d-6c67-4c5b-b112-36a304b66dad`). Um `email_verified=false` explícito é sempre rejeitado, venha de onde vier.

## Requisitos

- WordPress ≥ 6.0 com WooCommerce
- PHP ≥ 8.0
- Credenciais OAuth próprias (gratuitas): Google Cloud Console e/ou portal Azure

## Instalação

1. Copia a pasta do plugin para `wp-content/plugins/` (o `vendor/` já vem incluído).
2. Ativa o plugin em Plugins.
3. Configura em **WooCommerce → Login social** — cada tab tem o guia passo-a-passo da respetiva consola e o Redirect URI pronto a copiar.

Os botões aparecem automaticamente nos formulários de login e registo do WooCommerce (`woocommerce_login_form_start` / `woocommerce_register_form_start`). O tema não precisa de alterações.

### Setup Google (resumo)

1. [Google Cloud Console](https://console.cloud.google.com/) → projeto → OAuth consent screen (nome + logo da app).
2. Credentials → Create OAuth client ID → tipo **Web application**.
3. Em **Authorized redirect URIs** cola o URI mostrado na tab Google do admin.
4. Copia o Client ID e o Client Secret para o admin (ou wp-config, ver abaixo).

### Setup Microsoft (resumo)

1. [Registar uma aplicação](https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/CreateApplicationBlade) no portal Azure.
2. Tipos de contas suportadas: **Apenas contas pessoais**.
3. URI de Redirecionamento: plataforma **Web** → cola o URI da tab Microsoft do admin.
4. Descrição Geral → copia o **ID de aplicação (cliente)**.
5. Certificados e segredos → **Novo segredo de cliente** (recomendado: 24 meses) → copia a coluna **Valor** (mostrada só uma vez). ⚠️ Os segredos Microsoft **expiram** — na data de expiração cria um novo e substitui no admin; o login Microsoft fica indisponível entre a expiração e a substituição, sem afetar contas nem os outros métodos de login.

### Secrets em `wp-config.php` (recomendado)

```php
define( 'PIXELHUNTER_GOOGLE_LOGIN_CLIENT_SECRET', '…' );
define( 'PIXELHUNTER_MICROSOFT_LOGIN_CLIENT_SECRET', '…' );
```

Quando a constante existe, o valor nunca é escrito na base de dados e o campo correspondente do admin fica bloqueado.

## Estrutura do código

| Ficheiro | Responsabilidade |
|---|---|
| `includes/class-providers.php` | Registry: todos os factos por provider (endpoints, claims, nomes, branding) |
| `includes/class-oauth.php` | Rotas REST `/start` e `/callback`, state/nonce, troca do code |
| `includes/class-token.php` | Verificação do `id_token` (JWKS + claims); `validate_claims()` é pura |
| `includes/class-accounts.php` | Árvore de decisão (pura) + lookup/criação/linking de contas |
| `includes/class-link.php` | Handoff seguro do confirm-link + notices ao cliente |
| `includes/class-button.php` | Botões nos formulários WooCommerce + CSS |
| `includes/class-admin.php` | Página de settings (tabs, Settings API, guias) |
| `includes/class-settings.php` | Acesso à config por provider; constantes > BD |

> **Nota histórica:** o slug/pasta mantém o nome original `pixelhunter-google-login` (o plugin nasceu Google-only); mudá-lo desativava o plugin nas instalações existentes. Pela mesma razão, os identificadores legados do Google (opção, constante, meta keys, rota `/callback`) são imutáveis — ver comentários no registry.

## Rotas REST

| Rota | Função |
|---|---|
| `GET /wp-json/pixelhunter-google-login/v1/start` | Início do fluxo Google (legado) |
| `GET /wp-json/pixelhunter-google-login/v1/callback` | Callback Google (é este o Redirect URI) |
| `GET /wp-json/pixelhunter-google-login/v1/microsoft/start` | Início do fluxo Microsoft |
| `GET /wp-json/pixelhunter-google-login/v1/microsoft/callback` | Callback Microsoft (Redirect URI) |

## Testes

A lógica pura (claims, árvore de decisão, registry) corre sem WordPress:

```bash
php tests/test-token-claims.php
php tests/test-linking-decision.php
php tests/test-providers.php
```

Checklist de aceitação manual (por provider): email novo → cria conta · regresso → login instantâneo · email existente → confirm-link com password · mesmo email nos dois providers → mesma conta WP.

## Licença

GPL-2.0-or-later, como derivado de WordPress.
