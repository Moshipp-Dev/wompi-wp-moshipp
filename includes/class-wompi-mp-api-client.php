<?php
/**
 * Cliente HTTP para el API de Wompi (sandbox/producción).
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_API_Client {

	const SANDBOX_URL    = 'https://sandbox.wompi.co/v1';
	const PRODUCTION_URL = 'https://production.wompi.co/v1';

	private string $public_key;
	private string $private_key;
	private string $integrity_secret;
	private bool $testmode;
	private bool $logging;

	public function __construct( string $public_key, string $private_key, string $integrity_secret, bool $testmode, bool $logging = false ) {
		$this->public_key       = $public_key;
		$this->private_key      = $private_key;
		$this->integrity_secret = $integrity_secret;
		$this->testmode         = $testmode;
		$this->logging          = $logging;
	}

	public function base_url(): string {
		return $this->testmode ? self::SANDBOX_URL : self::PRODUCTION_URL;
	}

	/**
	 * Información del comercio. Incluye los tokens de aceptación prefirmados,
	 * que son de UN SOLO USO: pedir frescos justo antes de cada transacción.
	 *
	 * @return array|WP_Error
	 */
	public function get_merchant() {
		return $this->request( 'GET', '/merchants/' . rawurlencode( $this->public_key ) );
	}

	/**
	 * Tokens de aceptación frescos para una transacción.
	 *
	 * @return array{acceptance_token:string,accept_personal_auth:string}|WP_Error
	 */
	public function get_fresh_acceptance_tokens() {
		$merchant = $this->get_merchant();
		if ( is_wp_error( $merchant ) ) {
			return $merchant;
		}
		$acceptance = $merchant['data']['presigned_acceptance']['acceptance_token'] ?? '';
		$personal   = $merchant['data']['presigned_personal_data_auth']['acceptance_token'] ?? '';
		if ( ! $acceptance ) {
			return new WP_Error( 'wompi_mp_no_tokens', __( 'No fue posible obtener los tokens de aceptación de Wompi.', 'wompi-moshipp' ) );
		}
		return array(
			'acceptance_token'     => $acceptance,
			'accept_personal_auth' => $personal,
		);
	}

	/**
	 * Enlaces a los contratos legales (cacheados; los enlaces no rotan como los tokens).
	 *
	 * @return array{policy:string,personal_data:string}
	 */
	public function get_legal_permalinks(): array {
		$cache_key = 'wompi_mp_permalinks_' . md5( $this->public_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$links    = array(
			'policy'        => 'https://wompi.com/assets/downloadble/reglamento-Usuarios-Colombia.pdf',
			'personal_data' => 'https://wompi.com/assets/downloadble/autorizacion-administracion-datos-personales.pdf',
		);
		$merchant = $this->get_merchant();
		if ( ! is_wp_error( $merchant ) ) {
			$links['policy']        = $merchant['data']['presigned_acceptance']['permalink'] ?? $links['policy'];
			$links['personal_data'] = $merchant['data']['presigned_personal_data_auth']['permalink'] ?? $links['personal_data'];
			set_transient( $cache_key, $links, HOUR_IN_SECONDS );
		}
		return $links;
	}

	/**
	 * Firma de integridad: SHA256(reference + amount_in_cents + currency [+ expiration] + secret).
	 */
	public function integrity_signature( string $reference, int $amount_in_cents, string $currency = 'COP', string $expiration = '' ): string {
		return hash( 'sha256', $reference . $amount_in_cents . $currency . $expiration . $this->integrity_secret );
	}

	/**
	 * Crea una transacción. $payment_method según método (NEQUI/DAVIPLATA).
	 *
	 * @return array|WP_Error Cuerpo 'data' de la transacción creada.
	 */
	public function create_transaction( string $reference, int $amount_in_cents, string $customer_email, array $payment_method, string $redirect_url = '' ) {
		$tokens = $this->get_fresh_acceptance_tokens();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$body = array(
			'acceptance_token'     => $tokens['acceptance_token'],
			'accept_personal_auth' => $tokens['accept_personal_auth'],
			'amount_in_cents'      => $amount_in_cents,
			'currency'             => 'COP',
			'customer_email'       => $customer_email,
			'reference'            => $reference,
			'signature'            => $this->integrity_signature( $reference, $amount_in_cents ),
			'payment_method'       => $payment_method,
		);
		if ( $redirect_url ) {
			$body['redirect_url'] = $redirect_url;
		}

		$response = $this->request( 'POST', '/transactions', $body, $this->private_key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['data'];
	}

	/**
	 * Consulta una transacción por ID.
	 *
	 * @return array|WP_Error Cuerpo 'data' de la transacción.
	 */
	public function get_transaction( string $transaction_id ) {
		$response = $this->request( 'GET', '/transactions/' . rawurlencode( $transaction_id ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response['data'];
	}

	/**
	 * @param array|null  $body
	 * @param string|null $bearer Llave para el header Authorization.
	 * @return array|WP_Error JSON decodificado.
	 */
	private function request( string $method, string $path, ?array $body = null, ?string $bearer = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);
		if ( $bearer ) {
			$args['headers']['Authorization'] = 'Bearer ' . $bearer;
		}
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log( $method . ' ' . $path . ( $body ? ' body: ' . wp_json_encode( $this->redact( $body ) ) : '' ) );

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			$this->log( 'Error HTTP: ' . $response->get_error_message(), 'error' );
			return new WP_Error( 'wompi_mp_http', __( 'No fue posible comunicarse con Wompi. Intenta de nuevo.', 'wompi-moshipp' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $json ) ) {
			$message = $this->extract_error_message( $json );
			$this->log( 'Respuesta ' . $code . ': ' . wp_remote_retrieve_body( $response ), 'error' );
			return new WP_Error( 'wompi_mp_api', $message, array( 'status' => $code ) );
		}

		return $json;
	}

	private function extract_error_message( $json ): string {
		$fallback = __( 'Wompi rechazó la solicitud de pago.', 'wompi-moshipp' );
		if ( ! is_array( $json ) || empty( $json['error'] ) ) {
			return $fallback;
		}
		$messages = $json['error']['messages'] ?? null;
		if ( is_array( $messages ) ) {
			$parts = array();
			foreach ( $messages as $field => $errors ) {
				$parts[] = $field . ': ' . implode( ', ', (array) $errors );
			}
			return $fallback . ' ' . implode( ' | ', $parts );
		}
		if ( ! empty( $json['error']['reason'] ) ) {
			return $fallback . ' ' . $json['error']['reason'];
		}
		return $fallback;
	}

	/**
	 * Nunca escribir tokens/llaves completos en el log.
	 */
	private function redact( array $body ): array {
		foreach ( array( 'acceptance_token', 'accept_personal_auth', 'signature' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) ) {
				$body[ $key ] = substr( $body[ $key ], 0, 12 ) . '…';
			}
		}
		return $body;
	}

	public function log( string $message, string $level = 'info' ): void {
		if ( ! $this->logging || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'wompi-moshipp' ) );
	}
}
