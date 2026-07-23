<?php
/**
 * Gateway PSE: débito bancario. Se crea la transacción, se espera la URL del
 * banco (extra.async_payment_url) y se redirige al cliente a su banco.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Gateway_PSE extends Wompi_MP_Gateway {

	const DOC_TYPES = array(
		'CC'  => 'Cédula de ciudadanía',
		'CE'  => 'Cédula de extranjería',
		'NIT' => 'NIT',
		'TI'  => 'Tarjeta de identidad',
		'PP'  => 'Pasaporte',
	);

	const USER_TYPES = array(
		'0' => 'Persona natural',
		'1' => 'Persona jurídica',
	);

	public function __construct() {
		$this->id                 = 'wompi_pse';
		$this->icon               = WOMPI_MP_PLUGIN_URL . 'assets/img/pse.png';
		$this->method_title       = __( 'Wompi — PSE', 'wompi-wp-moshipp' );
		$this->method_description = __( 'Débito desde cuentas bancarias colombianas: el cliente es redirigido al portal de su banco para autorizar el pago.', 'wompi-wp-moshipp' );
		$this->order_button_text  = __( 'Pagar con PSE', 'wompi-wp-moshipp' );

		parent::__construct();
	}

	public function init_form_fields() {
		$this->form_fields = array_merge(
			array(
				'enabled'     => array(
					'title'   => __( 'Activar / Desactivar', 'wompi-wp-moshipp' ),
					'type'    => 'checkbox',
					'label'   => __( 'Activar pagos con PSE', 'wompi-wp-moshipp' ),
					'default' => 'no',
				),
				'title'       => array(
					'title'   => __( 'Título', 'wompi-wp-moshipp' ),
					'type'    => 'text',
					'default' => __( 'PSE — débito bancario', 'wompi-wp-moshipp' ),
				),
				'description' => array(
					'title'   => __( 'Descripción', 'wompi-wp-moshipp' ),
					'type'    => 'textarea',
					'default' => __( 'Serás redirigido al portal de tu banco para autorizar el pago.', 'wompi-wp-moshipp' ),
				),
			),
			$this->shared_form_fields()
		);
	}

	/**
	 * @return array<array{financial_institution_code:string,financial_institution_name:string}>
	 */
	public function get_banks(): array {
		$banks = $this->api()->get_pse_financial_institutions();
		return is_wp_error( $banks ) ? array() : $banks;
	}

	public function payment_fields() {
		$banks = $this->get_banks();
		?>
		<div class="wompi-mp-fields wompi-mp-pse">
			<?php if ( $this->description ) : ?>
				<p class="wompi-mp-desc"><?php echo wp_kses_post( $this->description ); ?></p>
			<?php endif; ?>
			<p class="form-row form-row-wide wompi-mp-field">
				<label for="wompi_mp_pse_bank"><?php esc_html_e( 'Banco', 'wompi-wp-moshipp' ); ?> <span class="required">*</span></label>
				<select id="wompi_mp_pse_bank" name="wompi_mp_pse_bank">
					<option value=""><?php esc_html_e( '— Selecciona tu banco —', 'wompi-wp-moshipp' ); ?></option>
					<?php foreach ( $banks as $bank ) : ?>
						<option value="<?php echo esc_attr( $bank['financial_institution_code'] ); ?>"><?php echo esc_html( $bank['financial_institution_name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-wide wompi-mp-field">
				<label for="wompi_mp_user_type"><?php esc_html_e( 'Tipo de persona', 'wompi-wp-moshipp' ); ?> <span class="required">*</span></label>
				<select id="wompi_mp_user_type" name="wompi_mp_user_type">
					<?php foreach ( self::USER_TYPES as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<div class="wompi-mp-cols">
				<p class="form-row wompi-mp-field">
					<label for="wompi_mp_doc_type"><?php esc_html_e( 'Tipo de documento', 'wompi-wp-moshipp' ); ?> <span class="required">*</span></label>
					<select id="wompi_mp_doc_type" name="wompi_mp_doc_type">
						<?php foreach ( self::DOC_TYPES as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-row wompi-mp-field">
					<label for="wompi_mp_doc_number"><?php esc_html_e( 'Número de documento', 'wompi-wp-moshipp' ); ?> <span class="required">*</span></label>
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

	private function get_posted_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce valida el nonce del checkout.
		$bank      = isset( $_POST['wompi_mp_pse_bank'] ) ? sanitize_text_field( wp_unslash( $_POST['wompi_mp_pse_bank'] ) ) : '';
		$user_type = isset( $_POST['wompi_mp_user_type'] ) ? sanitize_text_field( wp_unslash( $_POST['wompi_mp_user_type'] ) ) : '0';
		$doc_type  = isset( $_POST['wompi_mp_doc_type'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['wompi_mp_doc_type'] ) ) ) : '';
		$doc       = isset( $_POST['wompi_mp_doc_number'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['wompi_mp_doc_number'] ) ) ) : '';
		// phpcs:enable
		return array( $bank, $user_type, $doc_type, $doc );
	}

	private function fields_are_valid( string $bank, string $user_type, string $doc_type, string $doc ): bool {
		return '' !== $bank
			&& isset( self::USER_TYPES[ $user_type ] )
			&& isset( self::DOC_TYPES[ $doc_type ] )
			&& 1 === preg_match( '/^\d{4,15}$/', $doc );
	}

	public function validate_fields() {
		list( $bank, $user_type, $doc_type, $doc ) = $this->get_posted_fields();
		$valid                                     = true;
		if ( ! $this->fields_are_valid( $bank, $user_type, $doc_type, $doc ) ) {
			wc_add_notice( __( 'Selecciona tu banco e ingresa un documento válido para pagar con PSE.', 'wompi-wp-moshipp' ), 'error' );
			$valid = false;
		}
		if ( ! $this->acceptance_was_checked() ) {
			wc_add_notice( __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-wp-moshipp' ), 'error' );
			$valid = false;
		}
		return $valid;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		list( $bank, $user_type, $doc_type, $doc ) = $this->get_posted_fields();

		// El checkout por bloques no pasa por validate_fields(): revalidar aquí.
		if ( ! $this->fields_are_valid( $bank, $user_type, $doc_type, $doc ) ) {
			wc_add_notice( __( 'Selecciona tu banco e ingresa un documento válido para pagar con PSE.', 'wompi-wp-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( ! $this->acceptance_was_checked() ) {
			wc_add_notice( __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-wp-moshipp' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$description = sprintf(
			/* translators: %s: número de pedido. */
			__( 'Pedido %s', 'wompi-wp-moshipp' ),
			$order->get_order_number()
		);

		$tx = $this->create_wompi_transaction(
			$order,
			array(
				'type'                       => 'PSE',
				'user_type'                  => (int) $user_type,
				'user_legal_id_type'         => $doc_type,
				'user_legal_id'              => $doc,
				'financial_institution_code' => $bank,
				// Wompi limita la descripción a 30 caracteres.
				'payment_description'        => mb_substr( $description, 0, 30 ),
			)
		);

		if ( is_wp_error( $tx ) ) {
			wc_add_notice( $tx->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$result = $this->wait_for_bank_url( (string) $tx['id'], $order );

		if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}

		if ( ! empty( $result['url'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: ID de transacción Wompi. */
					__( 'Cliente redirigido al portal de su banco (PSE). Transacción: %s.', 'wompi-wp-moshipp' ),
					$tx['id']
				)
			);
			return array(
				'result'   => 'success',
				'redirect' => $result['url'],
			);
		}

		if ( ! empty( $result['final'] ) ) {
			// El sandbox puede resolver la transacción de inmediato.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		wc_add_notice( __( 'No pudimos iniciar el pago con PSE. Intenta de nuevo en unos minutos.', 'wompi-wp-moshipp' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Espera la URL del banco (payment_method.extra.async_payment_url).
	 * Si Wompi finaliza la transacción de inmediato, sincroniza la orden.
	 *
	 * @return array{url?:string,final?:bool}
	 */
	private function wait_for_bank_url( string $transaction_id, WC_Order $order ): array {
		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			sleep( 2 );
			$tx = $this->api()->get_transaction( $transaction_id );
			if ( is_wp_error( $tx ) ) {
				continue;
			}
			$url = $tx['payment_method']['extra']['async_payment_url'] ?? '';
			$fin = 'PENDING' !== strtoupper( (string) ( $tx['status'] ?? 'PENDING' ) );
			if ( is_string( $url ) && '' !== $url && ! $fin ) {
				return array( 'url' => $url );
			}
			if ( $fin ) {
				Wompi_MP_Order_Sync::apply_transaction( $order, $tx );
				return array( 'final' => true );
			}
		}
		return array();
	}

	public function waiting_message(): string {
		return __( 'Estamos esperando la confirmación de tu banco.', 'wompi-wp-moshipp' );
	}
}
