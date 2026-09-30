<?php
/**
 * Descarga de PDF y XML sin que el token salga del servidor.
 *
 * El endpoint de FacturaMX que entrega los ficheros exige
 * `Authorization: Bearer fmx_live_…` (src/app/api/public/invoice/[id]/[format]
 * /route.ts:22-25). Un navegador no puede mandar esa cabecera desde un
 * `<a href>`, así que **cualquier enlace directo devuelve 401**. Es lo que le
 * pasa hoy al metabox del admin: S1.4 pintó `_facturamx_pdf_url` tal cual y los dos
 * botones están rotos desde entonces. No es una mejora de esta story, es un
 * defecto de la anterior que se repara aquí porque el portal público lo habría
 * heredado igual.
 *
 * La solución es un proxy: WordPress recibe la petición del navegador, pone el
 * token él mismo, se trae el fichero y lo reenvía. El token nunca cruza al
 * cliente — ni en el HTML, ni en la URL, ni en una redirección.
 *
 * Lo que autoriza la descarga es una clave por pedido con el formato
 * `{order_id}.{32 hex}` (decisión D4):
 *
 *   - **El id delante** para que el proxy resuelva el pedido con
 *     `wc_get_order()` en vez de una `meta_query`, que se comporta distinto en
 *     HPOS y en el almacenamiento clásico. El id es enumerable de todas formas;
 *     lo que protege son los 128 bits de `random_bytes(16)`.
 *   - **Generación perezosa**: si el pedido no tiene clave, se crea al pedir la
 *     URL. Así las facturas emitidas antes de esta story tienen enlaces válidos
 *     sin migración ninguna.
 *
 * Todos los rechazos son **404, nunca 403**. Un 403 confirma que la clave
 * existía y solo falló el permiso; el 404 no dice nada. Por eso hay un único
 * `not_found()`: con un `return` suelto por rama, tarde o temprano una devuelve
 * otra cosa.
 *
 * `parse_key()` y `is_format()` son puras y se prueban en el runner (PAT-D-011).
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Download {

	/** Meta donde vive el secreto del pedido. */
	const META_KEY = '_facturamx_dl_key';

	/** Acción de admin-post a la que se engancha el proxy. */
	const ACTION = 'facturamx_download';

	/** Bytes de aleatoriedad del secreto. 16 → 32 caracteres hex. */
	const SECRET_BYTES = 16;

	/** Segundos de espera al traerse el fichero. */
	const TIMEOUT = 30;

	/**
	 * Registra el proxy en las dos variantes de admin-post.
	 *
	 * `admin_post_nopriv_` es la que importa: quien descarga desde el portal no
	 * ha iniciado sesión. Sin ella, WordPress redirige al login y el cliente ve
	 * una pantalla de administración en vez de su factura.
	 *
	 * Se usa admin-post y no una ruta REST ni una regla de reescritura porque no
	 * necesita `flush_rewrite_rules()` en la activación ni depende de cómo estén
	 * configurados los permalinks del sitio (decisión D5).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'serve' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'serve' ) );
	}

	/**
	 * Los dos formatos que se sirven. Lista blanca, no lista negra.
	 *
	 * El valor viaja a la ruta remota `/api/public/invoice/{id}/{format}`. Con
	 * una lista negra habría que acertar con todo lo que puede hacer daño;
	 * enumerando lo permitido, cualquier cosa que no sea exactamente `pdf` o
	 * `xml` —incluido `PDF`, un espacio final o `pdf/../`— se cae sola.
	 *
	 * @param mixed $format Formato pedido.
	 * @return bool
	 */
	public static function is_format( $format ) {
		return in_array( $format, array( 'pdf', 'xml' ), true );
	}

	/**
	 * Parte una clave en id de pedido y secreto, o dice que no vale.
	 *
	 * Estricta a propósito: la expresión exige el id sin ceros a la izquierda,
	 * un único punto y exactamente 32 hex en minúscula. Todo lo que no encaje
	 * devuelve false y no llega a tocar la base de datos. Función pura.
	 *
	 * @param mixed $key Clave tal y como llega por la query string.
	 * @return array|false array{order_id:int, secret:string} o false.
	 */
	public static function parse_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		if ( ! preg_match( '/^([1-9][0-9]*)\.([0-9a-f]{32})$/', $key, $m ) ) {
			return false;
		}

		return array(
			'order_id' => (int) $m[1],
			'secret'   => $m[2],
		);
	}

	/**
	 * Construye una clave nueva para un pedido.
	 *
	 * `random_bytes()` y no `wp_rand()`: el secreto es lo único que separa una
	 * factura de cualquiera que teclee un id. Un generador que no sea
	 * criptográfico convierte 128 bits de entropía en una apariencia de 128 bits.
	 *
	 * @param int $order_id Id del pedido.
	 * @return string Clave `{order_id}.{32 hex}`.
	 */
	public static function build_key( $order_id ) {
		return (int) $order_id . '.' . bin2hex( random_bytes( self::SECRET_BYTES ) );
	}

	/**
	 * Devuelve la clave del pedido, creándola si aún no tiene.
	 *
	 * La generación perezosa es lo que hace que los pedidos ya facturados por
	 * S1.4 tengan enlaces que funcionan sin ejecutar ninguna migración.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string Clave, o cadena vacía si el pedido no sirve.
	 */
	public static function key_for( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		$stored = (string) $order->get_meta( self::META_KEY, true );

		if ( false !== self::parse_key( $stored ) ) {
			return $stored;
		}

		$key = self::build_key( $order->get_id() );

		$order->update_meta_data( self::META_KEY, $key );
		$order->save();

		return $key;
	}

	/**
	 * URL de descarga de un pedido en un formato.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param string   $format 'pdf' o 'xml'.
	 * @return string URL, o cadena vacía si no procede.
	 */
	public static function url( $order, $format ) {
		if ( ! self::is_format( $format ) ) {
			return '';
		}

		$key = self::key_for( $order );

		if ( '' === $key ) {
			return '';
		}

		return add_query_arg(
			array(
				'action' => self::ACTION,
				'key'    => $key,
				'format' => $format,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Atiende la petición del navegador y reenvía el fichero.
	 *
	 * El orden importa por la misma razón que en FacturaMX_Eligibility: primero lo que
	 * se puede comprobar sin consultar nada, y todos los fallos con la misma
	 * respuesta. La comparación del secreto usa `hash_equals()` para que el
	 * tiempo de respuesta no revele cuántos caracteres se acertaron.
	 *
	 * @return void
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- La
		// clave ES la autorización; un nonce exigiría sesión y estos enlaces se
		// abren desde el correo o desde el portal sin iniciarla.
		$format = isset( $_GET['format'] ) ? sanitize_text_field( wp_unslash( $_GET['format'] ) ) : '';
		$raw    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! self::is_format( $format ) ) {
			self::not_found();
		}

		$parsed = self::parse_key( $raw );

		if ( false === $parsed ) {
			self::not_found();
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			self::not_found();
		}

		$order = wc_get_order( $parsed['order_id'] );

		if ( ! $order ) {
			self::not_found();
		}

		$stored = (string) $order->get_meta( self::META_KEY, true );

		// hash_equals sobre la clave entera. Comparar solo el secreto dejaría
		// pasar la clave de otro pedido si alguien cambiara el id delante.
		if ( '' === $stored || ! hash_equals( $stored, $raw ) ) {
			self::not_found();
		}

		$invoice_id = (string) $order->get_meta( '_facturamx_invoice_id', true );

		if ( '' === $invoice_id ) {
			self::not_found();
		}

		$endpoint = FacturaMX_Client::endpoint(
			FacturaMX_Settings::get( 'api_url' ),
			FacturaMX_Client::PATH_INVOICE . '/' . rawurlencode( $invoice_id ) . '/' . $format
		);

		$token = (string) FacturaMX_Settings::get( 'api_token' );

		if ( '' === $endpoint || '' === $token ) {
			self::not_found();
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::not_found();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			self::not_found();
		}

		$serie    = (string) $order->get_meta( '_facturamx_series', true );
		$folio    = (string) $order->get_meta( '_facturamx_folio', true );
		$etiqueta = '' !== $serie . $folio ? $serie . $folio : (string) $order->get_id();

		nocache_headers();
		header( 'Content-Type: ' . ( 'pdf' === $format ? 'application/pdf' : 'application/xml' ) );
		header( 'Content-Disposition: attachment; filename="factura-' . $etiqueta . '.' . $format . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		// Es el PDF o el XML del CFDI, un binario que se reenvía tal cual:
		// escaparlo lo corrompería.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario del CFDI, ver arriba.
		exit;
	}

	/**
	 * Único punto de salida de todos los rechazos.
	 *
	 * 404 y no 403 siempre: el 403 confirma que la clave era real y solo falló
	 * el permiso, que es justo el dato que un atacante busca. Con un solo sitio
	 * donde se emite, ninguna rama puede divergir sin que se vea.
	 *
	 * @return void
	 */
	private static function not_found() {
		status_header( 404 );
		nocache_headers();

		wp_die(
			esc_html__( 'No encontramos ese documento.', 'facturamx-for-woocommerce' ),
			esc_html__( 'No encontrado', 'facturamx-for-woocommerce' ),
			array( 'response' => 404 )
		);
	}
}
