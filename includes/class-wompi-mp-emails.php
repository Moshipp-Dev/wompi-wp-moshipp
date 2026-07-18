<?php
/**
 * Email al cliente con instrucciones cuando el pago queda pendiente de su acción
 * (aprobar el push de Nequi, confirmar el OTP de Daviplata, completar en el banco).
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Emails {

	public static function enabled(): bool {
		$credentials = get_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, array() );
		return ! is_array( $credentials ) || 'no' !== ( $credentials['pending_email'] ?? 'yes' );
	}

	public static function send_pending_instructions( WC_Order $order, string $gateway_id ): void {
		if ( ! self::enabled() || $order->is_paid() || ! $order->get_billing_email() ) {
			return;
		}

		switch ( $gateway_id ) {
			case 'wompi_nequi':
				$instruction = __( 'Abre tu app Nequi y aprueba la notificación de pago que te acabamos de enviar. Tienes 30 minutos antes de que expire.', 'wompi-wp-moshipp' );
				break;
			case 'wompi_daviplata':
				$instruction = __( 'Confirma el código que Daviplata te envió por SMS en la página segura de pago. Tienes 30 minutos antes de que expire.', 'wompi-wp-moshipp' );
				break;
			case 'wompi_pse':
				$instruction = __( 'Completa el pago en el portal de tu banco. Tienes 30 minutos antes de que expire.', 'wompi-wp-moshipp' );
				break;
			default:
				$instruction = __( 'Completa el pago siguiendo las instrucciones del método que elegiste. Tienes 30 minutos antes de que expire.', 'wompi-wp-moshipp' );
		}

		$heading = sprintf(
			/* translators: %s: número de pedido. */
			__( 'Tu pedido #%s espera el pago', 'wompi-wp-moshipp' ),
			$order->get_order_number()
		);

		$content  = '<p>' . sprintf(
			/* translators: %s: nombre del cliente. */
			esc_html__( 'Hola %s,', 'wompi-wp-moshipp' ),
			esc_html( $order->get_billing_first_name() )
		) . '</p>';
		$content .= '<p>' . esc_html( $instruction ) . '</p>';
		$content .= '<p>' . sprintf(
			'<a href="%s">%s</a>',
			esc_url( $order->get_checkout_order_received_url() ),
			esc_html__( 'Ver el estado de tu pedido', 'wompi-wp-moshipp' )
		) . '</p>';
		$content .= '<p>' . esc_html__( 'Cuando el pago se confirme te enviaremos el comprobante. Si no realizaste esta compra, ignora este mensaje.', 'wompi-wp-moshipp' ) . '</p>';

		$mailer = WC()->mailer();
		$mailer->send(
			$order->get_billing_email(),
			$heading,
			$mailer->wrap_message( $heading, $content ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		$order->add_order_note( __( 'Email de instrucciones de pago enviado al cliente.', 'wompi-wp-moshipp' ) );
	}
}
