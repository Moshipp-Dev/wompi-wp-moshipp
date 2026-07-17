<?php
/**
 * Sincronización del estado de la orden con el estado de la transacción Wompi.
 * Usada por el polling AJAX, la página de gracias y el webhook.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Order_Sync {

	const META_TX_ID     = '_wompi_mp_transaction_id';
	const META_REFERENCE = '_wompi_mp_reference';
	const META_STATUS    = '_wompi_mp_last_status';
	const META_SNAPSHOT  = '_wompi_mp_tx_snapshot';
	const META_ENV       = '_wompi_mp_env';

	/** Evita render doble y llamadas repetidas al API en una misma carga. */
	private static bool $ui_rendered = false;
	private static array $synced = array();

	public static function init(): void {
		add_action( 'wp_ajax_wompi_mp_check_status', array( __CLASS__, 'ajax_check_status' ) );
		add_action( 'wp_ajax_nopriv_wompi_mp_check_status', array( __CLASS__, 'ajax_check_status' ) );
		// Sincroniza contra el API al cargar la página de gracias (por si el webhook aún no llega).
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'sync_on_thankyou' ) );
		// Fallback para temas de bloques, donde los hooks clásicos de thankyou pueden no dispararse.
		add_action( 'wp_footer', array( __CLASS__, 'render_fallback_on_order_received' ), 5 );
		// Retorno desde la experiencia hosted de Wompi (Daviplata): llega solo con ?id={tx}.
		add_action( 'woocommerce_api_wompi_mp_return', array( __CLASS__, 'handle_hosted_return' ) );
	}

	/**
	 * URL de retorno para flujos hosted. Sin query params propios: la página de
	 * Wompi no los preserva, pero sí anexa ?id={transaction_id} al volver.
	 */
	public static function hosted_return_url(): string {
		return home_url( '/wc-api/wompi_mp_return' );
	}

	/**
	 * Busca la orden a partir de la referencia wmpmp-{order_id}-{timestamp}-{aleatorio},
	 * verificando contra el metadato guardado para evitar suplantaciones.
	 */
	public static function find_order_by_reference( string $reference ): ?WC_Order {
		if ( ! preg_match( '/^wmpmp-(\d+)-/', $reference, $matches ) ) {
			return null;
		}
		$order = wc_get_order( (int) $matches[1] );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$stored = (string) $order->get_meta( self::META_REFERENCE );
		if ( '' === $stored || ! hash_equals( $stored, $reference ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Retorno del cliente desde la página de pago de Wompi: sincroniza la orden
	 * y redirige a la página de confirmación del pedido.
	 */
	public static function handle_hosted_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- retorno externo; la orden se valida vía la referencia firmada por Wompi.
		$tx_id = (string) wc_clean( wp_unslash( $_GET['id'] ?? '' ) );
		if ( '' === $tx_id ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = null;
		foreach ( array( 'wompi_daviplata', 'wompi_nequi' ) as $id ) {
			if ( isset( $gateways[ $id ] ) && $gateways[ $id ] instanceof Wompi_MP_Gateway ) {
				$gateway = $gateways[ $id ];
				break;
			}
		}
		if ( ! $gateway ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$tx = $gateway->api()->get_transaction( $tx_id );
		if ( is_wp_error( $tx ) || empty( $tx['reference'] ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$order = self::find_order_by_reference( (string) $tx['reference'] );
		if ( ! $order || (string) $order->get_meta( self::META_TX_ID ) !== $tx_id ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		self::apply_transaction( $order, $tx );

		$order_gateway = wc_get_payment_gateway_by_order( $order );
		$redirect      = $order_gateway instanceof WC_Payment_Gateway
			? $order_gateway->get_return_url( $order )
			: $order->get_checkout_order_received_url();

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function is_wompi_order( WC_Order $order ): bool {
		return in_array( $order->get_payment_method(), array( 'wompi_nequi', 'wompi_daviplata' ), true );
	}

	/**
	 * Aplica a la orden el estado reportado por Wompi. Idempotente.
	 *
	 * @param array $tx Cuerpo 'data' de la transacción Wompi.
	 * @return string Estado Wompi aplicado.
	 */
	public static function apply_transaction( WC_Order $order, array $tx ): string {
		$status  = strtoupper( (string) ( $tx['status'] ?? 'PENDING' ) );
		$tx_id   = (string) ( $tx['id'] ?? '' );
		$message = (string) ( $tx['status_message'] ?? '' );

		$order->update_meta_data( self::META_STATUS, $status );
		$order->update_meta_data( self::META_SNAPSHOT, self::build_snapshot( $tx ) );

		switch ( $status ) {
			case 'APPROVED':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $tx_id );
					$order->add_order_note(
						sprintf(
							/* translators: %s: ID de transacción Wompi. */
							__( 'Pago aprobado por Wompi. Transacción: %s', 'wompi-moshipp' ),
							$tx_id
						)
					);
				}
				break;

			case 'DECLINED':
			case 'ERROR':
			case 'VOIDED':
				if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
					$order->update_status(
						'failed',
						sprintf(
							/* translators: 1: estado Wompi, 2: ID de transacción, 3: mensaje. */
							__( 'Pago %1$s en Wompi. Transacción: %2$s. %3$s', 'wompi-moshipp' ),
							$status,
							$tx_id,
							$message
						)
					);
				}
				break;
		}

		$order->save();
		return $status;
	}

	/**
	 * Resumen de la transacción para mostrar en el admin de la orden.
	 */
	public static function build_snapshot( array $tx ): array {
		$pm     = is_array( $tx['payment_method'] ?? null ) ? $tx['payment_method'] : array();
		$type   = strtoupper( (string) ( $tx['payment_method_type'] ?? ( $pm['type'] ?? '' ) ) );
		$detail = '';
		if ( 'NEQUI' === $type ) {
			$detail = (string) ( $pm['phone_number'] ?? '' );
		} elseif ( 'DAVIPLATA' === $type ) {
			$detail = trim( (string) ( $pm['user_legal_id_type'] ?? '' ) . ' ' . (string) ( $pm['user_legal_id'] ?? '' ) );
		}
		return array(
			'id'              => (string) ( $tx['id'] ?? '' ),
			'status'          => strtoupper( (string) ( $tx['status'] ?? '' ) ),
			'status_message'  => (string) ( $tx['status_message'] ?? '' ),
			'amount_in_cents' => (int) ( $tx['amount_in_cents'] ?? 0 ),
			'currency'        => (string) ( $tx['currency'] ?? 'COP' ),
			'type'            => $type,
			'detail'          => $detail,
			'created_at'      => (string) ( $tx['created_at'] ?? '' ),
			'finalized_at'    => (string) ( $tx['finalized_at'] ?? '' ),
		);
	}

	/**
	 * Consulta el API y sincroniza. Devuelve el estado Wompi o null si no aplica.
	 */
	public static function refresh_from_api( WC_Order $order ): ?string {
		$tx_id = $order->get_meta( self::META_TX_ID );
		if ( ! $tx_id || ! self::is_wompi_order( $order ) ) {
			return null;
		}
		if ( $order->is_paid() ) {
			return 'APPROVED';
		}
		if ( isset( self::$synced[ $order->get_id() ] ) ) {
			return self::$synced[ $order->get_id() ];
		}

		$gateway = wc_get_payment_gateway_by_order( $order );
		if ( ! $gateway instanceof Wompi_MP_Gateway ) {
			return null;
		}

		$tx = $gateway->api()->get_transaction( $tx_id );
		if ( is_wp_error( $tx ) ) {
			return null;
		}

		$status = self::apply_transaction( $order, $tx );

		self::$synced[ $order->get_id() ] = $status;
		return $status;
	}

	public static function sync_on_thankyou( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && self::is_wompi_order( $order ) && ! $order->is_paid() ) {
			self::refresh_from_api( $order );
		}
	}

	/**
	 * Endpoint AJAX del polling en la página de gracias.
	 */
	public static function ajax_check_status(): void {
		check_ajax_referer( 'wompi_mp_poll', 'nonce' );

		$order_id  = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$order_key = wc_clean( wp_unslash( $_POST['order_key'] ?? '' ) );

		// Rate limit: el polling legítimo hace ~20 req/min por orden.
		$rate_key = 'wompi_mp_rl_' . $order_id;
		$count    = (int) get_transient( $rate_key );
		if ( $count > 40 ) {
			wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
		}
		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), (string) $order_key ) || ! self::is_wompi_order( $order ) ) {
			wp_send_json_error( array( 'message' => 'invalid_order' ), 400 );
		}

		$status = self::refresh_from_api( $order );

		if ( 'APPROVED' === $status ) {
			$gateway  = wc_get_payment_gateway_by_order( $order );
			$redirect = $gateway ? $gateway->get_return_url( $order ) : $order->get_checkout_order_received_url();
			wp_send_json_success(
				array(
					'status'   => 'APPROVED',
					'redirect' => $redirect,
				)
			);
		}

		if ( in_array( $status, array( 'DECLINED', 'ERROR', 'VOIDED' ), true ) ) {
			wp_send_json_success(
				array(
					'status'   => 'FAILED',
					'redirect' => $order->get_checkout_payment_url(),
				)
			);
		}

		wp_send_json_success( array( 'status' => 'PENDING' ) );
	}

	/**
	 * Pinta el estado del pago: espera con polling si está pendiente,
	 * o aviso con enlace de reintento si falló. Idempotente por carga.
	 */
	public static function render_status_ui( WC_Order $order ): void {
		if ( self::$ui_rendered || ! self::is_wompi_order( $order ) || $order->is_paid() ) {
			return;
		}
		self::$ui_rendered = true;
		self::refresh_from_api( $order );

		if ( $order->has_status( 'failed' ) ) {
			?>
			<div class="wompi-mp-wait wompi-mp-failed" id="wompi-mp-wait-wrap">
				<p><?php esc_html_e( 'Tu pago fue rechazado o no pudo completarse.', 'wompi-moshipp' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>"><?php esc_html_e( 'Intentar de nuevo', 'wompi-moshipp' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$gateway = wc_get_payment_gateway_by_order( $order );
		$message = $gateway instanceof Wompi_MP_Gateway
			? $gateway->waiting_message()
			: __( 'Estamos confirmando tu pago.', 'wompi-moshipp' );

		wp_enqueue_style( 'wompi-mp', WOMPI_MP_PLUGIN_URL . 'assets/css/wompi-mp.css', array(), WOMPI_MP_VERSION );
		wp_enqueue_script( 'wompi-mp-poll', WOMPI_MP_PLUGIN_URL . 'assets/js/status-poll.js', array(), WOMPI_MP_VERSION, true );
		wp_localize_script(
			'wompi-mp-poll',
			'wompiMpPoll',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wompi_mp_poll' ),
				'orderId'    => $order->get_id(),
				'orderKey'   => $order->get_order_key(),
				'interval'   => 3000,
				'timeout'    => 5 * MINUTE_IN_SECONDS * 1000,
				'expiredMsg' => __( 'El tiempo de espera terminó. Si ya pagaste, esta página se actualizará al recibir la confirmación; si no, puedes reintentar el pago.', 'wompi-moshipp' ),
				'retryUrl'   => $order->get_checkout_payment_url(),
			)
		);
		?>
		<div class="wompi-mp-wait" id="wompi-mp-wait" data-wompi-mp-relocate="1">
			<span class="wompi-mp-spinner" aria-hidden="true"></span>
			<p class="wompi-mp-wait-msg"><?php echo esc_html( $message ); ?></p>
			<p class="wompi-mp-wait-sub"><?php esc_html_e( 'Esta página se actualizará automáticamente cuando se confirme el pago.', 'wompi-moshipp' ); ?></p>
		</div>
		<?php
	}

	/**
	 * En temas de bloques el bloque de confirmación de pedido no dispara los
	 * hooks clásicos de thankyou; si nadie pintó la UI, se pinta en el footer
	 * y el JS la reubica junto al contenido principal.
	 */
	public static function render_fallback_on_order_received(): void {
		if ( self::$ui_rendered || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- misma validación que usa WooCommerce en la página de gracias.
		$order_key = wc_clean( wp_unslash( $_GET['key'] ?? '' ) );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), (string) $order_key ) ) {
			return;
		}

		self::render_status_ui( $order );
		?>
		<script>
		( function () {
			var box = document.getElementById( 'wompi-mp-wait' ) || document.getElementById( 'wompi-mp-wait-wrap' );
			var target = document.querySelector( '.wc-block-order-confirmation-status, .woocommerce-thankyou-order-received, .woocommerce-order, .entry-content' );
			if ( box && target ) {
				target.parentNode.insertBefore( box, target.nextSibling );
			}
		} )();
		</script>
		<?php
	}
}
