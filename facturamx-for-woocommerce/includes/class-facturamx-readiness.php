<?php
/**
 * Preparación para timbrar (S2.4).
 *
 * Un comercio no debe llegar a su primer timbre a medio configurar: con un
 * token que nadie ha probado o con productos que llevarían la clave comodín
 * 01010101 («No existe en el catálogo»). La clave comodín timbra igual y cuadra
 * igual —solo se ve leyendo el XML—, así que el plugin tiene que pararlo antes.
 *
 * La decisión es pura (`fingerprint()`, `blocking_reasons()`) y se prueba con
 * `php tests/run-tests.php`. Lo que toca WordPress (opción, consulta de
 * productos) queda en métodos finos.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Readiness {

	/** Opción con la huella de la última conexión probada con éxito. */
	const OPTION = 'facturamx_connection_verified';

	/** ClaveProdServ comodín del SAT: «No existe en el catálogo». */
	const WILDCARD_KEY = '01010101';

	/**
	 * Huella de una combinación URL + token. Cambiar cualquiera de los dos
	 * invalida la prueba de conexión anterior. No contiene el token.
	 *
	 * @param string $url   URL de la API.
	 * @param string $token Token de la API.
	 * @return string
	 */
	public static function fingerprint( $url, $token ) {
		return hash( 'sha256', rtrim( (string) $url, '/' ) . '|' . (string) $token );
	}

	/**
	 * Motivos por los que NO se puede timbrar este payload. Vacío = adelante.
	 * Pensados para el administrador de la tienda: dicen qué hacer.
	 *
	 * @param array $payload  Payload de FacturaMX_Order_Mapper::build().
	 * @param bool  $verified ¿Se probó la conexión con la URL y el token actuales?
	 * @return string[]
	 */
	public static function blocking_reasons( $payload, $verified ) {
		$reasons = array();

		if ( ! $verified ) {
			$reasons[] = __( 'La conexión con FacturaMX no se ha comprobado con el token actual. Ve a WooCommerce → FacturaMX y pulsa «Probar conexión».', 'facturamx-for-woocommerce' );
		}

		$without_key = array();
		$items       = is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['product_key'] ) && self::WILDCARD_KEY === (string) $item['product_key'] ) {
				$without_key[] = isset( $item['description'] ) ? (string) $item['description'] : '?';
			}
		}

		if ( $without_key ) {
			$reasons[] = sprintf(
				/* translators: %s: lista de productos separados por comas. */
				__( 'Estos productos no tienen clave del SAT propia y saldrían con la comodín 01010101: %s. Asígnasela en la pestaña «FacturaMX» de cada producto.', 'facturamx-for-woocommerce' ),
				implode( ', ', array_unique( $without_key ) )
			);
		}

		return $reasons;
	}

	/**
	 * ¿La URL y el token actuales se probaron con éxito?
	 *
	 * @return bool
	 */
	public static function is_verified() {
		$stored = get_option( self::OPTION, '' );
		$now    = self::fingerprint( FacturaMX_Settings::get( 'api_url' ), FacturaMX_Settings::get( 'api_token' ) );

		return is_string( $stored ) && '' !== $stored && hash_equals( $stored, $now );
	}

	/**
	 * Anota que la URL y el token actuales funcionan (tras «Probar conexión»).
	 */
	public static function mark_verified() {
		update_option(
			self::OPTION,
			self::fingerprint( FacturaMX_Settings::get( 'api_url' ), FacturaMX_Settings::get( 'api_token' ) ),
			false
		);
	}

	/**
	 * Freno antes de timbrar: WP_Error si falta algo, true si se puede.
	 *
	 * @param array $payload Payload a timbrar.
	 * @return true|WP_Error
	 */
	public static function check( $payload ) {
		$reasons = self::blocking_reasons( $payload, self::is_verified() );

		if ( $reasons ) {
			return new WP_Error( 'facturamx_setup_incomplete', implode( ' ', $reasons ) );
		}

		return true;
	}

	/**
	 * Productos publicados cuya factura saldría con la clave comodín: sin clave
	 * propia (o con la comodín) mientras la clave por defecto es la comodín.
	 * Para el panel de ajustes.
	 *
	 * @param int $limit Máximo de productos a devolver.
	 * @return array{count: int, products: array<int, array{id: int, name: string, edit: string}>}
	 */
	public static function products_without_key( $limit = 20 ) {
		if ( self::WILDCARD_KEY !== (string) FacturaMX_Settings::get( 'default_product_key' ) ) {
			// Con una clave por defecto real, ningún producto cae en la comodín.
			return array( 'count' => 0, 'products' => array() );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- solo en la pantalla de ajustes.
					'relation' => 'OR',
					array(
						'key'     => FacturaMX_Order_Mapper::META_PRODUCT_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => FacturaMX_Order_Mapper::META_PRODUCT_KEY,
						'value' => array( '', self::WILDCARD_KEY ),
						'compare' => 'IN',
					),
				),
			)
		);

		$products = array();
		foreach ( $query->posts as $id ) {
			$products[] = array(
				'id'   => (int) $id,
				'name' => get_the_title( $id ),
				'edit' => (string) get_edit_post_link( $id, 'raw' ),
			);
		}

		return array(
			'count'    => (int) $query->found_posts,
			'products' => $products,
		);
	}
}
