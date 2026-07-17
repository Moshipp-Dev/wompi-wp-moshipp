<?php
/**
 * Gateway Nequi: pago por notificación push a la app del cliente.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Gateway_Nequi extends Wompi_MP_Gateway {

	public function __construct() {
		$this->id                 = 'wompi_nequi';
		$this->method_title       = __( 'Wompi — Nequi', 'wompi-moshipp' );
		$this->method_description = __( 'El cliente recibe una notificación push en su app Nequi y aprueba el pago sin salir de la tienda.', 'wompi-moshipp' );
		$this->order_button_text  = __( 'Pagar con Nequi', 'wompi-moshipp' );

		parent::__construct();
	}

	public function init_form_fields() {
		$this->form_fields = array_merge(
			array(
				'enabled'     => array(
					'title'   => __( 'Activar / Desactivar', 'wompi-moshipp' ),
					'type'    => 'checkbox',
					'label'   => __( 'Activar pagos con Nequi', 'wompi-moshipp' ),
					'default' => 'no',
				),
				'title'       => array(
					'title'   => __( 'Título', 'wompi-moshipp' ),
					'type'    => 'text',
					'default' => __( 'Nequi', 'wompi-moshipp' ),
				),
				'description' => array(
					'title'   => __( 'Descripción', 'wompi-moshipp' ),
					'type'    => 'textarea',
					'default' => __( 'Recibirás una notificación en tu app Nequi para aprobar el pago.', 'wompi-moshipp' ),
				),
			),
			$this->shared_form_fields()
		);
	}

	public function payment_fields() {
		?>
		<div class="wompi-mp-fields wompi-mp-nequi">
			<?php if ( $this->description ) : ?>
				<p class="wompi-mp-desc"><?php echo wp_kses_post( $this->description ); ?></p>
			<?php endif; ?>
			<p class="form-row form-row-wide wompi-mp-field">
				<label for="wompi_mp_nequi_phone"><?php esc_html_e( 'Número de celular Nequi', 'wompi-moshipp' ); ?> <span class="required">*</span></label>
				<input
					type="tel"
					id="wompi_mp_nequi_phone"
					name="wompi_mp_nequi_phone"
					inputmode="numeric"
					autocomplete="tel-national"
					maxlength="10"
					placeholder="3001234567"
					value=""
				/>
			</p>
			<?php $this->render_acceptance_checkbox(); ?>
		</div>
		<?php
	}

	private function get_posted_phone(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		$phone = wc_clean( wp_unslash( $_POST['wompi_mp_nequi_phone'] ?? '' ) );
		return preg_replace( '/\D/', '', (string) $phone );
	}

	private function phone_is_valid( string $phone ): bool {
		return 1 === preg_match( '/^3\d{9}$/', $phone );
	}

	public function validate_fields() {
		$valid = true;
		if ( ! $this->phone_is_valid( $this->get_posted_phone() ) ) {
			wc_add_notice( __( 'Ingresa un número de celular Nequi válido (10 dígitos, inicia por 3).', 'wompi-moshipp' ), 'error' );
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
		$phone = $this->get_posted_phone();

		// El checkout por bloques no pasa por validate_fields(): revalidar aquí.
		if ( ! $this->phone_is_valid( $phone ) ) {
			wc_add_notice( __( 'Ingresa un número de celular Nequi válido (10 dígitos, inicia por 3).', 'wompi-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( ! $this->acceptance_was_checked() ) {
			wc_add_notice( __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$tx = $this->create_wompi_transaction(
			$order,
			array(
				'type'         => 'NEQUI',
				'phone_number' => $phone,
			)
		);

		if ( is_wp_error( $tx ) ) {
			wc_add_notice( $tx->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: celular, 2: ID de transacción Wompi. */
				__( 'Notificación push de Nequi enviada al %1$s. Transacción Wompi: %2$s. Esperando aprobación del cliente.', 'wompi-moshipp' ),
				$phone,
				$tx['id']
			)
		);

		if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}

		// La orden queda pendiente; la página de gracias hace polling y el webhook confirma.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	public function waiting_message(): string {
		return __( 'Abre tu app Nequi y aprueba la notificación de pago que acabamos de enviarte.', 'wompi-moshipp' );
	}
}
