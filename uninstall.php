<?php
/**
 * Limpieza al desinstalar el plugin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wompi_mp_credentials' );
delete_option( 'woocommerce_wompi_nequi_settings' );
delete_option( 'woocommerce_wompi_daviplata_settings' );

// Transients de permalinks legales (llave con hash de la llave pública).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wompi_mp_%' OR option_name LIKE '_transient_timeout_wompi_mp_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
