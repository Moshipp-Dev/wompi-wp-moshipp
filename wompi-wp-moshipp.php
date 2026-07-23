<?php
/**
 * Plugin Name: Wompi Pagos — Nequi, Daviplata y PSE
 * Description: Acepta pagos con Nequi (notificación push), Daviplata y PSE a través de Wompi Colombia. Compatible con el checkout clásico y el checkout por bloques de WooCommerce, y con HPOS.
 * Version: 0.5.4
 * Author: Moshipp
 * Text Domain: wompi-wp-moshipp
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 9.0
 * WC tested up to: 11.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/Moshipp-Dev/wompi-wp-moshipp
 */

defined( 'ABSPATH' ) || exit;

define( 'WOMPI_MP_VERSION', '0.5.4' );
define( 'WOMPI_MP_PLUGIN_FILE', __FILE__ );
define( 'WOMPI_MP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOMPI_MP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Atribución del desarrollador. La marca es parte funcional del plugin:
 * su integridad se verifica en varios puntos (disponibilidad de los gateways
 * y creación de transacciones). Si se elimina o altera, los métodos de pago
 * se desactivan. No modificar.
 */
define( 'WOMPI_MP_BRAND_NAME', 'Moshipp' );
define( 'WOMPI_MP_BRAND_URL', 'https://moshipp.com/desarrollo-web' );
define( 'WOMPI_MP_BRAND_SIG', '4d2b317905ab541b7ea50d3df4dbc22f79f86440' );

/**
 * HTML de la marca. Usada en la pantalla de ajustes y en el checkout.
 */
function wompi_mp_brand_html( bool $checkout = false ): string {
	$link = sprintf(
		'<a href="%s" target="_blank" rel="noopener" class="wompi-mp-brand-link">%s</a>',
		esc_url( WOMPI_MP_BRAND_URL ),
		esc_html( WOMPI_MP_BRAND_NAME )
	);
	if ( $checkout ) {
		return '<p class="wompi-mp-brand">' . sprintf(
			/* translators: %s: enlace a Moshipp. */
			esc_html__( 'Integración de pagos por %s', 'wompi-wp-moshipp' ),
			$link
		) . '</p>';
	}
	return '<span class="wompi-mp-brand-admin">' . sprintf(
		/* translators: %s: enlace a Moshipp. */
		esc_html__( 'Desarrollado por %s', 'wompi-wp-moshipp' ),
		$link
	) . '</span>';
}

/**
 * Verificación de integridad de la marca. Los gateways consultan esto antes
 * de mostrarse y antes de crear cada transacción.
 */
function wompi_mp_brand_ok(): bool {
	if ( ! defined( 'WOMPI_MP_BRAND_URL' ) || ! defined( 'WOMPI_MP_BRAND_SIG' ) || ! defined( 'WOMPI_MP_BRAND_NAME' ) ) {
		return false;
	}
	if ( sha1( 'wompi-mp|' . WOMPI_MP_BRAND_URL . '|' . WOMPI_MP_BRAND_NAME ) !== WOMPI_MP_BRAND_SIG ) {
		return false;
	}
	$html = wompi_mp_brand_html( true );
	return false !== strpos( $html, 'moshipp.com/desarrollo-web' )
		&& false !== strpos( $html, 'wompi-mp-brand' );
}

/**
 * Compatibilidad con HPOS (custom order tables) y Cart/Checkout Blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'wompi_mp_init', 11 );

function wompi_mp_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'wompi_mp_missing_wc_notice' );
		return;
	}

	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-api-client.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-order-sync.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/abstract-wompi-mp-gateway.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-gateway-nequi.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-gateway-daviplata.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-gateway-pse.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-webhook.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-admin-order.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-settings-page.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-reconciler.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-emails.php';
	require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-updater.php';

	add_filter( 'woocommerce_payment_gateways', 'wompi_mp_register_gateways' );
	add_action( 'wp_enqueue_scripts', 'wompi_mp_enqueue_checkout_styles' );
	add_action( 'admin_enqueue_scripts', 'wompi_mp_enqueue_admin_assets' );
	add_action( 'wp_ajax_wompi_mp_check_credentials', array( 'Wompi_MP_Gateway', 'ajax_check_credentials' ) );

	Wompi_MP_Webhook::init();
	Wompi_MP_Order_Sync::init();
	Wompi_MP_Admin_Order::init();
	Wompi_MP_Settings_Page::init();
	Wompi_MP_Reconciler::init();
	Wompi_MP_Updater::init();
}

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'Wompi_MP_Reconciler' ) ) {
			Wompi_MP_Reconciler::unschedule();
		} else {
			wp_clear_scheduled_hook( 'wompi_mp_reconcile' );
		}
	}
);

/**
 * Estilos y JS de la pantalla de ajustes de los gateways Wompi.
 */
function wompi_mp_enqueue_admin_assets( $hook ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura para decidir si encolar assets.
	$section         = sanitize_key( wp_unslash( $_GET['section'] ?? '' ) );
	$is_gateway_page = 'woocommerce_page_wc-settings' === $hook && in_array( $section, array( 'wompi_nequi', 'wompi_daviplata', 'wompi_pse' ), true );
	$is_central_page = 'woocommerce_page_' . Wompi_MP_Settings_Page::SLUG === $hook;
	if ( ! $is_gateway_page && ! $is_central_page ) {
		return;
	}

	wp_enqueue_style( 'wompi-mp-admin', WOMPI_MP_PLUGIN_URL . 'assets/css/wompi-mp-admin.css', array(), WOMPI_MP_VERSION );
	wp_enqueue_script( 'wompi-mp-admin', WOMPI_MP_PLUGIN_URL . 'assets/js/admin.js', array(), WOMPI_MP_VERSION, true );
	wp_localize_script(
		'wompi-mp-admin',
		'wompiMpAdmin',
		array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wompi_mp_admin' ),
			'checking' => __( 'Verificando…', 'wompi-wp-moshipp' ),
			'error'    => __( 'No fue posible verificar. Intenta de nuevo.', 'wompi-wp-moshipp' ),
			'noKeys'   => __( 'No hay llaves guardadas aún. Guarda los cambios primero.', 'wompi-wp-moshipp' ),
		)
	);
}

/**
 * Estilos de los formularios de pago en el checkout (clásico y por bloques)
 * y en la página de pago de una orden pendiente.
 */
function wompi_mp_enqueue_checkout_styles() {
	if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || has_block( 'woocommerce/checkout' ) ) {
		wp_enqueue_style( 'wompi-mp', WOMPI_MP_PLUGIN_URL . 'assets/css/wompi-mp.css', array(), WOMPI_MP_VERSION );
	}
}

function wompi_mp_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'Wompi Pagos — Nequi y Daviplata requiere que WooCommerce esté instalado y activo.', 'wompi-wp-moshipp' );
	echo '</p></div>';
}

/**
 * @param string[] $gateways
 * @return string[]
 */
function wompi_mp_register_gateways( $gateways ) {
	$gateways[] = 'Wompi_MP_Gateway_Nequi';
	$gateways[] = 'Wompi_MP_Gateway_Daviplata';
	$gateways[] = 'Wompi_MP_Gateway_PSE';
	return $gateways;
}

/**
 * Registro de los métodos de pago para el Checkout por bloques.
 */
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	function ( $registry ) {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once WOMPI_MP_PLUGIN_DIR . 'includes/class-wompi-mp-blocks-support.php';
		$registry->register( new Wompi_MP_Blocks_Support( 'wompi_nequi' ) );
		$registry->register( new Wompi_MP_Blocks_Support( 'wompi_daviplata' ) );
		$registry->register( new Wompi_MP_Blocks_Support( 'wompi_pse' ) );
	}
);
