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
			__( 'Wompi — Nequi, Daviplata y PSE', 'wompi-wp-moshipp' ),
			__( 'Wompi', 'wompi-wp-moshipp' ),
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
			'pending_email'         => 'yes',
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
			wp_die( esc_html__( 'No tienes permisos para esto.', 'wompi-wp-moshipp' ) );
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
		$credentials['testmode']      = empty( $_POST['testmode'] ) ? 'no' : 'yes';
		$credentials['logging']       = empty( $_POST['logging'] ) ? 'no' : 'yes';
		$credentials['pending_email'] = empty( $_POST['pending_email'] ) ? 'no' : 'yes';

		update_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, $credentials, false );

		self::set_gateway_enabled( 'wompi_nequi', ! empty( $_POST['enable_nequi'] ) );
		self::set_gateway_enabled( 'wompi_daviplata', ! empty( $_POST['enable_daviplata'] ) );
		self::set_gateway_enabled( 'wompi_pse', ! empty( $_POST['enable_pse'] ) );

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

	/**
	 * Cada llave de Wompi tiene un prefijo único. Detecta llaves pegadas en el
	 * campo equivocado (causa típica del error "La firma es inválida").
	 *
	 * @return string[] Mensajes de problema, vacío si todo cuadra.
	 */
	private static function key_format_issues( array $values ): array {
		$expected = array(
			'test_public_key'       => array( 'pub_test_', __( 'Llave pública de prueba', 'wompi-wp-moshipp' ) ),
			'test_private_key'      => array( 'prv_test_', __( 'Llave privada de prueba', 'wompi-wp-moshipp' ) ),
			'test_integrity_secret' => array( 'test_integrity_', __( 'Secreto de integridad de prueba', 'wompi-wp-moshipp' ) ),
			'test_events_secret'    => array( 'test_events_', __( 'Secreto de eventos de prueba', 'wompi-wp-moshipp' ) ),
			'prod_public_key'       => array( 'pub_prod_', __( 'Llave pública de producción', 'wompi-wp-moshipp' ) ),
			'prod_private_key'      => array( 'prv_prod_', __( 'Llave privada de producción', 'wompi-wp-moshipp' ) ),
			'prod_integrity_secret' => array( 'prod_integrity_', __( 'Secreto de integridad de producción', 'wompi-wp-moshipp' ) ),
			'prod_events_secret'    => array( 'prod_events_', __( 'Secreto de eventos de producción', 'wompi-wp-moshipp' ) ),
		);

		$known_prefixes = array(
			'pub_test_'       => __( 'una llave pública de prueba', 'wompi-wp-moshipp' ),
			'prv_test_'       => __( 'una llave privada de prueba', 'wompi-wp-moshipp' ),
			'test_integrity_' => __( 'un secreto de integridad de prueba', 'wompi-wp-moshipp' ),
			'test_events_'    => __( 'un secreto de eventos de prueba', 'wompi-wp-moshipp' ),
			'pub_prod_'       => __( 'una llave pública de producción', 'wompi-wp-moshipp' ),
			'prv_prod_'       => __( 'una llave privada de producción', 'wompi-wp-moshipp' ),
			'prod_integrity_' => __( 'un secreto de integridad de producción', 'wompi-wp-moshipp' ),
			'prod_events_'    => __( 'un secreto de eventos de producción', 'wompi-wp-moshipp' ),
		);

		$issues = array();
		foreach ( $expected as $field => list( $prefix, $label ) ) {
			$value = trim( (string) ( $values[ $field ] ?? '' ) );
			if ( '' === $value || 0 === strpos( $value, $prefix ) ) {
				continue;
			}
			$looks_like = '';
			foreach ( $known_prefixes as $known => $description ) {
				if ( 0 === strpos( $value, $known ) ) {
					$looks_like = $description;
					break;
				}
			}
			if ( $looks_like ) {
				$issues[] = sprintf(
					/* translators: 1: campo, 2: prefijo esperado, 3: qué parece ser. */
					__( '%1$s: se esperaba una llave que empiece por "%2$s", pero lo pegado parece ser %3$s.', 'wompi-wp-moshipp' ),
					$label,
					$prefix,
					$looks_like
				);
			} else {
				$issues[] = sprintf(
					/* translators: 1: campo, 2: prefijo esperado. */
					__( '%1$s: debe empezar por "%2$s". Revisa que no tenga espacios ni esté incompleta.', 'wompi-wp-moshipp' ),
					$label,
					$prefix
				);
			}
		}
		return $issues;
	}

	public static function render(): void {
		$values      = self::credentials();
		$is_test     = 'yes' === $values['testmode'];
		$webhook_url = Wompi_MP_Webhook::url();
		$key_issues  = self::key_format_issues( $values );
		?>
		<div class="wrap">
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuración de Wompi guardada.', 'wompi-wp-moshipp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $key_issues ) : ?>
				<div class="wompi-mp-key-alert">
					<p><strong><?php esc_html_e( '⚠ Hay llaves de Wompi en el campo equivocado — los pagos fallarán con "La firma es inválida" hasta corregirlas:', 'wompi-wp-moshipp' ); ?></strong></p>
					<ul>
						<?php foreach ( $key_issues as $issue ) : ?>
							<li><?php echo esc_html( $issue ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p><?php esc_html_e( 'Copia cada llave desde el dashboard de Wompi (Mi cuenta → Llaves del API): cada una tiene un prefijo distinto que indica a qué campo pertenece.', 'wompi-wp-moshipp' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wompi-mp-admin-hero">
				<div>
					<h2><?php esc_html_e( 'Wompi — Nequi, Daviplata y PSE', 'wompi-wp-moshipp' ); ?></h2>
					<p class="wompi-mp-hero-desc"><?php esc_html_e( 'Configura aquí las credenciales una sola vez: aplican para todos los métodos de pago.', 'wompi-wp-moshipp' ); ?></p>
					<?php echo function_exists( 'wompi_mp_brand_html' ) ? wp_kses_post( wompi_mp_brand_html() ) : ''; ?>
				</div>
				<div class="wompi-mp-hero-meta">
					<span class="wompi-mp-badges">
						<span class="wompi-mp-badge"><?php echo esc_html( 'v' . WOMPI_MP_VERSION ); ?></span>
						<?php if ( $is_test ) : ?>
							<span class="wompi-mp-badge wompi-mp-badge-test"><?php esc_html_e( 'Modo prueba', 'wompi-wp-moshipp' ); ?></span>
						<?php else : ?>
							<span class="wompi-mp-badge wompi-mp-badge-prod"><?php esc_html_e( 'Producción', 'wompi-wp-moshipp' ); ?></span>
						<?php endif; ?>
					</span>
					<button type="button" class="button" id="wompi-mp-check"><?php esc_html_e( 'Verificar conexión con Wompi', 'wompi-wp-moshipp' ); ?></button>
					<span class="wompi-mp-check-result" id="wompi-mp-check-result"></span>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wompi-mp-admin-body">
				<input type="hidden" name="action" value="wompi_mp_save_settings" />
				<?php wp_nonce_field( 'wompi_mp_settings' ); ?>

				<h3><?php esc_html_e( 'Métodos de pago', 'wompi-wp-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'Actívalos aquí; el título y la descripción que ve el cliente se personalizan en la página de cada método.', 'wompi-wp-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::checkbox_field( 'enable_nequi', __( 'Nequi', 'wompi-wp-moshipp' ), __( 'Aceptar pagos con Nequi (notificación push)', 'wompi-wp-moshipp' ), self::gateway_enabled( 'wompi_nequi' ) );
					self::checkbox_field( 'enable_daviplata', __( 'Daviplata', 'wompi-wp-moshipp' ), __( 'Aceptar pagos con Daviplata (código OTP)', 'wompi-wp-moshipp' ), self::gateway_enabled( 'wompi_daviplata' ) );
					self::checkbox_field( 'enable_pse', __( 'PSE', 'wompi-wp-moshipp' ), __( 'Aceptar pagos con PSE (débito bancario)', 'wompi-wp-moshipp' ), self::gateway_enabled( 'wompi_pse' ) );
					self::checkbox_field( 'testmode', __( 'Modo de prueba', 'wompi-wp-moshipp' ), __( 'Usar el ambiente sandbox de Wompi (transacciones simuladas)', 'wompi-wp-moshipp' ), $is_test );
					?>
				</table>

				<h3><?php esc_html_e( 'Credenciales de prueba (sandbox)', 'wompi-wp-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'Las encuentras en comercios.wompi.co, ambiente de pruebas.', 'wompi-wp-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::text_field( 'test_public_key', __( 'Llave pública', 'wompi-wp-moshipp' ), 'pub_test_…', $values );
					self::text_field( 'test_private_key', __( 'Llave privada', 'wompi-wp-moshipp' ), 'prv_test_…', $values, 'password' );
					self::text_field( 'test_integrity_secret', __( 'Secreto de integridad', 'wompi-wp-moshipp' ), 'test_integrity_…', $values, 'password' );
					self::text_field( 'test_events_secret', __( 'Secreto de eventos', 'wompi-wp-moshipp' ), 'test_events_…', $values, 'password' );
					?>
				</table>

				<h3><?php esc_html_e( 'Credenciales de producción', 'wompi-wp-moshipp' ); ?></h3>
				<table class="form-table">
					<?php
					self::text_field( 'prod_public_key', __( 'Llave pública', 'wompi-wp-moshipp' ), 'pub_prod_…', $values );
					self::text_field( 'prod_private_key', __( 'Llave privada', 'wompi-wp-moshipp' ), 'prv_prod_…', $values, 'password' );
					self::text_field( 'prod_integrity_secret', __( 'Secreto de integridad', 'wompi-wp-moshipp' ), 'prod_integrity_…', $values, 'password' );
					self::text_field( 'prod_events_secret', __( 'Secreto de eventos', 'wompi-wp-moshipp' ), 'prod_events_…', $values, 'password' );
					?>
				</table>

				<h3><?php esc_html_e( 'Comisiones de Wompi (estimación en las órdenes)', 'wompi-wp-moshipp' ); ?></h3>
				<p><?php esc_html_e( 'El API de Wompi no reporta la comisión cobrada; con tu tarifa el plugin muestra en cada orden una comisión y un neto estimados. Vacío = no mostrar.', 'wompi-wp-moshipp' ); ?></p>
				<table class="form-table">
					<?php
					self::text_field( 'fee_percent', __( 'Comisión variable (%)', 'wompi-wp-moshipp' ), '2.65', $values );
					self::text_field( 'fee_fixed', __( 'Comisión fija (COP)', 'wompi-wp-moshipp' ), '700', $values );
					self::text_field( 'fee_iva', __( 'IVA sobre la comisión (%)', 'wompi-wp-moshipp' ), '19', $values );
					?>
				</table>

				<h3><?php esc_html_e( 'Webhook y depuración', 'wompi-wp-moshipp' ); ?></h3>
				<table class="form-table">
					<tr valign="top">
						<th scope="row" class="titledesc"><?php esc_html_e( 'URL de eventos (webhook)', 'wompi-wp-moshipp' ); ?></th>
						<td class="forminp">
							<div class="wompi-mp-webhook-box">
								<code><?php echo esc_html( $webhook_url ); ?></code>
								<button type="button" class="button" data-wompi-copy="<?php echo esc_attr( $webhook_url ); ?>"><?php esc_html_e( 'Copiar', 'wompi-wp-moshipp' ); ?></button>
								<span class="wompi-mp-copy-done" style="display:none"><?php esc_html_e( '¡Copiada!', 'wompi-wp-moshipp' ); ?></span>
							</div>
							<p class="description"><?php esc_html_e( 'Regístrala como "URL de eventos" en el dashboard de Wompi, una vez por ambiente (pruebas y producción).', 'wompi-wp-moshipp' ); ?></p>
						</td>
					</tr>
					<?php
					self::checkbox_field( 'pending_email', __( 'Email de instrucciones', 'wompi-wp-moshipp' ), __( 'Enviar al cliente un email con instrucciones cuando el pago quede pendiente de su acción', 'wompi-wp-moshipp' ), 'yes' === ( $values['pending_email'] ?? 'yes' ) );
					self::checkbox_field( 'logging', __( 'Registro de depuración', 'wompi-wp-moshipp' ), __( 'Guardar log de llamadas al API (WooCommerce → Estado → Logs, fuente wompi-wp-moshipp)', 'wompi-wp-moshipp' ), 'yes' === $values['logging'] );
					?>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Guardar configuración', 'wompi-wp-moshipp' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
