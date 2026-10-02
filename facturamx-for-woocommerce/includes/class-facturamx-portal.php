<?php
/**
 * El portal público: /facturacion/.
 *
 * Es la única parte del plugin que atiende a alguien sin sesión iniciada, y por
 * eso es la que más se parece a una puerta a la calle. Todo lo que hace lo
 * hacen ya otras clases —`FacturaMX_Eligibility` decide, `FacturaMX_Receptor` valida,
 * `FacturaMX_Order_Mapper` mapea, `FacturaMX_Client` timbra, `FacturaMX_Invoice` persiste,
 * `FacturaMX_Download` sirve— y ninguna de ellas se modifica aquí (decisión D7). Lo
 * propio de esta clase son tres cosas:
 *
 *   1. **El estado que no existe.** Cada petición reenvía número de pedido e
 *      importe y se revalida entera (decisión D1). No hay token de continuación
 *      ni sesión: el importe ya es el secreto, y un segundo secreto sería otro
 *      secreto que gestionar sin comprar nada. La consecuencia buena es que no
 *      se puede saltar el paso 1 — quien llame directamente a timbrar tiene que
 *      aportar igualmente el importe correcto.
 *   2. **El freno.** Antes de tocar la red, dos contadores sobre transients.
 *   3. **Los tres pasos** en una sola página, sin recargas.
 *
 * Lo que NO hace, y se comprueba con grep y no de memoria:
 *
 *   - El ajuste del token no se lee en ningún punto de este archivo. Lo leen
 *     FacturaMX_Client y FacturaMX_Download, del lado del servidor, y nunca se pinta en el
 *     HTML. La comprobación es un grep sobre el nombre del ajuste: por eso este
 *     comentario lo describe en vez de escribirlo, para no ensuciar el recuento.
 *   - Se timbra en **un solo punto** de todo el archivo, dentro de ajax_stamp().
 *     Igual que arriba: el recuento del método se hace con grep, así que aquí no
 *     se escribe su nombre.
 *   - Buscar, validar y consultar un pedido ya facturado hacen **cero**
 *     peticiones a `/api/public/invoice`: ese endpoint no tiene modo de prueba,
 *     valida y timbra en la misma llamada.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Portal {

	/** Shortcode que se pega en la página de facturación. */
	const SHORTCODE = 'facturamx_portal';

	/** Acción AJAX del paso 1. No toca la API de facturación. */
	const ACTION_SEARCH = 'facturamx_portal_search';

	/** Acción AJAX del paso 2. El único camino que gasta un timbre. */
	const ACTION_STAMP = 'facturamx_portal_stamp';

	/** Acción del nonce que firma las dos peticiones del portal. */
	const NONCE_ACTION = 'facturamx_portal';

	/** Fallos consecutivos antes de frenar. */
	const THROTTLE_MAX = 5;

	/** Ventana del freno, en segundos. */
	const THROTTLE_WINDOW = 600;

	/**
	 * Registra el shortcode y los cuatro handlers.
	 *
	 * Los `nopriv` son los que importan: el visitante que factura no ha iniciado
	 * sesión. Se registran también los que sí, porque el administrador que
	 * prueba el portal desde su propio navegador está autenticado y sin ellos
	 * WordPress le devolvería 0 sin explicación.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );

		foreach ( array( self::ACTION_SEARCH => 'ajax_search', self::ACTION_STAMP => 'ajax_stamp' ) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Convierte lo que el visitante teclea en el importe en un número.
	 *
	 * El importe es la contraseña, y el cliente lo copia de donde puede: del
	 * correo de WooCommerce, del extracto del banco, de la pantalla de gracias.
	 * Llega con signo de peso, con separador de miles, con coma decimal o con
	 * espacios. Rechazar `1,160.00` sería rechazar a alguien que ha escrito su
	 * importe correctamente.
	 *
	 * Cuando aparecen coma y punto, **manda el último**: es el separador
	 * decimal en las dos convenciones. Con una sola coma, se lee como decimal si
	 * la siguen exactamente dos cifras (`1160,00`) y como separador de miles en
	 * cualquier otro caso (`1,160`).
	 *
	 * Devuelve `null`, nunca `0.0`, cuando no hay número. Un formulario a medias
	 * no es un importe de cero: tratarlo así abriría de par en par cualquier
	 * pedido de importe cero ante un campo vacío. Función pura.
	 *
	 * @param mixed $raw Lo tecleado.
	 * @return float|null
	 */
	public static function normalize_amount( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
			return null;
		}

		$raw = (string) $raw;

		// Un signo menos se rechaza, no se ignora. Limpiarlo convertiría '-100'
		// en 100, que es reinterpretar lo que alguien escribió en lugar de
		// decirle que no vale. Ningún total de pedido es negativo.
		if ( false !== strpos( $raw, '-' ) ) {
			return null;
		}

		// Fuera moneda, espacios (incluido el fino de los miles) y nada más.
		$text = preg_replace( '/[^0-9.,]/u', '', $raw );

		if ( '' === $text ) {
			return null;
		}

		$last_dot   = strrpos( $text, '.' );
		$last_comma = strrpos( $text, ',' );

		if ( false !== $last_dot && false !== $last_comma ) {
			$decimal = $last_dot > $last_comma ? '.' : ',';
		} elseif ( false !== $last_comma ) {
			// Una coma sola: decimal si la siguen dos cifras y no hay más comas.
			$decimal = preg_match( '/^[0-9]+,[0-9]{2}$/', $text ) ? ',' : '';
		} else {
			$decimal = '.';
		}

		if ( ',' === $decimal ) {
			$text = str_replace( '.', '', $text );
			$text = str_replace( ',', '.', $text );
		} else {
			$text = str_replace( ',', '', $text );
		}

		// Después de normalizar tiene que ser un decimal y nada más. Aquí es
		// donde muere '1160.00.00', que hasta este punto parecía un número.
		if ( ! preg_match( '/^[0-9]+(\.[0-9]+)?$/', $text ) ) {
			return null;
		}

		return round( (float) $text, 2 );
	}

	/**
	 * Convierte lo tecleado en el número de pedido en un id.
	 *
	 * WooCommerce imprime el número con almohadilla en el correo de confirmación
	 * —«Pedido #1234»— y el cliente copia lo que ve. Exigirle que la quite sería
	 * cobrarle un error nuestro. Devuelve 0 si no hay un id utilizable, y 0 no
	 * es un pedido válido en WooCommerce. Función pura.
	 *
	 * @param mixed $raw Lo tecleado.
	 * @return int
	 */
	public static function normalize_order_number( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return 0;
		}

		$text = trim( (string) $raw );
		$text = ltrim( $text, '#' );
		$text = trim( $text );

		if ( ! preg_match( '/^[0-9]+$/', $text ) ) {
			return 0;
		}

		return (int) $text;
	}

	/**
	 * Pinta el portal.
	 *
	 * No hace **ninguna** llamada de red: los catálogos del SAT viajan en la
	 * respuesta de la búsqueda, no en el HTML de la página (decisión D10). Si se
	 * pidieran aquí, cada visita a la página consumiría cuota del límite de
	 * 10 peticiones por minuto que es *de la organización*, no del visitante, y
	 * un pico de tráfico dejaría a la tienda sin poder facturar.
	 *
	 * @return string HTML.
	 */
	public static function render() {
		if ( ! FacturaMX_Settings::is_configured() || ! function_exists( 'wc_get_order' ) ) {
			// Mensaje neutro a propósito: no nombra el plugin, ni los ajustes, ni
			// el token, ni WooCommerce. Quien lo lee es un cliente, no un
			// administrador, y decirle qué falta le dice también qué atacar.
			return '<p class="facturamx-portal-off">'
				. esc_html__( 'La facturación no está disponible ahora mismo. Escríbenos y te ayudamos.', 'facturamx-for-woocommerce' )
				. '</p>';
		}

		wp_enqueue_script( 'jquery' );

		// La página lleva un nonce: no puede servirse desde una caché de página,
		// o los clientes recibirían uno caducado. DONOTCACHEPAGE lo respetan WP
		// Super Cache, W3TC y LiteSpeed; LiteSpeed además por su propia acción.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar que leen los plugins de caché.
		}
		do_action( 'litespeed_control_set_nocache', 'facturamx portal' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- acción propia de LiteSpeed Cache.

		ob_start();
		self::render_markup();

		return (string) ob_get_clean();
	}

	/**
	 * Handler del paso 1. Busca el pedido y decide si es facturable.
	 *
	 * Cero peticiones a `/api/public/invoice` en este camino, por construcción:
	 * el endpoint no distingue validar de timbrar, así que la única forma de no
	 * gastar un timbre al buscar es no llamarlo.
	 *
	 * @return void
	 */
	public static function ajax_search() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce', false ) ) {
			self::fail( self::expired_nonce_error(), 403 );
		}

		$input = self::read_lookup();

		if ( self::throttled( $input['order_id'] ) ) {
			self::fail( self::throttle_error(), 429 );
		}

		$order = $input['order_id'] ? wc_get_order( $input['order_id'] ) : false;

		// ¿Se facturó ya por otra vía (panel, cotización del POS)? Va antes de la
		// elegibilidad: un pedido ya facturado es la rama de descarga, aunque esté
		// fuera de plazo. Solo escribe la meta; el importe se sigue comprobando abajo.
		if ( $order ) {
			FacturaMX_Invoice::sync_from_api( $order );
		}

		$eligible = FacturaMX_Eligibility::check(
			self::facts_from_order( $order ),
			array(
				'amount'      => $input['amount'],
				'window_days' => (int) FacturaMX_Settings::get( 'invoicing_window' ),
			)
		);

		if ( is_wp_error( $eligible ) ) {
			self::register_failure( $input['order_id'] );
			self::fail( $eligible );
		}

		self::clear_failures();

		// Ya facturado: se le entregan sus documentos y se acabó. No hay
		// formulario que rellenar porque no hay nada que emitir.
		if ( FacturaMX_Invoice::is_stamped( $order ) ) {
			wp_send_json_success(
				array(
					'ok'      => true,
					'step'    => 'done',
					'message' => __( 'Este pedido ya tiene factura. Puedes descargarla aquí.', 'facturamx-for-woocommerce' ),
					'pdf_url' => FacturaMX_Download::url( $order, 'pdf' ),
					'xml_url' => FacturaMX_Download::url( $order, 'xml' ),
				)
			);
		}

		$catalogs = FacturaMX_Client::catalogs();

		if ( is_wp_error( $catalogs ) ) {
			self::fail( $catalogs, 503 );
		}

		wp_send_json_success(
			array(
				'ok'       => true,
				'step'     => 'form',
				'summary'  => self::summary( $order ),
				'catalogs' => self::catalog_options( $catalogs ),
				'defaults' => self::defaults_for( $order ),
			)
		);
	}

	/**
	 * Handler del paso 2. El único camino del portal que gasta un timbre.
	 *
	 * Revalida la elegibilidad **entera** (decisión D1). No da por buena la
	 * búsqueda anterior porque no hay ninguna: entre una petición y otra no se
	 * guarda nada, y lo que llega es un POST que cualquiera puede fabricar.
	 *
	 * @return void
	 */
	public static function ajax_stamp() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce', false ) ) {
			self::fail( self::expired_nonce_error(), 403 );
		}

		$input = self::read_lookup();

		if ( self::throttled( $input['order_id'] ) ) {
			self::fail( self::throttle_error(), 429 );
		}

		$order    = $input['order_id'] ? wc_get_order( $input['order_id'] ) : false;
		$eligible = FacturaMX_Eligibility::check(
			self::facts_from_order( $order ),
			array(
				'amount'      => $input['amount'],
				'window_days' => (int) FacturaMX_Settings::get( 'invoicing_window' ),
			)
		);

		if ( is_wp_error( $eligible ) ) {
			self::register_failure( $input['order_id'] );
			self::fail( $eligible );
		}

		self::clear_failures();

		// Guarda de servidor contra el doble timbrado. El botón deshabilitado en
		// el navegador no detiene una segunda petición hecha a mano.
		if ( FacturaMX_Invoice::is_stamped( $order ) ) {
			self::fail(
				new WP_Error(
					'facturamx_already_stamped',
					__( 'Este pedido ya tiene factura. Vuelve a buscarlo para descargarla.', 'facturamx-for-woocommerce' )
				),
				409
			);
		}

		$request  = self::read_receptor();
		$catalogs = FacturaMX_Client::catalogs();

		if ( is_wp_error( $catalogs ) ) {
			self::fail( $catalogs, 503 );
		}

		$valid = FacturaMX_Receptor::validate(
			$request['customer'],
			$catalogs,
			$request['use'],
			$request['payment_form']
		);

		if ( is_wp_error( $valid ) ) {
			self::fail( $valid );
		}

		self::save_customer( $order, $request );

		$payload = FacturaMX_Order_Mapper::map(
			$order,
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
			)
		);

		if ( is_wp_error( $payload ) ) {
			self::fail( $payload );
		}

		$response = FacturaMX_Client::stamp( $payload );

		// S2.4: la tienda no ha terminado de configurar el plugin. Al cliente no
		// se le explican claves del SAT; al comerciante sí, en el propio pedido,
		// para que sepa que alguien se quedó sin factura y por qué.
		if ( is_wp_error( $response ) && 'facturamx_setup_incomplete' === $response->get_error_code() ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: motivos por los que el plugin no puede timbrar todavía. */
					__( 'Un cliente intentó facturar este pedido pero la facturación no está lista: %s', 'facturamx-for-woocommerce' ),
					$response->get_error_message()
				),
				false
			);
			self::fail(
				new WP_Error(
					'facturamx_setup_incomplete',
					__( 'La tienda aún no termina de configurar la facturación en línea. Por favor contáctala para que te envíe tu factura.', 'facturamx-for-woocommerce' )
				),
				503
			);
		}

		// Un fallo no deja rastro en la meta: tras un timeout nadie sabe si la
		// factura llegó a emitirse, y escribir «falló» sería afirmarlo.
		if ( is_wp_error( $response ) ) {
			self::fail( $response, 502 );
		}

		$meta = FacturaMX_Invoice::persist( $order, $response );

		if ( is_wp_error( $meta ) ) {
			self::fail( $meta, 502 );
		}

		// Segunda nota, corta: persist() ya escribió el diario fiscal; esta dice
		// de dónde vino. Saber si facturó el cliente o el administrador es lo
		// primero que se pregunta cuando algo hay que revisar.
		$order->add_order_note(
			__( 'Facturada por el cliente desde el portal de autofacturación.', 'facturamx-for-woocommerce' ),
			false
		);

		wp_send_json_success(
			array(
				'ok'      => true,
				'step'    => 'done',
				'message' => FacturaMX_Invoice::note_text( $response ),
				'pdf_url' => FacturaMX_Download::url( $order, 'pdf' ),
				'xml_url' => FacturaMX_Download::url( $order, 'xml' ),
			)
		);
	}

	/**
	 * Lee número de pedido e importe, que van en las dos peticiones.
	 *
	 * @return array{order_id:int, amount:float|null}
	 */
	private static function read_lookup() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el nonce
		// lo comprueba check_ajax_referer() al entrar en cada handler. La página
		// del portal se marca como no cacheable para que nunca esté caducado.
		$number = isset( $_POST['order_number'] ) ? sanitize_text_field( wp_unslash( $_POST['order_number'] ) ) : '';
		$amount = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'order_id' => self::normalize_order_number( is_scalar( $number ) ? (string) $number : '' ),
			'amount'   => self::normalize_amount( is_scalar( $amount ) ? (string) $amount : '' ),
		);
	}

	/**
	 * Lee y sanea el receptor. El saneado vive entero en FacturaMX_Receptor.
	 *
	 * @return array
	 */
	private static function read_receptor() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Ver D9 en read_lookup().
		$raw = isset( $_POST['customer'] ) && is_array( $_POST['customer'] )
			? wp_unslash( $_POST['customer'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- lo sanea FacturaMX_Receptor::sanitize() justo abajo, campo a campo.
			: array();

		return array(
			'customer'     => FacturaMX_Receptor::sanitize( $raw ),
			'use'          => isset( $_POST['use'] ) ? sanitize_text_field( wp_unslash( $_POST['use'] ) ) : '',
			'payment_form' => isset( $_POST['payment_form'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_form'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Extrae del pedido los hechos que FacturaMX_Eligibility necesita.
	 *
	 * `getOffsetTimestamp()` y no `getTimestamp()`: el primero devuelve la hora
	 * local del sitio, que es la misma escala que usa `current_time('timestamp')`
	 * dentro de FacturaMX_Eligibility. Mezclarlas desplazaría la ventana las horas del
	 * huso —seis en México— sin que nadie lo hubiera decidido.
	 *
	 * @param WC_Order|false $order Pedido, o false si no se encontró.
	 * @return array
	 */
	private static function facts_from_order( $order ) {
		if ( ! $order ) {
			return array( 'exists' => false );
		}

		$created = $order->get_date_created();

		return array(
			'exists'     => true,
			'total'      => (float) $order->get_total(),
			'is_paid'    => (bool) $order->is_paid(),
			'refunded'   => (float) $order->get_total_refunded(),
			'created_ts' => $created ? (int) $created->getOffsetTimestamp() : 0,
			'is_stamped' => FacturaMX_Invoice::is_stamped( $order ),
			'currency'   => (string) $order->get_currency(),
		);
	}

	/**
	 * Correo que se propone en el formulario a partir del de facturación del pedido.
	 *
	 * Si es del dominio de la propia tienda no es del cliente: es una dirección
	 * interna (p. ej. la que crea un bot de WhatsApp al dar de alta el pedido). Se
	 * deja vacío para que el cliente escriba el suyo; si no, la factura le llegaría
	 * a la tienda.
	 *
	 * @param string $email     Correo de facturación del pedido.
	 * @param string $site_host Host de la tienda (home_url).
	 * @return string
	 */
	public static function prefill_email( $email, $site_host ) {
		$email = trim( (string) $email );
		$at    = strrpos( $email, '@' );
		if ( '' === $email || false === $at ) {
			return $email;
		}
		$domain = strtolower( substr( $email, $at + 1 ) );
		$site   = strtolower( preg_replace( '/^www\./i', '', trim( (string) $site_host ) ) );
		if ( '' !== $site && ( $domain === $site || substr( $domain, -strlen( '.' . $site ) ) === '.' . $site ) ) {
			return '';
		}
		return $email;
	}

	/**
	 * Importe de wc_price() como texto plano para el resumen.
	 *
	 * wc_price() devuelve HTML con el símbolo como entidad (`&#36;`). Quitar las
	 * etiquetas no basta: el JavaScript lo escribe con .text() y el cliente veía
	 * «&#36;678.00» literal. Hay que decodificar también las entidades.
	 *
	 * @param string $html Salida de wc_price().
	 * @return string
	 */
	public static function plain_price( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Resumen del pedido para que el cliente confirme que es el suyo.
	 *
	 * Se enseña **después** de acertar el importe, nunca antes: es información
	 * del pedido y solo la ve quien ya ha demostrado que es suyo.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	private static function summary( $order ) {
		$created = $order->get_date_created();

		return array(
			'number' => (string) $order->get_order_number(),
			'date'   => $created ? date_i18n( (string) get_option( 'date_format' ), $created->getOffsetTimestamp() ) : '',
			'total'  => self::plain_price( wc_price( $order->get_total() ) ),
			'items'  => (int) $order->get_item_count(),
		);
	}

	/**
	 * Aplana los catálogos del SAT a pares valor/etiqueta para los selectores.
	 *
	 * La clave del código es `code`, no `key`: es la que usa el servidor, la que
	 * lee `FacturaMX_Receptor::validate()` para su lista de códigos válidos y la que
	 * pinta `render_select()` en el metabox. Escribir `key` aquí no rompería
	 * nada visiblemente —devolvería tres listas vacías— y el cliente vería tres
	 * selectores sin opciones sin ningún error en ninguna parte. Por eso está
	 * probada en el runner y no solo leída.
	 *
	 * Pública para poder probarla: es la forma exacta de la respuesta que
	 * consume el navegador, no un detalle interno.
	 *
	 * @param array $catalogs Catálogos tal y como los devuelve FacturaMX_Client.
	 * @return array
	 */
	public static function catalog_options( $catalogs ) {
		$out      = array();
		$catalogs = is_array( $catalogs ) ? $catalogs : array();

		foreach ( array( 'tax_regimes', 'cfdi_uses', 'payment_forms' ) as $key ) {
			$out[ $key ] = array();

			if ( ! isset( $catalogs[ $key ] ) || ! is_array( $catalogs[ $key ] ) ) {
				continue;
			}

			foreach ( $catalogs[ $key ] as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['code'] ) ) {
					continue;
				}

				$code = (string) $entry['code'];

				if ( '' === $code ) {
					continue;
				}

				$name = isset( $entry['name'] ) ? (string) $entry['name'] : $code;

				$out[ $key ][] = array(
					'value' => $code,
					'label' => $code . ' — ' . $name,
				);
			}
		}

		return $out;
	}

	/**
	 * Prerrellena el formulario con lo que ya se sabe del cliente.
	 *
	 * Si el pedido se facturó antes desde el admin, sus datos fiscales están en
	 * la meta y se reutilizan. La forma de pago sale del ajuste del sitio: es la
	 * de la tienda, no la del cliente, y hacérsela elegir invita a equivocarse.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	private static function defaults_for( $order ) {
		$saved = array();

		foreach ( FacturaMX_Order_Metabox::CUSTOMER_FIELDS as $field ) {
			$saved[ $field ] = (string) $order->get_meta( FacturaMX_Order_Metabox::CUSTOMER_PREFIX . $field, true );
		}

		if ( '' === $saved['email'] ) {
			$saved['email'] = self::prefill_email(
				(string) $order->get_billing_email(),
				(string) wp_parse_url( home_url(), PHP_URL_HOST )
			);
		}

		if ( '' === $saved['payment_form'] ) {
			$saved['payment_form'] = (string) FacturaMX_Settings::get( 'payment_form' );
		}

		return $saved;
	}

	/**
	 * Guarda el receptor en la meta del pedido, con las mismas claves que el
	 * metabox del admin: una sola fuente de verdad para los dos caminos.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param array    $request Salida de read_receptor().
	 * @return void
	 */
	private static function save_customer( $order, $request ) {
		$values = array_merge(
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
			)
		);

		foreach ( FacturaMX_Order_Metabox::CUSTOMER_FIELDS as $field ) {
			$order->update_meta_data(
				FacturaMX_Order_Metabox::CUSTOMER_PREFIX . $field,
				isset( $values[ $field ] ) ? (string) $values[ $field ] : ''
			);
		}

		$order->save();
	}

	// -------------------------------------------------------------------------
	// El freno (decisión D6)
	//
	// Dos contadores porque frenan ataques distintos: uno por IP detiene a
	// alguien probando muchos pedidos, y uno por pedido detiene a muchas IP
	// probando importes del mismo. Solo se cuentan los FALLOS: quien acierta no
	// está atacando, y contarle los aciertos castigaría a la familia que factura
	// cinco pedidos seguidos desde la misma casa.
	//
	// El contador por IP es lo mejor que se puede hacer, no una garantía: detrás
	// de un proxy que no reescriba la cabecera, muchos visitantes comparten IP, y
	// sin proxy delante la cabecera se puede falsear. El que no se puede esquivar
	// es el de pedido, y por eso está.
	// -------------------------------------------------------------------------

	/**
	 * ¿Hay que frenar esta petición?
	 *
	 * Se evalúa **antes** de cualquier acceso a la red y antes de leer el pedido.
	 *
	 * @param int $order_id Pedido pedido, 0 si no era utilizable.
	 * @return bool
	 */
	private static function throttled( $order_id ) {
		if ( (int) get_transient( self::ip_key() ) >= self::THROTTLE_MAX ) {
			return true;
		}

		return $order_id > 0 && (int) get_transient( self::order_key( $order_id ) ) >= self::THROTTLE_MAX;
	}

	/**
	 * Apunta un fallo en los dos contadores.
	 *
	 * @param int $order_id Pedido tecleado, 0 si no era utilizable.
	 * @return void
	 */
	private static function register_failure( $order_id ) {
		$keys = array( self::ip_key() );

		if ( $order_id > 0 ) {
			$keys[] = self::order_key( $order_id );
		}

		foreach ( $keys as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::THROTTLE_WINDOW );
		}
	}

	/**
	 * Un acierto borra el contador de la IP.
	 *
	 * El del pedido no se borra: si acertar lo limpiara, quien ya conoce un
	 * importe válido tendría intentos infinitos sobre ese pedido alternando
	 * aciertos y pruebas.
	 *
	 * @return void
	 */
	private static function clear_failures() {
		delete_transient( self::ip_key() );
	}

	/**
	 * Clave del contador por IP. La IP va con hash: es un dato personal y no
	 * hace falta guardarla en claro para contar.
	 *
	 * @return string
	 */
	private static function ip_key() {
		$ip = '';

		// Cloudflare reescribe esta cabecera en cada petición, así que detrás de
		// Cloudflare es fiable. Sin Cloudflare delante se puede falsear, y por eso
		// el contador por pedido es el que sostiene el freno.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'facturamx_rl_ip_' . sha1( $ip );
	}

	/**
	 * Clave del contador por pedido.
	 *
	 * @param int $order_id Id del pedido.
	 * @return string
	 */
	private static function order_key( $order_id ) {
		return 'facturamx_rl_ord_' . (int) $order_id;
	}

	/**
	 * El error del freno. No nombra pedidos ni importes: decir «demasiados
	 * intentos con ESE pedido» confirmaría que el pedido existe.
	 *
	 * @return WP_Error
	 */
	private static function throttle_error() {
		return new WP_Error(
			'facturamx_throttled',
			__( 'Demasiados intentos. Espera unos minutos y vuelve a probar.', 'facturamx-for-woocommerce' )
		);
	}

	/**
	 * Error cuando el nonce no es válido: casi siempre, una página abierta desde
	 * hace muchas horas. Al cliente se le pide recargar, sin más detalle.
	 *
	 * @return WP_Error
	 */
	public static function expired_nonce_error() {
		return new WP_Error(
			'facturamx_expired_page',
			__( 'Esta página lleva mucho tiempo abierta. Recarga la página y vuelve a intentarlo.', 'facturamx-for-woocommerce' )
		);
	}

	/**
	 * Responde un error y termina. Un solo sitio, un solo formato.
	 *
	 * @param WP_Error $error  Error.
	 * @param int      $status Código HTTP.
	 * @return void
	 */
	private static function fail( $error, $status = 422 ) {
		wp_send_json_error(
			array(
				'ok'      => false,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * El HTML y el guion de los tres pasos.
	 *
	 * Un solo contenedor con tres secciones y JavaScript que enseña una cada vez.
	 * Sin recargas y sin estado en el servidor: cada petición reenvía número e
	 * importe (decisión D1), así que recargar la página no rompe nada, solo
	 * devuelve al paso 1.
	 *
	 * @return void
	 */
	private static function render_markup() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( self::NONCE_ACTION );

		wp_register_style( 'facturamx-portal', false, array(), FACTURAMX_VERSION );
		wp_enqueue_style( 'facturamx-portal' );
		wp_add_inline_style(
			'facturamx-portal',
			'.facturamx-portal{max-width:34rem}'
			. '.facturamx-portal label{font-weight:600}'
			. '.facturamx-portal input[type=text],.facturamx-portal input[type=email],.facturamx-portal select{'
			. 'box-sizing:border-box;width:100%;padding:.6em .75em;margin-top:.3em;font-size:1em;'
			. 'border:1px solid #c3c4c7;border-radius:6px;background:#fff;color:inherit}'
			. '.facturamx-portal button{padding:.65em 1.4em;font-size:1em;border-radius:6px;cursor:pointer}'
			. '.facturamx-portal .facturamx-summary{font-weight:600}'
		);
		?>
		<div class="facturamx-portal">
			<div class="facturamx-step facturamx-step-search">
				<p>
					<label for="facturamx-order-number"><?php esc_html_e( 'Número de pedido', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="text" id="facturamx-order-number" inputmode="numeric" autocomplete="off" placeholder="1234">
				</p>
				<p>
					<label for="facturamx-amount"><?php esc_html_e( 'Total pagado', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="text" id="facturamx-amount" inputmode="decimal" autocomplete="off" placeholder="1160.00">
				</p>
				<p><button type="button" id="facturamx-search"><?php esc_html_e( 'Buscar mi pedido', 'facturamx-for-woocommerce' ); ?></button></p>
			</div>

			<div class="facturamx-step facturamx-step-form" style="display:none;">
				<div class="facturamx-summary"></div>
				<p>
					<label for="facturamx-legal-name"><?php esc_html_e( 'Razón social', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="text" id="facturamx-legal-name" autocomplete="organization">
					<br><small><?php esc_html_e( 'Tal y como aparece en tu Constancia de Situación Fiscal.', 'facturamx-for-woocommerce' ); ?></small>
				</p>
				<p>
					<label for="facturamx-tax-id"><?php esc_html_e( 'RFC', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="text" id="facturamx-tax-id" autocomplete="off">
				</p>
				<p>
					<label for="facturamx-zip"><?php esc_html_e( 'Código postal fiscal', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="text" id="facturamx-zip" inputmode="numeric" autocomplete="postal-code">
				</p>
				<p>
					<label for="facturamx-email"><?php esc_html_e( 'Correo para recibir la factura', 'facturamx-for-woocommerce' ); ?></label><br>
					<input type="email" id="facturamx-email" autocomplete="email">
				</p>
				<p>
					<label for="facturamx-tax-system"><?php esc_html_e( 'Régimen fiscal', 'facturamx-for-woocommerce' ); ?></label><br>
					<select id="facturamx-tax-system"></select>
				</p>
				<p>
					<label for="facturamx-use"><?php esc_html_e( 'Uso del CFDI', 'facturamx-for-woocommerce' ); ?></label><br>
					<select id="facturamx-use"></select>
				</p>
				<p>
					<label for="facturamx-payment-form"><?php esc_html_e( 'Forma de pago', 'facturamx-for-woocommerce' ); ?></label><br>
					<select id="facturamx-payment-form"></select>
				</p>
				<p><button type="button" id="facturamx-stamp"><?php esc_html_e( 'Generar factura', 'facturamx-for-woocommerce' ); ?></button></p>
				<p><small><?php esc_html_e( 'La factura se emite con estos datos y no se puede modificar después.', 'facturamx-for-woocommerce' ); ?></small></p>
			</div>

			<div class="facturamx-step facturamx-step-done" style="display:none;">
				<p class="facturamx-done-message"></p>
				<p class="facturamx-links"></p>
			</div>

			<p class="facturamx-message" role="status" aria-live="polite"></p>
		</div>

		<?php ob_start(); ?>
		jQuery( function ( $ ) {
			var root    = $( '.facturamx-portal' );
			var message = root.find( '.facturamx-message' );
			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;

			function say( text, bad ) {
				message.text( text || '' ).css( 'color', bad ? '#b32d2e' : 'inherit' );
			}

			function step( name ) {
				root.find( '.facturamx-step' ).hide();
				root.find( '.facturamx-step-' + name ).show();
			}

			// El número y el importe se reenvían en las DOS peticiones: no hay
			// estado en el servidor, así que timbrar revalida desde cero.
			function lookup() {
				return {
					order_number: $( '#facturamx-order-number' ).val(),
					amount: $( '#facturamx-amount' ).val()
				};
			}

			function fill( select, options, selected ) {
				select.empty().append( $( '<option>' ).val( '' ).text( '—' ) );
				$.each( options || [], function ( i, option ) {
					select.append( $( '<option>' ).val( option.value ).text( option.label ) );
				} );
				if ( selected ) {
					select.val( selected );
				}
			}

			function links( data ) {
				var box = root.find( '.facturamx-links' ).empty();
				if ( data.pdf_url ) {
					box.append( $( '<a>' ).attr( 'href', data.pdf_url ).text( <?php echo wp_json_encode( __( 'Descargar PDF', 'facturamx-for-woocommerce' ) ); ?> ) );
				}
				if ( data.xml_url ) {
					box.append( ' ' ).append( $( '<a>' ).attr( 'href', data.xml_url ).text( <?php echo wp_json_encode( __( 'Descargar XML', 'facturamx-for-woocommerce' ) ); ?> ) );
				}
			}

			function done( data ) {
				root.find( '.facturamx-done-message' ).text( data.message || '' );
				links( data );
				step( 'done' );
				say( '' );
			}

			function failed( xhr ) {
				var data = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
				say( data.message || <?php echo wp_json_encode( __( 'No se pudo completar la operación. Vuelve a intentarlo.', 'facturamx-for-woocommerce' ) ); ?>, true );
			}

			$( '#facturamx-search' ).on( 'click', function () {
				var button = $( this ).prop( 'disabled', true );
				say( <?php echo wp_json_encode( __( 'Buscando…', 'facturamx-for-woocommerce' ) ); ?> );

				$.post( ajaxUrl, $.extend( lookup(), { action: <?php echo wp_json_encode( self::ACTION_SEARCH ); ?>, _ajax_nonce: <?php echo wp_json_encode( $nonce ); ?> } ) )
					.done( function ( response ) {
						var data = response && response.data ? response.data : {};

						if ( data.step === 'done' ) {
							done( data );
							return;
						}

						var summary = data.summary || {};
						root.find( '.facturamx-summary' ).text(
							<?php echo wp_json_encode( __( 'Pedido', 'facturamx-for-woocommerce' ) ); ?> + ' #' + summary.number +
							' · ' + summary.date + ' · ' + summary.total
						);

						var catalogs = data.catalogs || {};
						var defaults = data.defaults || {};

						fill( $( '#facturamx-tax-system' ), catalogs.tax_regimes, defaults.tax_system );
						fill( $( '#facturamx-use' ), catalogs.cfdi_uses, defaults.use );
						fill( $( '#facturamx-payment-form' ), catalogs.payment_forms, defaults.payment_form );

						$( '#facturamx-legal-name' ).val( defaults.legal_name || '' );
						$( '#facturamx-tax-id' ).val( defaults.tax_id || '' );
						$( '#facturamx-zip' ).val( defaults.zip || '' );
						$( '#facturamx-email' ).val( defaults.email || '' );

						step( 'form' );
						say( '' );
					} )
					.fail( failed )
					.always( function () {
						button.prop( 'disabled', false );
					} );
			} );

			$( '#facturamx-stamp' ).on( 'click', function () {
				// Se deshabilita antes de la petición y no se vuelve a habilitar
				// pase lo que pase. Un botón que revive tras un timeout es una
				// segunda petición de timbrado.
				$( this ).prop( 'disabled', true );
				say( <?php echo wp_json_encode( __( 'Generando tu factura… no cierres esta página.', 'facturamx-for-woocommerce' ) ); ?> );

				$.post( ajaxUrl, $.extend( lookup(), {
					action: <?php echo wp_json_encode( self::ACTION_STAMP ); ?>,
					_ajax_nonce: <?php echo wp_json_encode( $nonce ); ?>,
					use: $( '#facturamx-use' ).val(),
					payment_form: $( '#facturamx-payment-form' ).val(),
					customer: {
						legal_name: $( '#facturamx-legal-name' ).val(),
						tax_id: $( '#facturamx-tax-id' ).val(),
						tax_system: $( '#facturamx-tax-system' ).val(),
						zip: $( '#facturamx-zip' ).val(),
						email: $( '#facturamx-email' ).val()
					}
				} ) )
					.done( function ( response ) {
						done( response && response.data ? response.data : {} );
					} )
					.fail( failed );
			} );
		} );
		<?php facturamx_inline_script( 'facturamx-portal', (string) ob_get_clean() ); ?>
		<?php
	}
}
