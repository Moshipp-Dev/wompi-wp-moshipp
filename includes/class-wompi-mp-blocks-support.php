<?php
/**
 * Integración con el Checkout por bloques de WooCommerce.
 * Una instancia por gateway (wompi_nequi / wompi_daviplata).
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Wompi_MP_Blocks_Support extends AbstractPaymentMethodType {

	public function __construct( string $gateway_id ) {
		$this->name = $gateway_id;
	}

	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
	}

	private function get_gateway(): ?Wompi_MP_Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ $this->name ] ?? null;
		return $gateway instanceof Wompi_MP_Gateway ? $gateway : null;
	}

	public function is_active() {
		$gateway = $this->get_gateway();
		return $gateway ? $gateway->is_available() : false;
	}

	public function get_payment_method_script_handles() {
		if ( ! wp_script_is( 'wompi-mp-blocks', 'registered' ) ) {
			wp_register_script(
				'wompi-mp-blocks',
				WOMPI_MP_PLUGIN_URL . 'assets/js/blocks.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				WOMPI_MP_VERSION,
				true
			);
		}
		return array( 'wompi-mp-blocks' );
	}

	public function get_payment_method_data() {
		$gateway = $this->get_gateway();
		$links   = $gateway
			? $gateway->api()->get_legal_permalinks()
			: array(
				'policy'        => '',
				'personal_data' => '',
			);

		return array(
			'name'        => $this->name,
			'title'       => $this->get_setting( 'title', $this->name ),
			'description' => $this->get_setting( 'description', '' ),
			'supports'    => $gateway ? array_filter( $gateway->supports, array( $gateway, 'supports' ) ) : array(),
			'brandHtml'   => function_exists( 'wompi_mp_brand_html' ) ? wompi_mp_brand_html( true ) : '',
			'icon'        => $gateway ? $gateway->icon : '',
			'legalLinks'  => $links,
			'docTypes'    => in_array( $this->name, array( 'wompi_daviplata', 'wompi_pse' ), true ) ? Wompi_MP_Gateway_Daviplata::DOC_TYPES : new stdClass(),
			'userTypes'   => 'wompi_pse' === $this->name ? Wompi_MP_Gateway_PSE::USER_TYPES : new stdClass(),
			'banks'       => 'wompi_pse' === $this->name && $gateway instanceof Wompi_MP_Gateway_PSE ? $gateway->get_banks() : array(),
			'i18n'        => array(
				'bankLabel'       => __( 'Banco', 'wompi-moshipp' ),
				'bankPlaceholder' => __( '— Selecciona tu banco —', 'wompi-moshipp' ),
				'bankInvalid'     => __( 'Selecciona tu banco e ingresa un documento válido para pagar con PSE.', 'wompi-moshipp' ),
				'userTypeLabel'   => __( 'Tipo de persona', 'wompi-moshipp' ),
				'phoneLabel'      => __( 'Número de celular Nequi', 'wompi-moshipp' ),
				'phoneInvalid'    => __( 'Ingresa un número de celular Nequi válido (10 dígitos, inicia por 3).', 'wompi-moshipp' ),
				'docTypeLabel'    => __( 'Tipo de documento', 'wompi-moshipp' ),
				'docNumberLabel'  => __( 'Número de documento', 'wompi-moshipp' ),
				'docInvalid'      => __( 'Ingresa un tipo y número de documento válidos para Daviplata.', 'wompi-moshipp' ),
				'acceptPrefix'    => __( 'Acepto el', 'wompi-moshipp' ),
				'policyText'      => __( 'reglamento de usuarios', 'wompi-moshipp' ),
				'acceptMiddle'    => __( 'y la', 'wompi-moshipp' ),
				'personalText'    => __( 'autorización de tratamiento de datos personales', 'wompi-moshipp' ),
				'acceptSuffix'    => __( 'de Wompi.', 'wompi-moshipp' ),
				'acceptRequired'  => __( 'Debes aceptar el reglamento y la autorización de datos de Wompi.', 'wompi-moshipp' ),
			),
		);
	}
}
