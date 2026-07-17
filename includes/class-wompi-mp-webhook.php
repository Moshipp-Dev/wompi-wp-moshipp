<?php
/**
 * Receptor de eventos (webhooks) de Wompi.
 * URL: {sitio}/wc-api/wompi_mp_events — configúrala en el dashboard de Wompi.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Webhook {

	const ENDPOINT = 'wompi_mp_events';

	public static function init(): void {
		add_action( 'woocommerce_api_' . self::ENDPOINT, array( __CLASS__, 'handle' ) );
	}

	public static function url(): string {
		return home_url( '/wc-api/' . self::ENDPOINT );
	}

	public static function handle(): void {
		$raw   = file_get_contents( 'php://input' );
		$event = json_decode( (string) $raw, true );

		if ( ! is_array( $event ) || empty( $event['event'] ) ) {
			status_header( 400 );
			exit( 'invalid payload' );
		}

		if ( ! self::checksum_is_valid( $event ) ) {
			self::log( 'Checksum inválido para el evento recibido.', 'error' );
			status_header( 403 );
			exit( 'invalid checksum' );
		}

		if ( 'transaction.updated' === $event['event'] ) {
			self::process_transaction_updated( $event );
		}

		// Siempre 200 tras validar autenticidad, para evitar reintentos innecesarios.
		status_header( 200 );
		exit( 'ok' );
	}

	/**
	 * Valida el checksum SHA256: concatenar los valores de signature.properties
	 * (rutas con puntos dentro de data) + timestamp + secreto de eventos.
	 */
	private static function checksum_is_valid( array $event ): bool {
		$signature  = $event['signature'] ?? array();
		$properties = $signature['properties'] ?? null;
		$checksum   = strtolower( (string) ( $signature['checksum'] ?? '' ) );
		$timestamp  = $event['timestamp'] ?? '';
		$data       = $event['data'] ?? array();

		if ( ! is_array( $properties ) || '' === $checksum || '' === (string) $timestamp ) {
			return false;
		}

		$secret = self::events_secret_for( (string) ( $event['environment'] ?? '' ) );
		if ( '' === $secret ) {
			self::log( 'No hay secreto de eventos configurado para el ambiente del evento.', 'error' );
			return false;
		}

		$concatenated = '';
		foreach ( $properties as $path ) {
			$value = $data;
			foreach ( explode( '.', (string) $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return false;
				}
				$value = $value[ $segment ];
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$concatenated .= (string) $value;
		}

		$expected = hash( 'sha256', $concatenated . $timestamp . $secret );

		return hash_equals( $expected, $checksum );
	}

	private static function events_secret_for( string $environment ): string {
		$credentials = get_option( Wompi_MP_Gateway::CREDENTIALS_OPTION, array() );
		if ( ! is_array( $credentials ) ) {
			return '';
		}
		$key = 'test' === $environment ? 'test_events_secret' : 'prod_events_secret';
		return trim( (string) ( $credentials[ $key ] ?? '' ) );
	}

	private static function process_transaction_updated( array $event ): void {
		$tx = $event['data']['transaction'] ?? null;
		if ( ! is_array( $tx ) || empty( $tx['reference'] ) ) {
			return;
		}

		$order = Wompi_MP_Order_Sync::find_order_by_reference( (string) $tx['reference'] );
		if ( ! $order ) {
			self::log( 'Evento para referencia sin orden: ' . $tx['reference'] );
			return;
		}

		$status = Wompi_MP_Order_Sync::apply_transaction( $order, $tx );
		self::log( sprintf( 'Evento transaction.updated aplicado. Orden #%d → %s', $order->get_id(), $status ) );
	}

	private static function log( string $message, string $level = 'info' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, '[webhook] ' . $message, array( 'source' => 'wompi-moshipp' ) );
		}
	}
}
