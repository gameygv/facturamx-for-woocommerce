<?php
/**
 * ¿Puede este visitante facturar este pedido?
 *
 * La clase entera existe por una razón que no es la obvia. La obvia sería
 * «centralizar las reglas». La real es que **el orden de las comprobaciones es
 * una decisión de seguridad**, y las decisiones de seguridad no se dejan
 * repartidas por un handler AJAX donde el próximo que las lea las reordene para
 * dar un mensaje más útil.
 *
 * En el portal público el importe hace de contraseña. Y una contraseña solo
 * funciona si el sistema no dice cuál de los dos campos falló. Por eso:
 *
 *   1. ¿existe?                  → no: facturamx_not_found
 *   2. ¿el importe cuadra?       → no: facturamx_not_found   ← MISMO error, a propósito
 *   3. ¿ya está facturado?       → sí: elegible, es la rama de descarga
 *   4. ¿está en pesos?           → no: facturamx_currency_unsupported
 *   5. ¿está pagado?             → no: facturamx_not_paid
 *   6. ¿está libre de reembolso? → no: facturamx_refunded
 *   7. ¿dentro de la ventana?    → no: facturamx_window_expired
 *
 * El paso 4 va después del 2 por lo mismo: un mensaje distinto para los pedidos
 * en otra divisa sería un oráculo —se enumeran probando importes hasta que el
 * texto cambia—. Y va después del 3 porque un pedido en dólares que YA tiene
 * factura viene a descargarla; negársela ahora no deshace el CFDI emitido.
 *
 * Las dos primeras van antes que todo lo demás porque, si «no pagado» se
 * comprobara antes que el importe, cualquiera podría distinguir los números de
 * pedido que existen sin conocer el importe: primero se enumera, después se
 * prueba. Los pasos 4–6 solo se alcanzan cuando el visitante ya ha demostrado
 * saber cuánto pagó, y entonces sí merece un mensaje que le sirva de algo.
 *
 * El paso 3 va donde va porque un pedido ya facturado es elegible AUNQUE esté
 * fuera de ventana o tenga un reembolso posterior: su dueño no viene a emitir
 * nada, viene a descargar lo que ya se emitió. Rechazarlo por antigüedad sería
 * esconderle su propia factura.
 *
 * Pura (decisión D3): recibe hechos ya extraídos del pedido, no un WC_Order. No
 * toca la red, ni la base de datos, ni las opciones. Se prueba entera con
 * `php tests/run-tests.php` — el mismo motivo por el que se extrajeron
 * `FacturaMX_Order_Mapper::build()` y `FacturaMX_Client::classify()`.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Eligibility {

	/** Margen de comparación del importe, en pesos. */
	const TOLERANCE = 0.01;

	/** Ventana por defecto si el ajuste no trae nada usable. */
	const DEFAULT_WINDOW_DAYS = 30;

	/**
	 * Los cuatro textos de rechazo FIJOS, en un solo sitio.
	 *
	 * `facturamx_currency_unsupported` no está aquí a propósito: su texto nombra la
	 * moneda del pedido, así que no es una constante. Lo construye
	 * `FacturaMX_Order_Mapper::currency_error()`, que es quien decide en qué divisa se
	 * emite; tener el texto en dos sitios los dejaría divergir.
	 *
	 * Están aquí y no repartidos por los `return` para que la sonda pueda
	 * comparar lo que devuelve el portal con lo que dice esta clase, sin copiar
	 * las cadenas a mano. Una cadena copiada a mano en un test comprueba que el
	 * test se escribió bien, no que el código hace lo correcto.
	 *
	 * Ninguno nombra el importe ni el número de pedido: nombrarlos convertiría
	 * el mensaje en la confirmación que todo lo demás evita dar.
	 *
	 * @return array<string, string>
	 */
	public static function messages() {
		return array(
			'facturamx_not_found'      => __( 'No encontramos un pedido con esos datos.', 'facturamx-for-woocommerce' ),
			'facturamx_not_paid'       => __( 'Solo se pueden facturar pedidos pagados.', 'facturamx-for-woocommerce' ),
			'facturamx_refunded'       => __( 'Este pedido necesita facturarse a mano. Escríbenos y te ayudamos.', 'facturamx-for-woocommerce' ),
			'facturamx_window_expired' => __( 'El plazo para facturar este pedido ha vencido. Escríbenos y te ayudamos.', 'facturamx-for-woocommerce' ),
		);
	}

	/**
	 * Decide si el pedido es facturable por quien acaba de teclear esos datos.
	 *
	 * @param array $facts {
	 *     Hechos ya extraídos del pedido.
	 *
	 *     @type bool  $exists     ¿Se encontró el pedido?
	 *     @type float $total      Total real del pedido.
	 *     @type bool  $is_paid    ¿Está pagado?
	 *     @type float $refunded   Importe reembolsado. 0 si ninguno.
	 *     @type int   $created_ts Timestamp de creación.
	 *     @type bool   $is_stamped ¿Ya tiene _facturamx_uuid?
	 *     @type string $currency   Moneda del pedido. Si falta, se supone MXN:
	 *                              su ausencia es un descuido del programador,
	 *                              no un hecho del pedido. El valor vacío sí se
	 *                              rechaza.
	 * }
	 * @param array $opts {
	 *     Lo que aporta la petición y la configuración.
	 *
	 *     @type mixed $amount      Importe tecleado por el visitante.
	 *     @type int   $window_days Días de la ventana de facturación.
	 *     @type int   $now         Momento de referencia. Por defecto, ahora.
	 * }
	 * @return true|WP_Error
	 */
	public static function check( $facts, $opts ) {
		$facts = is_array( $facts ) ? $facts : array();
		$opts  = is_array( $opts ) ? $opts : array();

		// 1. Existencia.
		if ( empty( $facts['exists'] ) ) {
			return self::reject( 'facturamx_not_found' );
		}

		// 2. Importe. Es la contraseña, y falla igual que la ausencia.
		if ( ! self::amount_matches( $facts, $opts ) ) {
			return self::reject( 'facturamx_not_found' );
		}

		// 3. A partir de aquí el visitante ha demostrado que el pedido es suyo.
		//    Si ya está facturado no hay nada más que comprobar: viene a
		//    descargar, no a emitir.
		if ( ! empty( $facts['is_stamped'] ) ) {
			return true;
		}

		// 4. En pesos. El gate que no se puede saltar está en el mapeador; éste
		//    solo evita que el visitante rellene el formulario entero para que
		//    falle al final. Es el mismo método, así que no pueden divergir.
		$currency_error = FacturaMX_Order_Mapper::currency_error(
			self::value( $facts, 'currency', FacturaMX_Order_Mapper::CURRENCY )
		);

		if ( $currency_error ) {
			return $currency_error;
		}

		// 5. Pagado.
		if ( empty( $facts['is_paid'] ) ) {
			return self::reject( 'facturamx_not_paid' );
		}

		// 6. Sin reembolso. Un céntimo devuelto ya cambia lo que hay que
		//    facturar, y el plugin no sabe reconstruir ese CFDI: lo hace una
		//    persona.
		if ( round( (float) self::value( $facts, 'refunded', 0.0 ), 2 ) > 0.0 ) {
			return self::reject( 'facturamx_refunded' );
		}

		// 7. Dentro de la ventana.
		if ( ! self::within_window( $facts, $opts ) ) {
			return self::reject( 'facturamx_window_expired' );
		}

		return true;
	}

	/**
	 * ¿El importe tecleado coincide con el del pedido a ±0.01?
	 *
	 * Un valor vacío o no numérico NO es cero: es un formulario a medias. Si se
	 * tratara como 0.00, un pedido de importe cero se abriría sin que nadie
	 * tecleara nada.
	 *
	 * @param array $facts Hechos del pedido.
	 * @param array $opts  Opciones de la petición.
	 * @return bool
	 */
	private static function amount_matches( $facts, $opts ) {
		$raw = isset( $opts['amount'] ) ? $opts['amount'] : '';

		if ( ! is_numeric( $raw ) ) {
			return false;
		}

		// La diferencia se redondea ANTES de compararse (PAT-D-007). Sin el
		// round, 1160.01 - 1160.00 da 0.010000000000218 y un `> 0.01` rechaza
		// un importe que es correcto al centavo.
		$difference = round( (float) self::value( $facts, 'total', 0.0 ) - (float) $raw, 2 );

		return abs( $difference ) <= self::TOLERANCE;
	}

	/**
	 * ¿El pedido está dentro de la ventana de facturación?
	 *
	 * La ventana son N días desde la compra, y N sale del ajuste
	 * `invoicing_window` que S1.1 ya definía. La frontera es inclusiva: a los 30
	 * días exactos todavía se puede facturar. Un `<` en vez de un `<=` le
	 * quitaría un día entero al cliente sin que nadie lo hubiera decidido.
	 *
	 * @param array $facts Hechos del pedido.
	 * @param array $opts  Opciones de la petición.
	 * @return bool
	 */
	private static function within_window( $facts, $opts ) {
		$days = (int) self::value( $opts, 'window_days', self::DEFAULT_WINDOW_DAYS );

		if ( $days < 1 ) {
			$days = self::DEFAULT_WINDOW_DAYS;
		}

		$created = (int) self::value( $facts, 'created_ts', 0 );

		// Sin fecha no se puede afirmar que esté dentro. Jidoka: ante un hecho
		// que falta, se rechaza; dar por buena una fecha desconocida abriría la
		// facturación de cualquier pedido antiguo.
		if ( $created < 1 ) {
			return false;
		}

		$now = (int) self::value( $opts, 'now', 0 );

		if ( $now < 1 ) {
			$now = (int) current_time( 'timestamp' );
		}

		return ( $now - $created ) <= ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Construye el rechazo con el texto publicado en messages().
	 *
	 * Todos los rechazos pasan por aquí. Es lo que garantiza que «no existe» y
	 * «el importe no coincide» no puedan divergir: no hay dos sitios donde
	 * escribir el texto.
	 *
	 * @param string $code Código del rechazo.
	 * @return WP_Error
	 */
	private static function reject( $code ) {
		$messages = self::messages();

		return new WP_Error( $code, $messages[ $code ] );
	}

	/**
	 * Lee una clave con valor por defecto.
	 *
	 * @param array  $source   Array de origen.
	 * @param string $key      Clave.
	 * @param mixed  $fallback Valor si falta.
	 * @return mixed
	 */
	private static function value( $source, $key, $fallback ) {
		return array_key_exists( $key, $source ) ? $source[ $key ] : $fallback;
	}
}
