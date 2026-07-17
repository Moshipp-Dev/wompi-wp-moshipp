<?php
/**
 * Meta box "Pago Wompi" en la pantalla de la orden: detalle de la transacción
 * y comisión/neto estimados según la tarifa configurada.
 * Compatible con HPOS y con el almacenamiento clásico de órdenes.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Admin_Order {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
	}

	public static function register_meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'wompi-mp-order',
			__( 'Pago Wompi', 'wompi-moshipp' ),
			array( __CLASS__, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order || ! Wompi_MP_Order_Sync::is_wompi_order( $order ) ) {
			echo '<p>' . esc_html__( 'Esta orden no se pagó con Wompi.', 'wompi-moshipp' ) . '</p>';
			return;
		}

		// Si sigue pendiente, aprovechar la vista del admin para sincronizar.
		if ( ! $order->is_paid() && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			Wompi_MP_Order_Sync::refresh_from_api( $order );
		}

		$tx_id    = (string) $order->get_meta( Wompi_MP_Order_Sync::META_TX_ID );
		$snapshot = $order->get_meta( Wompi_MP_Order_Sync::META_SNAPSHOT );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		// Órdenes anteriores a esta versión: completar el snapshot desde el API.
		if ( ! $snapshot && $tx_id ) {
			$gateway = wc_get_payment_gateway_by_order( $order );
			if ( $gateway instanceof Wompi_MP_Gateway ) {
				$tx = $gateway->api()->get_transaction( $tx_id );
				if ( ! is_wp_error( $tx ) ) {
					$snapshot = Wompi_MP_Order_Sync::build_snapshot( $tx );
					$order->update_meta_data( Wompi_MP_Order_Sync::META_SNAPSHOT, $snapshot );
					$order->save();
				}
			}
		}

		if ( ! $tx_id ) {
			echo '<p>' . esc_html__( 'Aún no hay una transacción Wompi asociada.', 'wompi-moshipp' ) . '</p>';
			return;
		}

		$status = (string) ( $snapshot['status'] ?? $order->get_meta( Wompi_MP_Order_Sync::META_STATUS ) );
		$env    = (string) $order->get_meta( Wompi_MP_Order_Sync::META_ENV );
		$amount = (int) ( $snapshot['amount_in_cents'] ?? 0 );

		$method_label = 'DAVIPLATA' === ( $snapshot['type'] ?? '' ) ? 'Daviplata' : 'Nequi';
		if ( ! empty( $snapshot['detail'] ) ) {
			$method_label .= ' · ' . $snapshot['detail'];
		}

		echo '<div class="wompi-mp-metabox">';

		self::row( __( 'Estado', 'wompi-moshipp' ), self::status_badge( $status ), true );
		self::row( __( 'Método', 'wompi-moshipp' ), esc_html( $method_label ), true );
		self::row( __( 'Transacción', 'wompi-moshipp' ), '<code style="font-size:11px">' . esc_html( $tx_id ) . '</code>', true );
		self::row( __( 'Referencia', 'wompi-moshipp' ), '<code style="font-size:11px">' . esc_html( (string) $order->get_meta( Wompi_MP_Order_Sync::META_REFERENCE ) ) . '</code>', true );

		if ( 'test' === $env ) {
			self::row( __( 'Ambiente', 'wompi-moshipp' ), '<span style="background:#f59e0b;color:#1c1917;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:600">' . esc_html__( 'Sandbox (prueba)', 'wompi-moshipp' ) . '</span>', true );
		}

		if ( ! empty( $snapshot['finalized_at'] ) ) {
			$local = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $snapshot['finalized_at'] ) ), 'Y-m-d H:i' );
			self::row( __( 'Finalizada', 'wompi-moshipp' ), esc_html( $local ), true );
		}

		if ( ! empty( $snapshot['status_message'] ) ) {
			self::row( __( 'Mensaje', 'wompi-moshipp' ), esc_html( $snapshot['status_message'] ), true );
		}

		if ( $amount > 0 ) {
			echo '<hr style="margin:10px 0;border:0;border-top:1px solid #e2e2e5">';
			self::row( __( 'Monto pagado', 'wompi-moshipp' ), wp_kses_post( wc_price( $amount / 100 ) ), true );
			self::render_fee_estimate( $amount, $status );
		}

		echo '<p style="margin:10px 0 0"><a href="https://comercios.wompi.co" target="_blank" rel="noopener" class="button button-small">' . esc_html__( 'Ver en el dashboard de Wompi ↗', 'wompi-moshipp' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Comisión y neto estimados según la tarifa configurada en los ajustes.
	 */
	private static function render_fee_estimate( int $amount_in_cents, string $status ): void {
		if ( 'APPROVED' !== $status ) {
			return;
		}
		$credentials = get_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, array() );
		$credentials = is_array( $credentials ) ? $credentials : array();

		$percent = (string) ( $credentials['fee_percent'] ?? '' );
		$fixed   = (string) ( $credentials['fee_fixed'] ?? '' );
		if ( '' === trim( $percent ) && '' === trim( $fixed ) ) {
			return;
		}

		$percent_value = (float) str_replace( ',', '.', $percent );
		$fixed_value   = (float) str_replace( ',', '.', $fixed );
		$iva_value     = (float) str_replace( ',', '.', (string) ( $credentials['fee_iva'] ?? '0' ) );

		$amount   = $amount_in_cents / 100;
		$fee_base = $amount * $percent_value / 100 + $fixed_value;
		$fee      = $fee_base * ( 1 + $iva_value / 100 );
		$net      = $amount - $fee;

		self::row( __( 'Comisión estimada', 'wompi-moshipp' ), wp_kses_post( wc_price( $fee ) ), true );
		self::row( __( 'Neto estimado', 'wompi-moshipp' ), '<strong>' . wp_kses_post( wc_price( $net ) ) . '</strong>', true );
		echo '<p class="description" style="margin:6px 0 0;font-size:11px">' . sprintf(
			/* translators: 1: %% variable, 2: fijo, 3: %% IVA. */
			esc_html__( 'Estimación con tarifa %1$s%% + %2$s + IVA %3$s%% (configurable en los ajustes del gateway). Wompi no reporta la comisión real por API.', 'wompi-moshipp' ),
			esc_html( rtrim( rtrim( number_format( $percent_value, 2, '.', '' ), '0' ), '.' ) ),
			wp_kses_post( wc_price( $fixed_value ) ),
			esc_html( rtrim( rtrim( number_format( $iva_value, 2, '.', '' ), '0' ), '.' ) )
		) . '</p>';
	}

	private static function status_badge( string $status ): string {
		$colors = array(
			'APPROVED' => array( '#dcfce7', '#166534', __( 'Aprobada', 'wompi-moshipp' ) ),
			'PENDING'  => array( '#fef3c7', '#92400e', __( 'Pendiente', 'wompi-moshipp' ) ),
			'DECLINED' => array( '#fee2e2', '#991b1b', __( 'Declinada', 'wompi-moshipp' ) ),
			'ERROR'    => array( '#fee2e2', '#991b1b', __( 'Error', 'wompi-moshipp' ) ),
			'VOIDED'   => array( '#e4e4e7', '#3f3f46', __( 'Anulada', 'wompi-moshipp' ) ),
		);
		list( $bg, $fg, $label ) = $colors[ $status ] ?? array( '#e4e4e7', '#3f3f46', $status );
		return '<span style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';border-radius:99px;padding:1px 10px;font-size:11px;font-weight:600">' . esc_html( $label ) . '</span>';
	}

	private static function row( string $label, string $value_html, bool $value_is_safe = false ): void {
		echo '<p style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0">';
		echo '<span style="color:#646970">' . esc_html( $label ) . '</span>';
		echo '<span style="text-align:right">' . ( $value_is_safe ? $value_html : esc_html( $value_html ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML construido internamente con valores escapados.
		echo '</p>';
	}
}
