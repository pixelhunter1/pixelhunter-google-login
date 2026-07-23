<?php
/**
 * Página de settings em WooCommerce, organizada por tabs (uma por provider +
 * Aparência). Cada tab tem o seu próprio option group da Settings API — o
 * options.php atualiza TODAS as opções de um grupo em cada submit, portanto
 * grupos separados são o que impede uma tab de apagar a config das outras.
 * Layout em grid: formulário à esquerda, guia de setup + estado à direita.
 *
 * @package PixelHunter_Google_Login
 */

defined( 'ABSPATH' ) || exit;

class PixelHunter_Login_Admin {

	const SLUG = 'pixelhunter-social-login';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		foreach ( $this->tab_slugs() as $tab ) {
			add_filter(
				'option_page_capability_' . $this->group( $tab ),
				function () {
					return 'manage_woocommerce';
				}
			);
		}
	}

	/** @return string[] Slugs das tabs, pela ordem de apresentação. */
	protected function tab_slugs(): array {
		return array_merge( PixelHunter_Login_Providers::slugs(), array( 'appearance' ) );
	}

	/** Option group da Settings API para uma tab. */
	protected function group( string $tab ): string {
		return self::SLUG . '-' . $tab;
	}

	/** Slug de página da Settings API (sections/fields) para uma tab. */
	protected function page( string $tab ): string {
		return self::SLUG . '-' . $tab;
	}

	/** Tab ativa, validada contra as conhecidas. */
	protected function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navegação, só leitura.
		return in_array( $tab, $this->tab_slugs(), true ) ? $tab : 'google';
	}

	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Social Login', 'pixelhunter-google-login' ),
			__( 'Social Login', 'pixelhunter-google-login' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'pixelhunter-login-admin',
			PIXELHUNTER_LOGIN_URL . 'assets/admin.css',
			array(),
			PIXELHUNTER_LOGIN_VERSION
		);
	}

	public function settings(): void {
		foreach ( PixelHunter_Login_Providers::all() as $provider ) {
			$tab  = $provider['slug'];
			$page = $this->page( $tab );

			register_setting(
				$this->group( $tab ),
				$provider['option'],
				array(
					'sanitize_callback' => function ( $input ) use ( $provider ) {
						return $this->sanitize_provider( $input, $provider );
					},
				)
			);

			add_settings_section(
				'credentials',
				__( 'Credentials', 'pixelhunter-google-login' ),
				'__return_null',
				$page
			);
			add_settings_field(
				'enabled',
				__( 'Enable', 'pixelhunter-google-login' ),
				array( $this, 'field_enabled' ),
				$page,
				'credentials',
				array( 'provider' => $provider )
			);
			add_settings_field(
				'client_id',
				__( 'Client ID', 'pixelhunter-google-login' ),
				array( $this, 'field_client_id' ),
				$page,
				'credentials',
				array( 'provider' => $provider )
			);
			add_settings_field(
				'client_secret',
				__( 'Client Secret', 'pixelhunter-google-login' ),
				array( $this, 'field_client_secret' ),
				$page,
				'credentials',
				array( 'provider' => $provider )
			);
		}

		register_setting(
			$this->group( 'appearance' ),
			PixelHunter_Login_Settings::APPEARANCE_OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_appearance' ) )
		);
		add_settings_section(
			'appearance',
			__( 'Buttons', 'pixelhunter-google-login' ),
			'__return_null',
			$this->page( 'appearance' )
		);
		add_settings_field(
			'button_theme',
			__( 'Button theme', 'pixelhunter-google-login' ),
			array( $this, 'field_button_theme' ),
			$this->page( 'appearance' ),
			'appearance'
		);
	}

	public function sanitize_provider( $input, array $provider ): array {
		$existing = PixelHunter_Login_Settings::get( $provider );
		if ( ! is_array( $input ) ) {
			return $existing;
		}
		$out = array(
			'enabled'   => ! empty( $input['enabled'] ),
			'client_id' => sanitize_text_field( $input['client_id'] ?? '' ),
		);
		// Nunca persistir o secret na BD quando a constante do wp-config o fornece.
		if ( PixelHunter_Login_Settings::secret_from_constant( $provider ) ) {
			$out['client_secret'] = '';
		} else {
			$submitted = sanitize_text_field( $input['client_secret'] ?? '' );
			// Campo em branco significa "manter o secret existente" (nunca é pré-preenchido).
			$out['client_secret'] = '' !== $submitted ? $submitted : (string) $existing['client_secret'];
		}
		// A opção do Google pode ainda ter o button_theme legado; preservá-lo
		// mantém o fallback de Settings::button_theme() a funcionar.
		if ( isset( $existing['button_theme'] ) ) {
			$out['button_theme'] = $existing['button_theme'];
		}
		return $out;
	}

	public function sanitize_appearance( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'button_theme' => ( 'dark' === ( $input['button_theme'] ?? 'light' ) ) ? 'dark' : 'light',
		);
	}

	public function field_enabled( array $args ): void {
		$provider = $args['provider'];
		$s        = PixelHunter_Login_Settings::get( $provider );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $provider['option'] ); ?>[enabled]" value="1" <?php checked( (bool) $s['enabled'] ); ?> />
			<?php
			/* translators: %s: button label (e.g. Continue with Google). */
			printf( esc_html__( 'Show the “%s” button', 'pixelhunter-google-login' ), esc_html( $provider['button_label'] ) );
			?>
		</label>
		<?php
	}

	public function field_client_id( array $args ): void {
		$provider = $args['provider'];
		$s        = PixelHunter_Login_Settings::get( $provider );
		printf(
			'<input type="text" id="phl_%1$s_client_id" class="regular-text code" name="%2$s[client_id]" value="%3$s" />',
			esc_attr( $provider['slug'] ),
			esc_attr( $provider['option'] ),
			esc_attr( $s['client_id'] )
		);
	}

	public function field_client_secret( array $args ): void {
		$provider = $args['provider'];
		if ( PixelHunter_Login_Settings::secret_from_constant( $provider ) ) {
			?>
			<input type="text" class="regular-text" value="🔒 <?php esc_attr_e( 'Set in wp-config.php', 'pixelhunter-google-login' ); ?>" disabled />
			<p class="description">
				<?php
				/* translators: %s: PHP constant name. */
				printf( esc_html__( 'The %s constant takes precedence; this field is locked.', 'pixelhunter-google-login' ), '<code>' . esc_html( $provider['secret_constant'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup construído acima, tudo escapado.
				?>
			</p>
			<?php
			return;
		}
		$db_secret_exists = '' !== (string) PixelHunter_Login_Settings::get( $provider )['client_secret'];
		?>
		<input type="password" id="phl_<?php echo esc_attr( $provider['slug'] ); ?>_client_secret" class="regular-text" name="<?php echo esc_attr( $provider['option'] ); ?>[client_secret]" value="" placeholder="<?php echo esc_attr( $db_secret_exists ? __( '•••••• (saved — leave blank to keep)', 'pixelhunter-google-login' ) : '' ); ?>" autocomplete="off" />
		<p class="description">
			<?php
			/* translators: %s: PHP constant name. */
			printf( esc_html__( 'Recommended: define %s in wp-config.php so the secret is never stored in the database.', 'pixelhunter-google-login' ), '<code>' . esc_html( $provider['secret_constant'] ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup construído acima, tudo escapado.
			?>
		</p>
		<?php
	}

	public function field_button_theme(): void {
		$theme  = PixelHunter_Login_Settings::button_theme();
		$option = PixelHunter_Login_Settings::APPEARANCE_OPTION;
		?>
		<select id="phl_theme" name="<?php echo esc_attr( $option ); ?>[button_theme]">
			<option value="light" <?php selected( 'light', $theme ); ?>><?php esc_html_e( 'Light', 'pixelhunter-google-login' ); ?></option>
			<option value="dark" <?php selected( 'dark', $theme ); ?>><?php esc_html_e( 'Dark', 'pixelhunter-google-login' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Applies to every “Continue with …” button.', 'pixelhunter-google-login' ); ?></p>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$current = $this->current_tab();
		?>
		<div class="wrap phl-wrap">
			<h1><?php esc_html_e( 'Social Login', 'pixelhunter-google-login' ); ?></h1>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'pixelhunter-google-login' ); ?>">
				<?php foreach ( $this->tab_slugs() as $tab ) : ?>
					<?php
					$provider = PixelHunter_Login_Providers::get( $tab );
					$label    = $provider ? $provider['label'] : __( 'Appearance', 'pixelhunter-google-login' );
					$url      = add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $tab === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php settings_errors(); ?>

			<?php
			$provider = PixelHunter_Login_Providers::get( $current );
			if ( $provider ) {
				$this->render_provider_tab( $provider );
			} else {
				$this->render_appearance_tab();
			}
			?>
		</div>
		<?php
	}

	protected function render_provider_tab( array $provider ): void {
		?>
		<div class="phl-grid">
			<div class="phl-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->group( $provider['slug'] ) );
					do_settings_sections( $this->page( $provider['slug'] ) );
					submit_button();
					?>
				</form>
			</div>

			<div class="phl-card phl-card--aside">
				<h2><?php esc_html_e( 'Status', 'pixelhunter-google-login' ); ?></h2>
				<?php $this->render_status( $provider ); ?>

				<h2><?php esc_html_e( 'Redirect URI', 'pixelhunter-google-login' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste this value in the provider console (click to select):', 'pixelhunter-google-login' ); ?></p>
				<input type="text" class="phl-uri code" readonly onclick="this.select()" value="<?php echo esc_attr( PixelHunter_Login_Settings::redirect_uri( $provider ) ); ?>" />

				<h2><?php esc_html_e( 'Setup guide', 'pixelhunter-google-login' ); ?></h2>
				<?php $this->render_guide( $provider['slug'] ); ?>
			</div>
		</div>
		<?php
	}

	protected function render_appearance_tab(): void {
		?>
		<div class="phl-grid phl-grid--single">
			<div class="phl-card">
				<form method="post" action="options.php">
					<?php
					settings_fields( $this->group( 'appearance' ) );
					do_settings_sections( $this->page( 'appearance' ) );
					submit_button();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	protected function render_status( array $provider ): void {
		$s          = PixelHunter_Login_Settings::get( $provider );
		$from_const = PixelHunter_Login_Settings::secret_from_constant( $provider );
		$has_secret = '' !== PixelHunter_Login_Settings::client_secret( $provider );
		$row        = static function ( bool $ok, string $label ): void {
			printf(
				'<li class="phl-status__item phl-status__item--%s"><span class="dashicons %s" aria-hidden="true"></span> %s</li>',
				$ok ? 'ok' : 'off',
				$ok ? 'dashicons-yes-alt' : 'dashicons-dismiss',
				esc_html( $label )
			);
		};
		echo '<ul class="phl-status">';
		$row( (bool) $s['enabled'], __( 'Enabled', 'pixelhunter-google-login' ) );
		$row( '' !== (string) $s['client_id'], __( 'Client ID set', 'pixelhunter-google-login' ) );
		$row( $has_secret, $from_const ? __( 'Client Secret set (via wp-config)', 'pixelhunter-google-login' ) : __( 'Client Secret set', 'pixelhunter-google-login' ) );
		echo '</ul>';
	}

	/** Passos de setup na consola de cada provider, com deep links. */
	protected function render_guide( string $slug ): void {
		if ( 'google' === $slug ) {
			?>
			<p><?php esc_html_e( 'In the Google Cloud Console, under the “Google Auth Platform” menu, follow these steps in order:', 'pixelhunter-google-login' ); ?></p>
			<ol>
				<li><a href="<?php echo esc_url( 'https://console.cloud.google.com/projectcreate' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create / choose a project', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="<?php echo esc_url( 'https://console.cloud.google.com/auth/branding' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Branding — set the app name and logo (what the user sees on the Google screen)', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="<?php echo esc_url( 'https://console.cloud.google.com/auth/audience' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Audience → Test users → add your own email (required for testing before publishing)', 'pixelhunter-google-login' ); ?></a></li>
				<li><a href="<?php echo esc_url( 'https://console.cloud.google.com/auth/clients' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Clients → Create client → type “Web application” → paste the Redirect URI (above)', 'pixelhunter-google-login' ); ?></a></li>
			</ol>
			<p><?php esc_html_e( 'When the client is created (step 4), Google shows the Client ID and the Client Secret — paste them into the form on the left.', 'pixelhunter-google-login' ); ?></p>
			<?php
			return;
		}
		?>
		<p><?php esc_html_e( 'In the Azure portal (Microsoft Entra ID), follow these steps in order:', 'pixelhunter-google-login' ); ?></p>
		<ol>
			<li>
				<a href="<?php echo esc_url( 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/CreateApplicationBlade' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Register an application (opens the registration form directly)', 'pixelhunter-google-login' ); ?></a>
				— <?php esc_html_e( 'under “Name”, enter your store name: it is what customers see on the Microsoft consent screen.', 'pixelhunter-google-login' ); ?>
			</li>
			<li><?php esc_html_e( 'Under “Supported account types” choose “Personal Microsoft accounts only” (the last option in the list) — that is what covers Hotmail, Outlook.com and Live.', 'pixelhunter-google-login' ); ?></li>
			<li><?php esc_html_e( 'Under “Redirect URI” select the “Web” platform and paste the Redirect URI (above). Click “Register”.', 'pixelhunter-google-login' ); ?></li>
			<li><?php esc_html_e( 'You land on the app’s “Overview”: copy the “Application (client) ID” and paste it into the Client ID field on the left.', 'pixelhunter-google-login' ); ?></li>
			<li><?php esc_html_e( 'In the app’s sidebar open “Certificates & secrets” → “New client secret” → choose the expiry (recommended: 730 days / 24 months, otherwise sign-in stops working when it expires) → “Add”.', 'pixelhunter-google-login' ); ?></li>
			<li><?php esc_html_e( 'Copy the secret’s “Value” column (careful: NOT the “Secret ID”) — it is shown only once. That is the Client Secret; ideally define it in wp-config.php (constant shown below the field).', 'pixelhunter-google-login' ); ?></li>
		</ol>
		<p>
			<?php esc_html_e( 'To come back later to an app you already created:', 'pixelhunter-google-login' ); ?>
			<a href="<?php echo esc_url( 'https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'app registrations list', 'pixelhunter-google-login' ); ?></a>
			<?php esc_html_e( '(if the list looks empty, switch to the “All applications” tab).', 'pixelhunter-google-login' ); ?>
		</p>
		<?php
	}
}
