<?php
/**
 * Gateway Daviplata: flujo hosted. Se crea la transacción, se espera la URL de la
 * experiencia de pago de Wompi (extra.url) y se redirige al cliente para el OTP.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Gateway_Daviplata extends Wompi_MP_Gateway {

	const DOC_TYPES = array(
		'CC'  => 'Cédula de ciudadanía',
		'CE'  => 'Cédula de extranjería',
		'NIT' => 'NIT',
		'TI'  => 'Tarjeta de identidad',
		'PP'  => 'Pasaporte',
	);

	public function __construct() {
		$this->id                 = 'wompi_daviplata';
		$this->method_title       = __( 'Wompi — Daviplata', 'wompi-moshipp' );
		$this->method_description = __( 'El cliente recibe un código OTP por SMS y lo confirma en la página segura de Wompi.', 'wompi-moshipp' );
		$this->order_button_text  = __( 'Pagar con Daviplata', 'wompi-moshipp' );

		parent::__construct();
	}

	public function init_form_fields() {
		$this->form_fields = array_merge(
			array(
				'enabled'     => array(
					'title'   => __( 'Activar / Desactivar', 'wompi-moshipp' ),
					'type'    => 'checkbox',
					'label'   => __( 'Activar pagos con Daviplata', 'wompi-moshipp' ),
					'default' => 'no',
				),
				'title'       => array(
					'title'   => __( 'Título', 'wompi-moshipp' ),
					'type'    => 'text',
					'default' => __( 'Daviplata', 'wompi-moshipp' ),
				),
				'description' => array(
					'title'   => __( 'Descripción', 'wompi-moshipp' ),
					'type'    => 'textarea',
					'default' => __( 'Recibirás un código por SMS para confirmar tu pago con Daviplata.', 'wompi-moshipp' ),
				),
			),
			$this->shared_form_fields()
		);
	}

	public function payment_fields() {
		?>
		<div class="wompi-mp-fields wompi-mp-daviplata">
			<?php if ( $this->description ) : ?>
				<p class="wompi-mp-desc"><?php echo wp_kses_post( $this->description ); ?></p>
			<?php endif; ?>
			<div class="wompi-mp-cols">
				<p class="form-row wompi-mp-field">
					<label for="wompi_mp_doc_type"><?php esc_html_e( 'Tipo de documento', 'wompi-moshipp' ); ?> <span class="required">*</span></label>
					<select id="wompi_mp_doc_type" name="wompi_mp_doc_type">
						<?php foreach ( self::DOC_TYPES as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-row wompi-mp-field">
					<label for="wompi_mp_doc_number"><?php esc_html_e( 'Número de documento', 'wompi-moshipp' ); ?> <span class="required">*</span></label>
					<input
						type="text"
						id="wompi_mp_doc_number"
						name="wompi_mp_doc_number"
						inputmode="numeric"
						maxlength="15"
						autocomplete="off"
						value=""
					/>
				</p>
			</div>
			<?php $this->render_acceptance_checkbox(); ?>
		</div>
		<?php
	}

	private function get_posted_doc(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		$type = strtoupper( (string) wc_clean( wp_unslash( $_POST['wompi_mp_doc_type'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$number = preg_replace( '/\D/', '', (string) wc_clean( wp_unslash( $_POST['wompi_mp_doc_number'] ?? '' ) ) );
		return array( $type, $number );
	}

	private function doc_is_valid( string $type, string $number ): bool {
		return isset( self::DOC_TYPES[ $type ] ) && 1 === preg_match( '/^\d{4,15}$/', $number );
	}

	public function validate_fields() {
		list( $type, $number ) = $this->get_posted_doc();
		$valid                 = true;
		if ( ! $this->doc_is_valid( $type, $number ) ) {
			wc_add_notice( __( 'Ingresa un tipo y número de documento válidos para Daviplata.', 'wompi-moshipp' ), 'error' );
			$valid = false;
		}
		if ( ! $this->acceptance_was_checked() ) {
			wc_add_notice( __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-moshipp' ), 'error' );
			$valid = false;
		}
		return $valid;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		list( $type, $number ) = $this->get_posted_doc();

		// El checkout por bloques no pasa por validate_fields(): revalidar aquí.
		if ( ! $this->doc_is_valid( $type, $number ) ) {
			wc_add_notice( __( 'Ingresa un tipo y número de documento válidos para Daviplata.', 'wompi-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( ! $this->acceptance_was_checked() ) {
			wc_add_notice( __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$description = sprintf(
			/* translators: %s: número de pedido. */
			__( 'Pedido %s', 'wompi-moshipp' ),
			$order->get_order_number()
		);

		$tx = $this->create_wompi_transaction(
			$order,
			array(
				'type'                => 'DAVIPLATA',
				'user_legal_id_type'  => $type,
				'user_legal_id'       => $number,
				// Wompi limita la descripción a 30 caracteres.
				'payment_description' => mb_substr( $description, 0, 30 ),
			)
		);

		if ( is_wp_error( $tx ) ) {
			wc_add_notice( $tx->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$hosted_url = $this->wait_for_hosted_url( (string) $tx['id'] );

		if ( ! $hosted_url ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: ID de transacción Wompi. */
					__( 'Daviplata: no se obtuvo la URL de pago hosted para la transacción %s.', 'wompi-moshipp' ),
					$tx['id']
				)
			);
			wc_add_notice( __( 'No pudimos iniciar el pago con Daviplata. Intenta de nuevo en unos minutos.', 'wompi-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: ID de transacción Wompi. */
				__( 'Cliente redirigido a la página de pago Daviplata de Wompi. Transacción: %s.', 'wompi-moshipp' ),
				$tx['id']
			)
		);

		if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $hosted_url,
		);
	}

	/**
	 * Espera a que la transacción exponga payment_method.extra.url.
	 * En sandbox aparece en el primer intento (~2 s); damos hasta ~12 s.
	 */
	private function wait_for_hosted_url( string $transaction_id ): ?string {
		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			sleep( 2 );
			$tx = $this->api()->get_transaction( $transaction_id );
			if ( is_wp_error( $tx ) ) {
				continue;
			}
			$url = $tx['payment_method']['extra']['url'] ?? '';
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
			$status = strtoupper( (string) ( $tx['status'] ?? 'PENDING' ) );
			if ( 'PENDING' !== $status ) {
				return null;
			}
		}
		return null;
	}

	public function waiting_message(): string {
		return __( 'Estamos confirmando tu pago con Daviplata.', 'wompi-moshipp' );
	}
}
