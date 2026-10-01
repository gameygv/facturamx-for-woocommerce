<?php
/**
 * Cliente HTTP de la API pública de FacturaMX.
 *
 * Es el único sitio del plugin que habla con el exterior. Su valor no está en
 * envolver wp_remote_post, sino en tres cosas que no son pass-through:
 *
 *   1. La política de reintento asimétrica: solo 429 y 502, una vez.
 *   2. La traducción de código HTTP a un error accionable en castellano.
 *   3. El tratamiento de `deduplicated: true` como éxito.
 *
 * Ese criterio vive en classify(), que es una función PURA: recibe el código, el
 * cuerpo ya decodificado y el Retry-After en segundos, y devuelve qué hacer. No
 * toca la red. Así la tabla entera se prueba en el runner sin fingir respuestas
 * HTTP — el mismo antipatrón que S1.2 evitó extrayendo build() (decisión D1).
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Client {

	/**
	 * Tope de espera antes de reintentar un 429, en segundos.
	 *
	 * PHP mata la petición al llegar a max_execution_time (30 s típico).
	 * Obedecer un Retry-After de 55 s garantizaría un 500 de WordPress en vez
	 * de un mensaje útil, así que por encima de este tope se falla sin esperar.
	 */
	const MAX_RETRY_WAIT = 20;

	/** Espera por defecto de un 429 que no trae cabecera Retry-After. */
	const DEFAULT_RETRY_WAIT = 5;

	/** Espera fija antes de reintentar un 502. */
	const UPSTREAM_RETRY_WAIT = 2;

	/**
	 * Timeout para timbrar, en segundos.
	 *
	 * Timbrar atraviesa FacturaMX, Facturapi y el PAC. Los 5 s por defecto de
	 * WordPress cortarían timbrados en curso de forma rutinaria (decisión D7).
	 */
	const STAMP_TIMEOUT = 30;

	/** Timeout para leer catálogos. Es una lectura cacheada por CDN. */
	const CATALOGS_TIMEOUT = 15;

	/** Transient donde viven los catálogos y cuánto duran (decisión D8). */
	const CATALOGS_TRANSIENT = 'facturamx_catalogs';
	const CATALOGS_TTL       = DAY_IN_SECONDS;

	const PATH_INVOICE  = '/api/public/invoice';
	const PATH_QUOTATION = '/api/public/quotation';
	const PATH_CATALOGS = '/api/public/catalogs';

	/**
	 * Une la base configurada con una ruta, sin duplicar ni perder la barra.
	 *
	 * La base la escribe una persona en un campo de texto: puede llegar con
	 * espacios, con barra final o con dos. Función pura.
	 *
	 * @param string $base Base de la API tal y como está guardada.
	 * @param string $path Ruta del endpoint.
	 * @return string URL completa, o cadena vacía si no hay base.
	 */
	public static function endpoint( $base, $path ) {
		$base = rtrim( trim( (string) $base ), '/' );

		if ( '' === $base ) {
			return '';
		}

		return $base . '/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Lee Retry-After de un array de cabeceras y lo devuelve en segundos.
	 *
	 * La RFC permite además un formato de fecha HTTP. FacturaMX manda siempre
	 * segundos (verificado en src/lib/api/rate-limit.ts), así que la fecha no
	 * se interpreta: se devuelve null y el cliente usa su espera por defecto.
	 * Adivinar mal una fecha sería peor que no leerla. Función pura.
	 *
	 * wp_remote_retrieve_headers() no devuelve un array plano sino el
	 * diccionario case-insensitive de Requests. Un cast a array sobre él
	 * expondría sus propiedades protegidas, no las cabeceras, así que primero
	 * se normaliza con getAll().
	 *
	 * @param array|object $headers Cabeceras de la respuesta.
	 * @return int|null Segundos, o null si no hay un valor utilizable.
	 */
	public static function retry_after_from( $headers ) {
		$value = null;

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return null;
		}

		foreach ( $headers as $name => $raw ) {
			if ( 'retry-after' === strtolower( (string) $name ) ) {
				$value = is_array( $raw ) ? reset( $raw ) : $raw;
				break;
			}
		}

		if ( null === $value || 1 !== preg_match( '/^[0-9]+$/', trim( (string) $value ) ) ) {
			return null;
		}

		$seconds = (int) $value;

		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * Timbra una factura. Es el único método que puede gastar un timbre.
	 *
	 * Delgado sobre classify(): hace la petición, extrae código, cuerpo y
	 * Retry-After, pregunta qué hacer y obedece. Un solo reintento como mucho
	 * (decisión D4): cada reintento extra es una forma más de emitir una
	 * factura de más.
	 *
	 * @param array $payload Payload construido por FacturaMX_Order_Mapper::build().
	 * @return array|WP_Error Cuerpo de la respuesta, o el error ya traducido.
	 */
	public static function stamp( array $payload ) {
		// S2.4: a medio configurar no se timbra. Va AQUÍ y no en cada pantalla
		// porque el portal y el admin llegan los dos a este método: validar en
		// una sola pantalla dejaría abierta la otra. Sale antes de cualquier
		// petición, así que no gasta timbres.
		$ready = FacturaMX_Readiness::check( $payload );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$args = array(
			'timeout' => self::STAMP_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . (string) FacturaMX_Settings::get( 'api_token' ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);

		$attempts = 0;

		while ( true ) {
			++$attempts;

			$response = self::post( self::PATH_INVOICE, $args );

			// Fallo de transporte: no se sabe si la petición llegó. No se
			// reintenta solo (decisión D3, Jidoka) — se avisa y decide el
			// usuario, que puede reintentar sin duplicar porque el servidor
			// deduplica por external_id.
			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'facturamx_transport_error',
					sprintf(
						/* translators: %s: mensaje de error de transporte. */
						__( 'No se pudo contactar con FacturaMX: %s. La factura PODRÍA haberse emitido de todos modos: vuelve a intentarlo, no se duplicará.', 'facturamx-for-woocommerce' ),
						$response->get_error_message()
					)
				);
			}

			$verdict = self::classify(
				wp_remote_retrieve_response_code( $response ),
				json_decode( wp_remote_retrieve_body( $response ), true ),
				self::retry_after_from( wp_remote_retrieve_headers( $response ) )
			);

			if ( 'return' === $verdict['action'] ) {
				return $verdict['data'];
			}

			if ( 'retry' === $verdict['action'] && $attempts < 2 ) {
				sleep( $verdict['wait'] );
				continue;
			}

			return new WP_Error( $verdict['code'], $verdict['message'] );
		}
	}

	/**
	 * Manda el pedido a FacturaMX como cotización en borrador. No timbra nada.
	 * Idempotente en el servidor por external_id: repetirlo devuelve la misma.
	 *
	 * @param array $body Salida de FacturaMX_Order_Mapper::to_quotation().
	 * @return array|WP_Error quotation_id, quote_number, quote_url…
	 */
	public static function send_quotation( array $body ) {
		$response = self::post(
			self::PATH_QUOTATION,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) FacturaMX_Settings::get( 'api_token' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'facturamx_transport_error', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ( 200 === $status || 201 === $status ) && is_array( $data ) && ! empty( $data['quotation_id'] ) ) {
			return $data;
		}
		$message = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : sprintf( 'HTTP %d', $status );
		return new WP_Error( 'facturamx_quote_failed', $message );
	}

	/**
	 * ¿Este pedido ya tiene factura en FacturaMX, hecha por otra vía?
	 *
	 * El comercio pudo facturarlo en el panel o convirtiendo la cotización que
	 * mandó su POS. Se consulta por external_id (el id del pedido) antes de
	 * ofrecer el formulario. Cualquier fallo devuelve null y el flujo sigue
	 * como siempre: el POST de timbrado deduplica por external_id igualmente.
	 *
	 * @param string $external_id Id del pedido.
	 * @return array|null Respuesta con uuid, invoice_id, serie, folio y URLs; null si no hay.
	 */
	public static function find_invoice( $external_id ) {
		$url = self::endpoint( FacturaMX_Settings::get( 'api_url' ), self::PATH_INVOICE );
		if ( '' === $url || '' === (string) $external_id ) {
			return null;
		}

		$response = wp_remote_get(
			add_query_arg( 'external_id', rawurlencode( (string) $external_id ), $url ),
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . (string) FacturaMX_Settings::get( 'api_token' ) ),
			)
		);

		return self::interpret_lookup(
			self::status_of( $response ),
			is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true )
		);
	}

	/**
	 * Parte pura de find_invoice(): solo una factura vigente y con UUID cuenta.
	 *
	 * @param int|string $status Código HTTP, o mensaje si no hubo respuesta.
	 * @param mixed      $body   Cuerpo decodificado.
	 * @return array|null
	 */
	public static function interpret_lookup( $status, $body ) {
		if ( 200 !== $status || ! is_array( $body ) ) {
			return null;
		}
		if ( '' === trim( (string) ( isset( $body['uuid'] ) ? $body['uuid'] : '' ) ) ) {
			return null;
		}
		if ( isset( $body['invoice_status'] ) && 'cancelled' === $body['invoice_status'] ) {
			return null;
		}
		return $body;
	}

	/**
	 * Catálogos del SAT para poblar los selectores del formulario público.
	 *
	 * El endpoint no lleva token y declara max-age=86400; la caché local se
	 * alinea con esa ventana (decisión D8). Un fallo de red NO invalida lo que
	 * ya hubiera cacheado: se prefiere un catálogo de ayer a un formulario sin
	 * selectores.
	 *
	 * @param bool $force Fuerza el refresco saltándose la caché.
	 * @return array|WP_Error
	 */
	public static function catalogs( $force = false ) {
		$cached = get_transient( self::CATALOGS_TRANSIENT );

		if ( ! $force && is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$url = self::endpoint( FacturaMX_Settings::get( 'api_url' ), self::PATH_CATALOGS );

		if ( '' === $url ) {
			return new WP_Error( 'facturamx_not_configured', __( 'Falta la URL de la API en los ajustes del plugin.', 'facturamx-for-woocommerce' ) );
		}

		$response = wp_remote_get( $url, array( 'timeout' => self::CATALOGS_TIMEOUT ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Si hay caché vieja, sirve. Vale más que un formulario vacío.
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}

			return new WP_Error(
				'facturamx_catalogs_unavailable',
				__( 'No se pudieron obtener los catálogos del SAT. Inténtalo de nuevo en unos minutos.', 'facturamx-for-woocommerce' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['tax_regimes'], $body['cfdi_uses'], $body['payment_forms'] ) ) {
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}

			return new WP_Error(
				'facturamx_catalogs_unavailable',
				__( 'La respuesta de catálogos no tiene el formato esperado.', 'facturamx-for-woocommerce' )
			);
		}

		set_transient( self::CATALOGS_TRANSIENT, $body, self::CATALOGS_TTL );

		return $body;
	}

	/**
	 * Sonda de conexión para la pantalla de ajustes.
	 *
	 * Devuelve los tres datos crudos que interpret_ping() sabe traducir: el
	 * código del GET a catálogos, el del POST a facturas y el recuento de
	 * catálogos. Vive aquí y no en la pantalla porque es la pantalla la que no
	 * debe saber construir peticiones (decisión D6).
	 *
	 * **No gasta un timbre:** el POST lleva cuerpo vacío y el endpoint valida
	 * el token antes de parsearlo, así que nunca llega a Facturapi.
	 *
	 * @return array{catalogs: int|string, invoice: int|string|null, counts: array<string, int>}
	 */
	public static function probe() {
		$base  = (string) FacturaMX_Settings::get( 'api_url' );
		$token = (string) FacturaMX_Settings::get( 'api_token' );

		$catalogs_response = wp_remote_get(
			self::endpoint( $base, self::PATH_CATALOGS ),
			array( 'timeout' => self::CATALOGS_TIMEOUT )
		);

		$catalogs = self::status_of( $catalogs_response );
		$counts   = self::count_catalogs( $catalogs_response );
		$invoice  = null;

		if ( 200 === $catalogs && ! empty( $counts ) ) {
			$invoice = self::status_of(
				self::post(
					self::PATH_INVOICE,
					array(
						'timeout' => self::CATALOGS_TIMEOUT,
						'headers' => array(
							'Authorization' => 'Bearer ' . $token,
							'Content-Type'  => 'application/json',
						),
						'body'    => '{}',
					)
				)
			);
		}

		return array(
			'catalogs' => $catalogs,
			'invoice'  => $invoice,
			'counts'   => $counts,
		);
	}

	/**
	 * POST a un endpoint de la API. Único punto del plugin que publica datos.
	 *
	 * @param string $path Ruta del endpoint.
	 * @param array  $args Argumentos para wp_remote_post.
	 * @return array|WP_Error
	 */
	private static function post( $path, array $args ) {
		$url = self::endpoint( FacturaMX_Settings::get( 'api_url' ), $path );

		if ( '' === $url ) {
			return new WP_Error( 'facturamx_not_configured', __( 'Falta la URL de la API en los ajustes del plugin.', 'facturamx-for-woocommerce' ) );
		}

		return wp_remote_post( $url, $args );
	}

	/**
	 * Código HTTP de una respuesta, o el mensaje de error si no hubo respuesta.
	 *
	 * La mezcla de tipos es deliberada: es exactamente lo que interpret_ping()
	 * espera desde S1.1, y su firma no se toca.
	 *
	 * @param array|WP_Error $response Respuesta de wp_remote_*.
	 * @return int|string
	 */
	private static function status_of( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Cuenta las entradas de cada catálogo. Vacío si la respuesta no tiene la
	 * forma que devuelve FacturaMX.
	 *
	 * @param array|WP_Error $response Respuesta de wp_remote_get.
	 * @return array<string, int>
	 */
	private static function count_catalogs( $response ) {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['tax_regimes'], $body['cfdi_uses'] ) ) {
			return array();
		}

		return array(
			'tax_regimes' => count( (array) $body['tax_regimes'] ),
			'cfdi_uses'   => count( (array) $body['cfdi_uses'] ),
		);
	}

	/**
	 * Decide qué hacer con una respuesta de la API. Función pura.
	 *
	 * Se decide por CÓDIGO HTTP, nunca por el texto del mensaje (decisión D2).
	 * El servidor ya clasifica por texto en statusForCoreError(); replicar esa
	 * fragilidad aquí haría que un cambio de redacción rompiera el plugin en
	 * silencio. El texto de la API se propaga al usuario, no se inspecciona.
	 *
	 * @param int        $status      Código HTTP de la respuesta.
	 * @param array|null $body        Cuerpo ya decodificado, o null si no se pudo decodificar.
	 * @param int|null   $retry_after Valor de la cabecera Retry-After en segundos, si venía.
	 * @return array {
	 *     @type string $action  'return' | 'retry' | 'fail'.
	 *     @type array  $data    Solo en 'return': el cuerpo tal cual.
	 *     @type int    $wait    Solo en 'retry': segundos a esperar.
	 *     @type string $code    En 'retry' y 'fail': código de error del plugin.
	 *     @type string $message En 'retry' y 'fail': mensaje para el usuario.
	 * }
	 */
	public static function classify( $status, $body, $retry_after = null ) {
		$status = (int) $status;
		$body   = is_array( $body ) ? $body : array();
		$api    = isset( $body['error'] ) ? trim( (string) $body['error'] ) : '';

		// Un 200 sin uuid no es un timbrado, por mucho que el código lo diga.
		if ( 200 === $status ) {
			if ( empty( $body['uuid'] ) ) {
				return self::fail( 'facturamx_unexpected_response', self::message( 'facturamx_unexpected_response', $api ) );
			}

			// deduplicated: true llega intacto — S1.4 y S1.5 lo usan para decir
			// «esta factura ya existía» en vez de «facturado» a secas (D5).
			return array(
				'action' => 'return',
				'data'   => $body,
			);
		}

		if ( 429 === $status ) {
			$wait = null === $retry_after ? self::DEFAULT_RETRY_WAIT : (int) $retry_after;

			// Por encima del tope no se espera: se falla ya, con un mensaje útil.
			if ( $wait < 1 || $wait > self::MAX_RETRY_WAIT ) {
				return self::fail( 'facturamx_rate_limited', self::message( 'facturamx_rate_limited', $api ) );
			}

			return array(
				'action'  => 'retry',
				'wait'    => $wait,
				'code'    => 'facturamx_rate_limited',
				'message' => self::message( 'facturamx_rate_limited', $api ),
			);
		}

		if ( 502 === $status ) {
			return array(
				'action'  => 'retry',
				'wait'    => self::UPSTREAM_RETRY_WAIT,
				'code'    => 'facturamx_upstream_error',
				'message' => self::message( 'facturamx_upstream_error', $api ),
			);
		}

		// Todo lo demás falla sin reintentar. Lo desconocido tampoco se
		// reintenta: no se sabe qué pasó, y reintentar a ciegas es la forma
		// más fácil de emitir una factura de más.
		$codes = array(
			401 => 'facturamx_unauthorized',
			409 => 'facturamx_org_not_ready',
			422 => 'facturamx_invalid_payload',
		);

		$code = isset( $codes[ $status ] ) ? $codes[ $status ] : 'facturamx_unexpected_response';

		return self::fail( $code, self::message( $code, $api, $status ) );
	}

	/**
	 * Mensaje para el usuario. Un único sitio donde viven las cadenas.
	 *
	 * El texto de la API se propaga cuando aporta algo (409 y 422 dicen qué
	 * falta o qué está mal), y se ignora cuando no (un 401 siempre significa
	 * lo mismo, y el texto del servidor no le dice nada al operador).
	 *
	 * @param string $code   Código de error del plugin.
	 * @param string $api    Texto que devolvió la API, si venía.
	 * @param int    $status Código HTTP, solo para el mensaje de respuesta inesperada.
	 * @return string
	 */
	private static function message( $code, $api = '', $status = 0 ) {
		switch ( $code ) {
			case 'facturamx_unauthorized':
				return __( 'FacturaMX rechazó el token. Revísalo en los ajustes del plugin.', 'facturamx-for-woocommerce' );

			case 'facturamx_org_not_ready':
				return '' === $api
					? __( 'La organización no puede facturar ahora mismo. Revisa timbres, CSD y API key en FacturaMX.', 'facturamx-for-woocommerce' )
					: sprintf(
						/* translators: %s: mensaje devuelto por la API. */
						__( 'No se pudo facturar: %s', 'facturamx-for-woocommerce' ),
						$api
					);

			case 'facturamx_invalid_payload':
				return '' === $api
					? __( 'Los datos de la factura no son válidos.', 'facturamx-for-woocommerce' )
					: sprintf(
						/* translators: %s: mensaje devuelto por la API. */
						__( 'Los datos de la factura no son válidos: %s', 'facturamx-for-woocommerce' ),
						$api
					);

			case 'facturamx_rate_limited':
				return __( 'FacturaMX está recibiendo demasiadas peticiones. Espera un minuto y vuelve a intentarlo.', 'facturamx-for-woocommerce' );

			case 'facturamx_upstream_error':
				return __( 'El servicio de timbrado no responde. Vuelve a intentarlo en unos minutos.', 'facturamx-for-woocommerce' );

			default:
				return 0 === $status
					? __( 'FacturaMX devolvió una respuesta inesperada.', 'facturamx-for-woocommerce' )
					: sprintf(
						/* translators: %d: código HTTP devuelto por la API. */
						__( 'FacturaMX devolvió una respuesta inesperada (%d).', 'facturamx-for-woocommerce' ),
						$status
					);
		}
	}

	/**
	 * Atajo para construir un veredicto de fallo.
	 *
	 * @param string $code    Código de error del plugin.
	 * @param string $message Mensaje para el usuario.
	 * @return array
	 */
	private static function fail( $code, $message ) {
		return array(
			'action'  => 'fail',
			'code'    => $code,
			'message' => $message,
		);
	}
}
