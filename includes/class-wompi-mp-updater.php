<?php
/**
 * Actualizaciones automáticas desde GitHub Releases.
 * WordPress consulta este filtro gracias al header "Update URI" del plugin.
 */

defined( 'ABSPATH' ) || exit;

class Wompi_MP_Updater {

	const REPO  = 'Moshipp-Dev/wompi-wp-moshipp';
	const ASSET = 'wompi-wp-moshipp.zip';

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
	}

	/**
	 * @param array|false $update
	 * @param array       $plugin_data Cabeceras del plugin.
	 * @param string      $plugin_file plugin_basename del plugin evaluado.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( WOMPI_MP_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		$version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' );
		if ( '' === $version || ! version_compare( $version, WOMPI_MP_VERSION, '>' ) ) {
			return $update;
		}

		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			// Sin el zip empaquetado no se ofrece actualización: el zipball de
			// GitHub cambia el nombre de la carpeta y rompería la instalación.
			return $update;
		}

		return array(
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => dirname( $plugin_file ),
			'version' => $version,
			'url'     => 'https://github.com/' . self::REPO,
			'package' => $package,
		);
	}

	/**
	 * Último release publicado, cacheado 6 horas.
	 */
	private static function latest_release(): ?array {
		$cache_key = 'wompi_mp_latest_release';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'wompi-wp-moshipp',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) ) {
			return null;
		}

		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}
}
