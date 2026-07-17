<?php
/**
 * Reconciliación programada: cada 15 minutos sincroniza contra el API de Wompi
 * las órdenes pendientes recientes. Cubre el caso en que el cliente paga pero
 * cierra el navegador y el webhook no llega (sitio caído, reintentos agotados).
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Reconciler {

	const HOOK     = 'wompi_mp_reconcile';
	const INTERVAL = 'wompi_mp_15min';

	/** Máximo de órdenes a revisar por corrida, para no cargar el cron. */
	const BATCH_LIMIT = 25;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 min es razonable para reconciliación.
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function register_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 15 minutos (reconciliación Wompi)', 'wompi-moshipp' ),
		);
		return $schedules;
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Sincroniza las órdenes Wompi pendientes de las últimas 24 horas.
	 *
	 * @return array{checked:int,updated:int} Resumen de la corrida.
	 */
	public static function run(): array {
		$checked = 0;
		$updated = 0;

		foreach ( array( 'wompi_nequi', 'wompi_daviplata', 'wompi_pse' ) as $method ) {
			$orders = wc_get_orders(
				array(
					'limit'          => self::BATCH_LIMIT,
					'status'         => array( 'pending', 'on-hold' ),
					'payment_method' => $method,
					'date_created'   => '>' . ( time() - DAY_IN_SECONDS ),
					'orderby'        => 'date',
					'order'          => 'ASC',
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order || ! $order->get_meta( Wompi_MP_Order_Sync::META_TX_ID ) ) {
					continue;
				}
				$checked++;
				$previous = $order->get_status();
				$status   = Wompi_MP_Order_Sync::refresh_from_api( $order );
				if ( null !== $status && $order->get_status() !== $previous ) {
					$updated++;
					self::log( sprintf( 'Orden #%d reconciliada: %s → %s (Wompi %s)', $order->get_id(), $previous, $order->get_status(), $status ) );
				}
			}
		}

		if ( $checked > 0 ) {
			self::log( sprintf( 'Corrida de reconciliación: %d revisadas, %d actualizadas.', $checked, $updated ) );
		}

		return array(
			'checked' => $checked,
			'updated' => $updated,
		);
	}

	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( '[reconciliador] ' . $message, array( 'source' => 'wompi-moshipp' ) );
		}
	}
}
