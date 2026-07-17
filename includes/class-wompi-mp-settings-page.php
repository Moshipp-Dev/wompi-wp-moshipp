<?php
/**
 * Página central de configuración: WooCommerce → Wompi.
 * Credenciales, comisiones, webhook y activación de ambos métodos en un solo lugar.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Settings_Page {

	const SLUG = 'wompi-mp-settings';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_wompi_mp_save_settings', array( __CLASS__, 'save' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Wompi — Nequi y Daviplata', 'wompi-moshipp' ),
			__( 'Wompi', 'wompi-moshipp' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function credentials(): array {
		$defaults = array(
			'testmode'              => 'yes',
			'test_public_key'       => '',
			'test_private_key'      => '',
			'test_integrity_secret' => '',
			'test_events_secret'    => '',
			'prod_public_key'       => '',
			'prod_private_key'      => '',
			'prod_integrity_secret' => '',
			'prod_events_secret'    => '',
			'logging'               => 'no',
			'fee_percent'           => '2.65',
			'fee_fixed'             => '700',
			'fee_iva'               => '19',
		);
		$stored = get_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	private static function gateway_enabled( string $gateway_id ): bool {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
		return is_array( $settings ) && 'yes' === ( $settings['enabled'] ?? 'no' );
	}

	private static function set_gateway_enabled( string $gateway_id, bool $enabled ): void {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = $enabled ? 'yes' : 'no';
		update_option( 'woocommerce_' . $gateway_id . '_settings', $settings );
	}

	public static function save(): void {
		check_admin_referer( 'wompi_mp_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tienes permisos para esto.', 'wompi-moshipp' ) );
		}

		$credentials = self::credentials();
		$text_fields = array(
			'test_public_key',
			'test_private_key',
			'test_integrity_secret',
			'test_events_secret',
			'prod_public_key',
			'prod_private_key',
			'prod_integrity_secret',
			'prod_events_secret',
			'fee_percent',
			'fee_fixed',
			'fee_iva',
		);
		foreach ( $text_fields as $field ) {
			$credentials[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
		}
		$credentials['testmode'] = empty( $_POST['testmode'] ) ? 'no' : 'yes';
		$credentials['logging']  = empty( $_POST['logging'] ) ? 'no' : 'yes';

		update_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, $credentials, false );

		self::set_gateway_enabled( 'wompi_nequi', ! empty( $_POST['enable_nequi'] ) );
		self::set_gateway_enabled( 'wompi_daviplata', ! empty( $_POST['enable_daviplata'] ) );

		wp_safe_redirect( add_query_arg( 'updated', '1', self::url() ) );
		exit;
	}

	private static function text_field( string $key, string $label, string $placeholder, array $values, string $type = 'text', string $description = '' ): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td class="forminp">
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="off"
				/>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function checkbox_field( string $key, string $label, string $text, bool $checked ): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $label ); ?></th>
			<td class="forminp">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked ); ?> />
					<?php echo esc_html( $text ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	public static function render(): void {
		$values      = self::credentials();
		$is_test     = 'yes' === $values['testmode'];
		$webhook_url = Wompi_MP_Webhook::url();
		?>
		<div class="wrap">
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de Wompi guardada.', 'wompi-moshipp' ); ?></p></div>
			<?php endif; ?>

			<div class="wompi-mp-admin-hero">
				<div>
					<h2><?php esc_html_e( 'Wompi — Nequi y Daviplata', 'wompi-moshipp' ); ?></h2>
					<p class="wompi-mp-hero-desc"><?php esc_html_e( 'Configura aquí las credenciales una sola vez: aplican para ambos métodos de pago.', 'wompi-moshipp' ); ?></p>
					<?php echo function_exists( 'wompi_mp_brand_html' ) ? wp_kses_post( wompi_mp_brand_html() ) : ''; ?>
				</div>
				<div class="wompi-mp-hero-meta">
					<span class="wompi-mp-badges">
						<span class="wompi-mp-badge"><?php echo esc_html( 'v' . WOMPI_MP_VERSION ); ?></span>
						<?php if ( $is_test ) : ?>
							<span class="wompi-mp-badge wompi-mp-badge-test"><?php esc_html_e( 'Modo prueba', 'wompi-moshipp' ); ?></span>
						<?php else : ?>
							<span class="wompi-mp-badge wompi-mp-badge-prod"><?php esc_html_e( 'Producción', 'wompi-moshipp' ); ?></span>
						<?php endif; ?>
					</span>
					<button type="button" class="button" id="wompi-mp-check"><?php esc_html_e( 'Verificar conexión con Wompi', 'wompi-moshipp' ); ?></button>
					<span class="wompi-mp-check-result" id="wompi-mp-check-result"></span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wompi-mp-admin-body">
				<input type="hidden" name="action" value="wompi_mp_save_settings" />
				<?php wp_nonce_field( 'wompi_mp_settings' ); ?>

				<h3><?php esc_html_e( 'Métodos de pago', 'wompi-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'Actívalos aquí; el título y la descripción que ve el cliente se personalizan en la página de cada método.', 'wompi-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::checkbox_field( 'enable_nequi', __( 'Nequi', 'wompi-moshipp' ), __( 'Aceptar pagos con Nequi (notificación push)', 'wompi-moshipp' ), self::gateway_enabled( 'wompi_nequi' ) );
					self::checkbox_field( 'enable_daviplata', __( 'Daviplata', 'wompi-moshipp' ), __( 'Aceptar pagos con Daviplata (código OTP)', 'wompi-moshipp' ), self::gateway_enabled( 'wompi_daviplata' ) );
					self::checkbox_field( 'testmode', __( 'Modo de prueba', 'wompi-moshipp' ), __( 'Usar el ambiente sandbox de Wompi (transacciones simuladas)', 'wompi-moshipp' ), $is_test );
					?>
				</table>

				<h3><?php esc_html_e( 'Credenciales de prueba (sandbox)', 'wompi-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'Las encuentras en comercios.wompi.co, ambiente de pruebas.', 'wompi-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::text_field( 'test_public_key', __( 'Llave pública', 'wompi-moshipp' ), 'pub_test_…', $values );
					self::text_field( 'test_private_key', __( 'Llave privada', 'wompi-moshipp' ), 'prv_test_…', $values, 'password' );
					self::text_field( 'test_integrity_secret', __( 'Secreto de integridad', 'wompi-moshipp' ), 'test_integrity_…', $values, 'password' );
					self::text_field( 'test_events_secret', __( 'Secreto de eventos', 'wompi-moshipp' ), 'test_events_…', $values, 'password' );
					?>
				</table>

				<h3><?php esc_html_e( 'Credenciales de producción', 'wompi-moshipp' ); ?></h3>
				<table class="form-table">
					<?php
					self::text_field( 'prod_public_key', __( 'Llave pública', 'wompi-moshipp' ), 'pub_prod_…', $values );
					self::text_field( 'prod_private_key', __( 'Llave privada', 'wompi-moshipp' ), 'prv_prod_…', $values, 'password' );
					self::text_field( 'prod_integrity_secret', __( 'Secreto de integridad', 'wompi-moshipp' ), 'prod_integrity_…', $values, 'password' );
					self::text_field( 'prod_events_secret', __( 'Secreto de eventos', 'wompi-moshipp' ), 'prod_events_…', $values, 'password' );
					?>
				</table>

				<h3><?php esc_html_e( 'Comisiones de Wompi (estimación en las órdenes)', 'wompi-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'El API de Wompi no reporta la comisión cobrada; con tu tarifa el plugin muestra en cada orden una comisión y un neto estimados. Vacío = no mostrar.', 'wompi-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::text_field( 'fee_percent', __( 'Comisión variable (%)', 'wompi-moshipp' ), '2.65', $values );
					self::text_field( 'fee_fixed', __( 'Comisión fija (COP)', 'wompi-moshipp' ), '700', $values );
					self::text_field( 'fee_iva', __( 'IVA sobre la comisión (%)', 'wompi-moshipp' ), '19', $values );
					?>
				</table>

				<h3><?php esc_html_e( 'Webhook y depuración', 'wompi-moshipp' ); ?></h3>
				<table class="form-table">
					<tr valign="top">
						<th scope="row" class="titledesc"><?php esc_html_e( 'URL de eventos (webhook)', 'wompi-moshipp' ); ?></th>
						<td class="forminp">
							<div class="wompi-mp-webhook-box">
								<code><?php echo esc_html( $webhook_url ); ?></code>
								<button type="button" class="button" data-wompi-copy="<?php echo esc_attr( $webhook_url ); ?>"><?php esc_html_e( 'Copiar', 'wompi-moshipp' ); ?></button>
								<span class="wompi-mp-copy-done" style="display:none"><?php esc_html_e( '¡Copiada!', 'wompi-moshipp' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'Regístrala como "URL de eventos" en el dashboard de Wompi, una vez por ambiente (pruebas y producción).', 'wompi-moshipp' ); ?></p>
						</td>
					</tr>
					<?php self::checkbox_field( 'logging', __( 'Registro de depuración', 'wompi-moshipp' ), __( 'Guardar log de llamadas al API (WooCommerce → Estado → Logs, fuente wompi-moshipp)', 'wompi-moshipp' ), 'yes' === $values['logging'] ); ?>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Guardar configuración', 'wompi-moshipp' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
