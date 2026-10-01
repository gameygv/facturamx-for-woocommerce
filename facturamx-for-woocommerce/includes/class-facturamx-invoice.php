<?php
/**
 * Qué queda escrito en el pedido después de un timbrado correcto.
 *
 * La clase existe separada del metabox por dos razones:
 *
 *   - `meta_from_response()` y `note_text()` son puras (decisión D8): se prueban
 *     enteras con `php tests/run-tests.php` sin gastar un timbre. Es la única
 *     forma de saber que la persistencia funciona antes del primer timbrado real.
 *   - S1.5 escribirá exactamente lo mismo desde el portal público. Si esto
 *     viviera dentro del handler AJAX del admin, el portal tendría que copiarlo,
 *     y dos copias de «qué significa estar facturado» se separan tarde o
 *     temprano.
 *
 * Regla que gobierna toda la clase (decisión D7): la meta se escribe SOLO en
 * éxito. No hay estado «intento fallido». Tras un timeout nadie sabe si la
 * factura se emitió, y marcar el pedido como fallido sería afirmar algo que el
 * plugin no sabe. El estado es binario y observable: el pedido tiene
 * `_facturamx_uuid` o no lo tiene.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Invoice {

	/**
	 * Las siete claves de meta, en el orden en que se escriben.
	 *
	 * Se declaran una sola vez porque las consumen el extractor y el lector. Si
	 * divergieran, `persist()` escribiría una cosa y `stamped_data()` leería
	 * otra — y el fallo aparecería en el peor sitio posible: un pedido facturado
	 * que el plugin cree sin facturar.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		return array(
			'_facturamx_uuid',
			'_facturamx_invoice_id',
			'_facturamx_folio',
			'_facturamx_series',
			'_facturamx_pdf_url',
			'_facturamx_xml_url',
			'_facturamx_stamped_at',
		);
	}

	/**
	 * Traduce la respuesta de /api/public/invoice a la meta del pedido.
	 *
	 * Pura: no toca el pedido, ni la base de datos, ni la red.
	 *
	 * @param array $response Cuerpo de la respuesta ya decodificado.
	 * @return array|WP_Error Meta lista para escribir, o error si no hay UUID.
	 */
	public static function meta_from_response( $response ) {
		$response = is_array( $response ) ? $response : array();

		$uuid = isset( $response['uuid'] ) ? trim( (string) $response['uuid'] ) : '';

		// Un 200 sin UUID no es una factura. Misma regla que FacturaMX_Client::classify()
		// y por la misma razón: escribir meta de una factura que no existe deja el
		// pedido marcado como facturado para siempre, y nadie vuelve a mirarlo.
		if ( '' === $uuid ) {
			return new WP_Error(
				'facturamx_no_uuid',
				__( 'El servidor respondió sin UUID: no hay factura que registrar.', 'facturamx-for-woocommerce' )
			);
		}

		return array(
			'_facturamx_uuid'       => $uuid,
			'_facturamx_invoice_id' => self::text( $response, 'invoice_id' ),
			// El folio se guarda como cadena a propósito. La meta de WordPress no
			// conserva el tipo: leerla devuelve '1', y un === contra 1 más
			// adelante daría un falso negativo. Se guarda ya como lo que se leerá.
			'_facturamx_folio'      => self::text( $response, 'folio' ),
			// La serie la pone la organización y puede no volver. Que falte no es
			// un error: es una factura sin serie.
			'_facturamx_series'     => self::text( $response, 'series' ),
			'_facturamx_pdf_url'    => self::text( $response, 'pdf_url' ),
			'_facturamx_xml_url'    => self::text( $response, 'xml_url' ),
			// La API no manda fecha de timbrado. Esta es la hora del sitio, no la
			// del SAT: sirve para saber cuándo lo hizo el operador, no para nada
			// fiscal. Lo fiscal está en el XML.
			'_facturamx_stamped_at' => (string) current_time( 'mysql' ),
		);
	}

	/**
	 * Texto de la nota que se añade al pedido.
	 *
	 * Pura. Distingue emitida de recuperada porque la diferencia importa: si
	 * alguien lee «emitida» dos veces en el historial de un pedido, va a pensar
	 * que se timbró dos veces y va a ir a cancelar una factura buena.
	 *
	 * `deduplicated` no se guarda en la meta — es información sobre ESTA
	 * petición, no sobre la factura. La misma factura recuperada mañana no
	 * estaría duplicada. Pero sí se cuenta en la nota, que es un diario de lo
	 * que pasó, no un estado.
	 *
	 * @param array $response Cuerpo de la respuesta ya decodificado.
	 * @return string
	 */
	public static function note_text( $response ) {
		$response = is_array( $response ) ? $response : array();

		$uuid   = self::text( $response, 'uuid' );
		$series = self::text( $response, 'series' );
		$folio  = self::text( $response, 'folio' );

		$referencia = '';

		if ( '' !== $series && '' !== $folio ) {
			$referencia = $series . '-' . $folio;
		} elseif ( '' !== $folio ) {
			$referencia = $folio;
		}

		$deduplicada = ! empty( $response['deduplicated'] );

		if ( $deduplicada ) {
			$texto = __( 'Factura recuperada: ya existía en FacturaMX para este pedido y no se ha vuelto a timbrar.', 'facturamx-for-woocommerce' );
		} else {
			$texto = __( 'Factura emitida en FacturaMX.', 'facturamx-for-woocommerce' );
		}

		if ( '' !== $referencia ) {
			$texto .= ' ' . sprintf(
				/* translators: %s: serie y folio, por ejemplo WEB-1. */
				__( 'Serie y folio: %s.', 'facturamx-for-woocommerce' ),
				$referencia
			);
		}

		$texto .= ' ' . sprintf(
			/* translators: %s: UUID fiscal del CFDI. */
			__( 'UUID: %s', 'facturamx-for-woocommerce' ),
			$uuid
		);

		return $texto;
	}

	/**
	 * ¿Este pedido ya tiene factura?
	 *
	 * Es la guarda de servidor contra el doble timbrado (una de las tres capas
	 * de la decisión D6). No sustituye a las otras dos: para la carrera entre
	 * dos peticiones simultáneas está `external_id` en la API.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function is_stamped( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}

		return '' !== trim( (string) $order->get_meta( '_facturamx_uuid', true ) );
	}

	/**
	 * Devuelve las siete metas del pedido, vacías si no está facturado.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	public static function stamped_data( $order ) {
		$data = array();

		foreach ( self::meta_keys() as $key ) {
			$data[ $key ] = ( is_object( $order ) && method_exists( $order, 'get_meta' ) )
				? (string) $order->get_meta( $key, true )
				: '';
		}

		return $data;
	}

	/**
	 * Si el pedido se facturó por otra vía (panel de FacturaMX, cotización del
	 * POS), trae esa factura y la guarda en el pedido como si la hubiera emitido
	 * el plugin. Así el portal ofrece la descarga en vez de un formulario.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool true si el pedido queda facturado tras la consulta.
	 */
	public static function sync_from_api( $order ) {
		if ( ! is_object( $order ) || self::is_stamped( $order ) ) {
			return self::is_stamped( $order );
		}
		$found = FacturaMX_Client::find_invoice( (string) $order->get_id() );
		if ( null === $found ) {
			return false;
		}
		$meta = self::persist( $order, $found );
		if ( is_wp_error( $meta ) ) {
			return false;
		}
		$order->add_order_note(
			__( 'FacturaMX: este pedido ya se había facturado fuera del portal (en el panel o desde una cotización). Se enlazó la factura existente.', 'facturamx-for-woocommerce' ),
			false
		);
		return true;
	}

	/**
	 * Escribe la meta y añade la nota al pedido.
	 *
	 * Las tres líneas de escritura que faltaban. No valida nada que no haya
	 * validado ya `meta_from_response()`: si esa devuelve error, aquí no se toca
	 * el pedido.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param array    $response Cuerpo de la respuesta ya decodificado.
	 * @return array|WP_Error La meta escrita, o el error del extractor.
	 */
	public static function persist( $order, $response ) {
		$meta = self::meta_from_response( $response );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();
		$order->add_order_note( self::note_text( $response ), false );

		return $meta;
	}

	/**
	 * Lee una clave de la respuesta como cadena. Ausente y vacía dan lo mismo:
	 * cadena vacía. Es lo que se va a leer de la meta de todas formas.
	 *
	 * @param array  $response Respuesta.
	 * @param string $key      Clave.
	 * @return string
	 */
	private static function text( $response, $key ) {
		if ( ! isset( $response[ $key ] ) || is_array( $response[ $key ] ) ) {
			return '';
		}

		return trim( (string) $response[ $key ] );
	}
}
