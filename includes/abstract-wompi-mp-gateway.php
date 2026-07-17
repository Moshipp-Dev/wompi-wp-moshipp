<?php
/**
 * Base de los gateways Wompi. Credenciales compartidas entre Nequi y Daviplata:
 * se editan desde cualquiera de las dos pantallas y se guardan en una sola opción.
 */

defined( 'ABSPATH' ) || exit;

abstract class Wompi_MP_Gateway extends WC_Payment_Gateway {

	const CREDENTIALS_OPTION = 'wompi_mp_credentials';

	/** Campos que se comparten entre ambos gateways. */
	const SHARED_FIELDS = array(
		'testmode',
		'test_public_key',
		'test_private_key',
		'test_integrity_secret',
		'test_events_secret',
		'prod_public_key',
		'prod_private_key',
		'prod_integrity_secret',
		'prod_events_secret',
		'logging',
		'fee_percent',
		'fee_fixed',
		'fee_iva',
	);

	protected ?Wompi_MP_API_Client $api_client = null;

	public function __construct() {
		$this->has_fields = true;
		$this->supports   = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->load_shared_credentials();

		$this->enabled     = $this->get_option( 'enabled', 'no' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_thankyou_status' ) );
	}

	/**
	 * Sobrescribe los campos compartidos con la opción común, para que ambos
	 * gateways siempre muestren y usen las mismas credenciales.
	 */
	protected function load_shared_credentials(): void {
		$shared = get_option( self::CREDENTIALS_OPTION, array() );
		if ( ! is_array( $shared ) ) {
			return;
		}
		foreach ( self::SHARED_FIELDS as $field ) {
			if ( array_key_exists( $field, $shared ) ) {
				$this->settings[ $field ] = $shared[ $field ];
			}
		}
	}

	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$shared = get_option( self::CREDENTIALS_OPTION, array() );
		$shared = is_array( $shared ) ? $shared : array();
		foreach ( self::SHARED_FIELDS as $field ) {
			$shared[ $field ] = $this->get_option( $field );
		}
		update_option( self::CREDENTIALS_OPTION, $shared, false );

		$this->api_client = null;
		return $saved;
	}

	public function is_testmode(): bool {
		return 'yes' === $this->get_option( 'testmode', 'yes' );
	}

	protected function credential( string $key ): string {
		$prefix = $this->is_testmode() ? 'test_' : 'prod_';
		return trim( (string) $this->get_option( $prefix . $key ) );
	}

	public function api(): Wompi_MP_API_Client {
		if ( null === $this->api_client ) {
			$this->api_client = new Wompi_MP_API_Client(
				$this->credential( 'public_key' ),
				$this->credential( 'private_key' ),
				$this->credential( 'integrity_secret' ),
				$this->is_testmode(),
				'yes' === $this->get_option( 'logging', 'no' )
			);
		}
		return $this->api_client;
	}

	public function events_secret(): string {
		return $this->credential( 'events_secret' );
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		// Verificación de integridad: la atribución es parte funcional del plugin.
		if ( ! function_exists( 'wompi_mp_brand_ok' ) || ! wompi_mp_brand_ok() ) {
			return false;
		}
		if ( 'COP' !== get_woocommerce_currency() ) {
			return false;
		}
		return '' !== $this->credential( 'public_key' )
			&& '' !== $this->credential( 'private_key' )
			&& '' !== $this->credential( 'integrity_secret' );
	}

	/**
	 * Campos de configuración compartidos (credenciales + webhook info).
	 */
	protected function shared_form_fields(): array {
		return array(
			'credentials_section'   => array(
				'title'       => __( 'Credenciales Wompi (compartidas entre Nequi y Daviplata)', 'wompi-moshipp' ),
				'type'        => 'title',
				'description' => __( 'Obtén tus llaves en comercios.wompi.co. Estas credenciales se comparten entre los dos métodos de pago: editarlas aquí las actualiza para ambos.', 'wompi-moshipp' ),
			),
			'testmode'              => array(
				'title'       => __( 'Modo de prueba (sandbox)', 'wompi-moshipp' ),
				'type'        => 'checkbox',
				'label'       => __( 'Usar el ambiente sandbox de Wompi (transacciones simuladas)', 'wompi-moshipp' ),
				'default'     => 'yes',
			),
			'test_public_key'       => array(
				'title'       => __( 'Llave pública de prueba', 'wompi-moshipp' ),
				'type'        => 'text',
				'placeholder' => 'pub_test_…',
			),
			'test_private_key'      => array(
				'title'       => __( 'Llave privada de prueba', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'prv_test_…',
			),
			'test_integrity_secret' => array(
				'title'       => __( 'Secreto de integridad de prueba', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'test_integrity_…',
			),
			'test_events_secret'    => array(
				'title'       => __( 'Secreto de eventos de prueba', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'test_events_…',
			),
			'prod_public_key'       => array(
				'title'       => __( 'Llave pública de producción', 'wompi-moshipp' ),
				'type'        => 'text',
				'placeholder' => 'pub_prod_…',
			),
			'prod_private_key'      => array(
				'title'       => __( 'Llave privada de producción', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'prv_prod_…',
			),
			'prod_integrity_secret' => array(
				'title'       => __( 'Secreto de integridad de producción', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'prod_integrity_…',
			),
			'prod_events_secret'    => array(
				'title'       => __( 'Secreto de eventos de producción', 'wompi-moshipp' ),
				'type'        => 'password',
				'placeholder' => 'prod_events_…',
			),
			'logging'               => array(
				'title'   => __( 'Registro de depuración', 'wompi-moshipp' ),
				'type'    => 'checkbox',
				'label'   => __( 'Guardar log de llamadas al API (WooCommerce → Estado → Logs, fuente wompi-moshipp)', 'wompi-moshipp' ),
				'default' => 'no',
			),
			'fees_section'          => array(
				'title'       => __( 'Comisiones de Wompi (para estimación en las órdenes)', 'wompi-moshipp' ),
				'type'        => 'title',
				'description' => __( 'El API de Wompi no reporta la comisión cobrada; con tu tarifa negociada el plugin muestra en cada orden una comisión y un neto estimados. Déjalo vacío si no quieres ver la estimación.', 'wompi-moshipp' ),
			),
			'fee_percent'           => array(
				'title'       => __( 'Comisión variable (%)', 'wompi-moshipp' ),
				'type'        => 'text',
				'default'     => '2.65',
				'placeholder' => '2.65',
				'description' => __( 'Porcentaje sobre el valor de la transacción.', 'wompi-moshipp' ),
			),
			'fee_fixed'             => array(
				'title'       => __( 'Comisión fija (COP)', 'wompi-moshipp' ),
				'type'        => 'text',
				'default'     => '700',
				'placeholder' => '700',
			),
			'fee_iva'               => array(
				'title'       => __( 'IVA sobre la comisión (%)', 'wompi-moshipp' ),
				'type'        => 'text',
				'default'     => '19',
				'placeholder' => '19',
			),
			'webhook_url'           => array(
				'title' => __( 'URL de eventos (webhook)', 'wompi-moshipp' ),
				'type'  => 'wompi_webhook',
			),
		);
	}

	/**
	 * Campo personalizado: URL del webhook con botón de copiar.
	 */
	public function generate_wompi_webhook_html( $key, $data ) {
		$url = Wompi_MP_Webhook::url();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="wompi-mp-webhook-box">
					<code><?php echo esc_html( $url ); ?></code>
					<button type="button" class="button" data-wompi-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copiar', 'wompi-moshipp' ); ?></button>
					<span class="wompi-mp-copy-done" style="display:none"><?php esc_html_e( '¡Copiada!', 'wompi-moshipp' ); ?></span>
				</div>
				<p class="description"><?php esc_html_e( 'Regístrala como "URL de eventos" en el dashboard de Wompi, una vez en el ambiente de pruebas y otra en producción. Sin esto, las órdenes solo se confirman por el polling de la página de gracias.', 'wompi-moshipp' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Pantalla de ajustes con encabezado de marca y verificación de conexión.
	 */
	public function admin_options() {
		$is_test = $this->is_testmode();
		$brand   = 'wompi_nequi' === $this->id ? 'nequi' : 'daviplata';
		?>
		<div class="wompi-mp-admin-hero wompi-mp-hero-<?php echo esc_attr( $brand ); ?>">
			<div>
				<h2><?php echo esc_html( $this->get_method_title() ); ?></h2>
				<p class="wompi-mp-hero-desc"><?php echo esc_html( $this->get_method_description() ); ?></p>
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
		<?php if ( ! function_exists( 'wompi_mp_brand_ok' ) || ! wompi_mp_brand_ok() ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Verificación de integridad fallida: la atribución del desarrollador fue eliminada o modificada. Los métodos de pago Wompi quedaron desactivados. Restaura los archivos originales del plugin o contacta a moshipp.com.', 'wompi-moshipp' ); ?></p>
			</div>
		<?php endif; ?>
		<div class="wompi-mp-admin-body wompi-mp-admin-<?php echo esc_attr( $brand ); ?>">
			<table class="form-table">
				<?php echo $this->generate_settings_html( $this->get_form_fields(), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado por la Settings API de WooCommerce. ?>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX admin: valida las llaves públicas de cada ambiente contra Wompi
	 * y devuelve el nombre del comercio.
	 */
	public static function ajax_check_credentials(): void {
		check_ajax_referer( 'wompi_mp_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$credentials = get_option( self::CREDENTIALS_OPTION, array() );
		$credentials = is_array( $credentials ) ? $credentials : array();
		$results     = array();

		$environments = array(
			'test' => array( Wompi_MP_API_Client::SANDBOX_URL, 'test_public_key' ),
			'prod' => array( Wompi_MP_API_Client::PRODUCTION_URL, 'prod_public_key' ),
		);

		foreach ( $environments as $env => list( $base_url, $option_key ) ) {
			$public_key = trim( (string) ( $credentials[ $option_key ] ?? '' ) );
			if ( '' === $public_key ) {
				continue;
			}
			$response = wp_remote_get( $base_url . '/merchants/' . rawurlencode( $public_key ), array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) ) {
				$results[ $env ] = array(
					'ok'      => false,
					'message' => __( 'Sin conexión con Wompi.', 'wompi-moshipp' ),
				);
				continue;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && ! empty( $body['data']['name'] ) ) {
				$results[ $env ] = array(
					'ok'   => true,
					'name' => (string) $body['data']['name'],
				);
			} else {
				$results[ $env ] = array(
					'ok'      => false,
					'message' => __( 'Llave pública inválida.', 'wompi-moshipp' ),
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Checkbox de aceptación de términos exigido por Wompi (regulación colombiana).
	 */
	protected function render_acceptance_checkbox(): void {
		$links = $this->api()->get_legal_permalinks();
		?>
		<p class="form-row form-row-wide wompi-mp-acceptance">
			<label for="<?php echo esc_attr( $this->id ); ?>_accept">
				<input type="checkbox" name="wompi_mp_accept" id="<?php echo esc_attr( $this->id ); ?>_accept" value="1" />
				<span class="wompi-mp-acceptance-text">
				<?php
				printf(
					/* translators: 1: URL reglamento, 2: URL autorización de datos. */
					wp_kses_post( __( 'Acepto el <a href="%1$s" target="_blank" rel="noopener">reglamento de usuarios</a> y la <a href="%2$s" target="_blank" rel="noopener">autorización para el tratamiento de datos personales</a> de Wompi. <span class="required">*</span>', 'wompi-moshipp' ) ),
					esc_url( $links['policy'] ),
					esc_url( $links['personal_data'] )
				);
				?>
				</span>
			</label>
		</p>
		<?php
		if ( function_exists( 'wompi_mp_brand_html' ) ) {
			echo wp_kses_post( wompi_mp_brand_html( true ) );
		}
	}

	protected function acceptance_was_checked(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		return ! empty( $_POST['wompi_mp_accept'] );
	}

	/**
	 * Referencia única y rastreable: wmpmp-{order_id}-{timestamp}-{aleatorio}.
	 */
	protected function build_reference( WC_Order $order ): string {
		return sprintf( 'wmpmp-%d-%d-%s', $order->get_id(), time(), strtolower( wp_generate_password( 6, false ) ) );
	}

	/**
	 * Crea la transacción en Wompi y guarda los metadatos en la orden.
	 *
	 * @return array|WP_Error Cuerpo 'data' de la transacción.
	 */
	protected function create_wompi_transaction( WC_Order $order, array $payment_method ) {
		// Verificación de integridad: sin la atribución intacta no se procesan pagos.
		if ( ! function_exists( 'wompi_mp_brand_ok' ) || ! wompi_mp_brand_ok() ) {
			return new WP_Error(
				'wompi_mp_integrity',
				__( 'La verificación de integridad del plugin de pagos falló. Contacta al desarrollador (moshipp.com).', 'wompi-moshipp' )
			);
		}

		$amount_in_cents = (int) round( (float) $order->get_total() * 100 );
		$reference       = $this->build_reference( $order );

		// URL de retorno sin query params: la página hosted de Wompi no los
		// preserva; el endpoint de retorno recibe ?id={tx} y resuelve la orden.
		$tx = $this->api()->create_transaction(
			$reference,
			$amount_in_cents,
			$order->get_billing_email(),
			$payment_method,
			Wompi_MP_Order_Sync::hosted_return_url()
		);

		if ( is_wp_error( $tx ) ) {
			return $tx;
		}

		$order->update_meta_data( Wompi_MP_Order_Sync::META_TX_ID, (string) $tx['id'] );
		$order->update_meta_data( Wompi_MP_Order_Sync::META_REFERENCE, $reference );
		$order->update_meta_data( Wompi_MP_Order_Sync::META_STATUS, (string) ( $tx['status'] ?? 'PENDING' ) );
		$order->update_meta_data( Wompi_MP_Order_Sync::META_ENV, $this->is_testmode() ? 'test' : 'prod' );
		$order->update_meta_data( Wompi_MP_Order_Sync::META_SNAPSHOT, Wompi_MP_Order_Sync::build_snapshot( $tx ) );
		$order->save();

		return $tx;
	}

	/**
	 * Pantalla de estado en la página de gracias (checkout/temas clásicos).
	 * En temas de bloques existe un fallback en wp_footer (ver Wompi_MP_Order_Sync).
	 */
	public function render_thankyou_status( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->get_payment_method() === $this->id ) {
			Wompi_MP_Order_Sync::render_status_ui( $order );
		}
	}

	/** Mensaje de espera específico del método. */
	abstract public function waiting_message(): string;
}
