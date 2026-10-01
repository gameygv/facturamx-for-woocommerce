<?php
/**
 * Convierte un pedido de WooCommerce en el payload que espera
 * POST /api/public/invoice de FacturaMX.
 *
 * La clase está partida en dos mitades a propósito (decisión D1 en
 * s1.2-design.md):
 *
 *   map()   habla con WooCommerce: lee el WC_Order, resuelve claves SAT.
 *   build() es PURA: aritmética, tasas y el gate de cuadre. Sin WordPress,
 *           sin red, sin base de datos. Es lo único que puede equivocarse de
 *           una forma que acabe en un documento fiscal erróneo, así que es lo
 *           único que se prueba a fondo en `php tests/run-tests.php`.
 *
 * Esta clase NO llama a la API de FacturaMX. Produce un array; enviarlo es S1.3.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Order_Mapper {

	/**
	 * Tasas de IVA que existen en el catálogo del SAT (c_TasaOCuota) y que esta
	 * tienda puede emitir.
	 *
	 * La tasa se DERIVA de lo que WooCommerce calculó por línea, pero derivada
	 * nunca da un valor exacto: en la sonda salieron 0.15999, 0.159964, 0.159997
	 * y 0.160022 para lo que es un 16 % de manual. Transmitir eso produciría un
	 * TasaOCuota que el SAT no reconoce, así que la derivación sirve para
	 * ELEGIR la tasa del catálogo, no para transmitirla (decisión D3).
	 */
	const TAX_RATES = array( 0.0, 0.08, 0.16 );

	/** Cuánto puede alejarse la tasa derivada de la del catálogo antes de rendirse. */
	const RATE_TOLERANCE = 0.005;

	/**
	 * Tasa que se aplica cuando WooCommerce NO calculó impuesto en la línea y el
	 * producto no declara otra.
	 *
	 * No se confía en la configuración de impuestos de WooCommerce, y es una
	 * decisión deliberada. Que una tienda tenga los impuestos apagados, o un
	 * producto en una clase sin tasa configurada, es un problema de configuración
	 * de la tienda — no una postura fiscal. Si el plugin lo obedeciera emitiría un
	 * CFDI sin IVA por una venta que sí lo lleva.
	 *
	 * La regla es la de la ley: en México todo lo que se vende lleva IVA salvo lo
	 * de primera necesidad (alimentos, medicinas), y el precio que pagó el cliente
	 * YA lo incluye. Así que si WooCommerce no lo desglosó, se desglosa aquí: la
	 * base sale de dividir el importe entre 1 + tasa, y el total no se mueve.
	 *
	 * Lo genuinamente exento se declara producto a producto en META_IVA_RATE. Es
	 * explícito a propósito: un 0 % tiene que ser una decisión de alguien, no el
	 * efecto colateral de una clase de impuesto mal configurada.
	 */
	const DEFAULT_IVA_RATE = 0.16;

	/** Tolerancia del cuadre, en pesos. Un centavo: el redondeo del CFDI, nada más. */
	const TOTAL_TOLERANCE = 0.01;

	/**
	 * Moneda en la que se emite el CFDI.
	 *
	 * Es una constante y no un ajuste a propósito (decisión D2 de S2.1): un
	 * ajuste sugeriría que hay algo que elegir. Esta integración emite en pesos;
	 * facturar en divisa extranjera exige TipoCambio y es otra historia.
	 */
	const CURRENCY = 'MXN';

	/** Decimales de ValorUnitario admitidos por CFDI 4.0. */
	const PRICE_DECIMALS = 6;

	/** Metas de producto que sobreescriben los valores por defecto de los ajustes. */
	const META_PRODUCT_KEY = '_facturamx_product_key';
	const META_UNIT_KEY    = '_facturamx_unit_key';
	const META_UNIT_NAME   = '_facturamx_unit_name';
	const META_IVA_RATE    = '_facturamx_iva_rate';

	/** Claves SAT de las líneas que no son un producto del catálogo. */
	const SHIPPING_PRODUCT_KEY = '78102200'; // Servicios de transporte de carga.
	const FEE_PRODUCT_KEY      = '84111506'; // Servicios de facturación.
	const SERVICE_UNIT_KEY     = 'E48';      // Unidad de servicio.
	const SERVICE_UNIT_NAME    = 'Unidad de servicio';

	/**
	 * Comprueba la moneda de un pedido. PURA a propósito: sin WC_Order de por
	 * medio se puede probar en `php tests/run-tests.php`.
	 *
	 * Sin este gate, un pedido en dólares emitiría un CFDI con la misma cifra
	 * expresada en pesos, y CUADRARÍA: el gate de total de build() compara la
	 * suma de las líneas contra el total del pedido, que es el mismo número en
	 * la misma unidad equivocada. Es la forma de fallo del 0 % de IVA: un
	 * desglose incorrecto suma igual que uno correcto.
	 *
	 * @param string $currency Código ISO de la moneda del pedido.
	 * @return WP_Error|null Null si se puede facturar.
	 */
	public static function currency_error( $currency ) {
		$code = strtoupper( trim( (string) $currency ) );

		if ( self::CURRENCY === $code ) {
			return null;
		}

		return new WP_Error(
			'facturamx_currency_unsupported',
			sprintf(
				/* translators: 1: moneda del pedido, 2: moneda exigida. */
				__( 'Este pedido está en %1$s y el CFDI solo se puede emitir en %2$s. Este pedido se factura a mano.', 'facturamx-for-woocommerce' ),
				'' === $code ? __( 'una moneda sin declarar', 'facturamx-for-woocommerce' ) : $code,
				self::CURRENCY
			)
		);
	}

	/**
	 * Lee un pedido de WooCommerce y devuelve el payload CFDI.
	 *
	 * El pedido se lee SOLO por la API de WC_Order. Nunca por consulta directa a
	 * wp_postmeta, porque con HPOS los pedidos no están en `posts` y la consulta
	 * directa devolvería vacío sin dar error.
	 *
	 * @param WC_Order $order    Pedido a facturar.
	 * @param array    $customer Datos fiscales del receptor, ya validados (S1.5).
	 * @param array    $opts     Sobreescribe payment_form, series, use, external_id.
	 * @return array|WP_Error
	 */
	public static function map( $order, $customer, $opts = array() ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'facturamx_invalid_order',
				__( 'No se encontró el pedido que se quiere facturar.', 'facturamx-for-woocommerce' )
			);
		}

		// La moneda va antes que nada: si no es MXN no hay aritmética que valga.
		$currency_error = self::currency_error( $order->get_currency() );

		if ( $currency_error ) {
			return $currency_error;
		}

		// Un pedido reembolsado cuadra igual que uno intacto —lo comprobó la
		// sonda—, así que la aritmética no protege de esto. Facturar el importe
		// original sería emitir un CFDI por dinero que el cliente no pagó.
		$refunded = (float) $order->get_total_refunded();

		if ( $refunded > 0 ) {
			return new WP_Error(
				'facturamx_order_refunded',
				sprintf(
					/* translators: %s: importe reembolsado. */
					__( 'Este pedido tiene un reembolso de %s MXN. No se puede facturar automáticamente.', 'facturamx-for-woocommerce' ),
					number_format( $refunded, 2, '.', '' )
				)
			);
		}

		$default_product_key = (string) FacturaMX_Settings::get( 'default_product_key' );
		$default_unit_key    = (string) FacturaMX_Settings::get( 'default_unit_key' );
		$default_unit_name   = (string) FacturaMX_Settings::get( 'default_unit_name' );

		$lines = array();

		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$type = $item->get_type();
			$net  = (float) $item->get_total();
			$tax  = (float) $item->get_total_tax();

			// Envío gratis y comisiones a cero no aportan nada al CFDI y ensucian
			// el documento. Las líneas de producto a cero sí se conservan: un
			// regalo forma parte de lo que el cliente se llevó.
			if ( 'line_item' !== $type && 0.0 === $net && 0.0 === $tax ) {
				continue;
			}

			if ( 'line_item' === $type ) {
				$lines[] = self::product_line( $item, $net, $tax, $default_product_key, $default_unit_key, $default_unit_name );
				continue;
			}

			$lines[] = array(
				'description' => $item->get_name(),
				'quantity'    => 1.0,
				'net'         => $net,
				'tax'         => $tax,
				'product_key' => 'shipping' === $type ? self::SHIPPING_PRODUCT_KEY : self::FEE_PRODUCT_KEY,
				'unit_key'    => self::SERVICE_UNIT_KEY,
				'unit_name'   => self::SERVICE_UNIT_NAME,
				'sku'         => '',
			);
		}

		$opts = array_merge(
			array(
				'external_id'      => (string) $order->get_id(),
				'payment_form'     => (string) FacturaMX_Settings::get( 'payment_form' ),
				'series'           => (string) FacturaMX_Settings::get( 'series' ),
				'use'              => '',
				'default_iva_rate' => FacturaMX_Settings::get( 'default_iva_rate' ),
			),
			$opts
		);

		return self::build( $lines, (float) $order->get_total(), $customer, $opts );
	}

	/**
	 * Normaliza una línea de producto, resolviendo sus claves SAT.
	 *
	 * Si el producto se borró del catálogo después de la compra, el pedido
	 * conserva el nombre y los importes: se factura con los valores por defecto
	 * en vez de romper.
	 *
	 * @param WC_Order_Item_Product $item                Línea del pedido.
	 * @param float                 $net                 Importe sin IVA.
	 * @param float                 $tax                 IVA de la línea.
	 * @param string                $default_product_key Clave de producto por defecto.
	 * @param string                $default_unit_key    Clave de unidad por defecto.
	 * @param string                $default_unit_name   Nombre de unidad por defecto.
	 * @return array
	 */
	private static function product_line( $item, $net, $tax, $default_product_key, $default_unit_key, $default_unit_name ) {
		$product     = $item->get_product();
		$product_id  = $product ? $product->get_id() : 0;
		$product_key = $default_product_key;
		$unit_key    = $default_unit_key;
		$unit_name   = $default_unit_name;
		$iva_rate    = '';
		$sku         = $product ? (string) $product->get_sku() : '';

		if ( $product_id ) {
			$product_key = self::resolve_product_key( get_post_meta( $product_id, self::META_PRODUCT_KEY, true ), $default_product_key );
			$unit_key    = self::resolve_unit_key( get_post_meta( $product_id, self::META_UNIT_KEY, true ), $default_unit_key );
			$iva_rate    = (string) get_post_meta( $product_id, self::META_IVA_RATE, true );

			$meta_unit_name = trim( (string) get_post_meta( $product_id, self::META_UNIT_NAME, true ) );
			$unit_name      = '' !== $meta_unit_name ? $meta_unit_name : $default_unit_name;
		}

		$quantity = (float) $item->get_quantity();

		return array(
			'description' => $item->get_name(),
			'quantity'    => $quantity,
			'net'         => $net,
			'tax'         => $tax,
			'product_key' => $product_key,
			'unit_key'    => $unit_key,
			'unit_name'   => $unit_name,
			'iva_rate'    => $iva_rate,
			'sku'         => $sku,
		);
	}

	/**
	 * Construye el payload a partir de líneas ya normalizadas.
	 *
	 * Cada línea es un array con: description, quantity, net (importe de la línea
	 * según WooCommerce, después de descuentos), tax (impuesto que WooCommerce
	 * calculó), product_key, unit_key, unit_name, iva_rate (tasa declarada en el
	 * producto; cadena vacía si no declara ninguna) y sku.
	 *
	 * Ojo con `net`: es la base imponible SOLO cuando WooCommerce cobró impuesto.
	 * Si no lo cobró, el importe lleva el IVA dentro y split_tax() lo separa.
	 *
	 * @param array $lines       Líneas normalizadas.
	 * @param float $order_total Total del pedido, con IVA. El número contra el que se cuadra.
	 * @param array $customer    Datos fiscales del receptor, ya validados (S1.5).
	 * @param array $opts        external_id, payment_form, series, use, default_iva_rate.
	 * @return array|WP_Error
	 */
	public static function build( $lines, $order_total, $customer, $opts ) {
		if ( empty( $lines ) ) {
			return new WP_Error(
				'facturamx_no_items',
				__( 'El pedido no tiene ninguna línea facturable.', 'facturamx-for-woocommerce' )
			);
		}

		$items = array();

		$default_rate = isset( $opts['default_iva_rate'] ) && '' !== $opts['default_iva_rate']
			? (float) $opts['default_iva_rate']
			: self::DEFAULT_IVA_RATE;

		foreach ( $lines as $line ) {
			$description = isset( $line['description'] ) ? (string) $line['description'] : '';
			$quantity    = isset( $line['quantity'] ) ? (float) $line['quantity'] : 0.0;
			$net         = isset( $line['net'] ) ? (float) $line['net'] : 0.0;
			$tax         = isset( $line['tax'] ) ? (float) $line['tax'] : 0.0;

			if ( $net < 0 || $tax < 0 ) {
				return new WP_Error(
					'facturamx_negative_line',
					sprintf(
						/* translators: %s: nombre de la línea del pedido. */
						__( 'La línea "%s" tiene un importe negativo. El CFDI no admite importes negativos.', 'facturamx-for-woocommerce' ),
						$description
					)
				);
			}

			if ( $quantity <= 0 ) {
				return new WP_Error(
					'facturamx_invalid_quantity',
					sprintf(
						/* translators: %s: nombre de la línea del pedido. */
						__( 'La línea "%s" no tiene una cantidad válida.', 'facturamx-for-woocommerce' ),
						$description
					)
				);
			}

			$declared = self::resolve_declared_rate( isset( $line['iva_rate'] ) ? $line['iva_rate'] : '' );
			$split    = self::split_tax( $net, $tax, $declared, $default_rate );

			if ( is_wp_error( $split ) ) {
				return new WP_Error(
					$split->get_error_code(),
					sprintf(
						/* translators: 1: nombre de la línea, 2: mensaje del error de tasa. */
						__( 'La línea "%1$s" tiene un problema de impuestos: %2$s', 'facturamx-for-woocommerce' ),
						$description,
						$split->get_error_message()
					)
				);
			}

			$item = array(
				'description' => $description,
				'product_key' => isset( $line['product_key'] ) ? (string) $line['product_key'] : '',
				'unit_key'    => isset( $line['unit_key'] ) ? (string) $line['unit_key'] : '',
				'unit_name'   => isset( $line['unit_name'] ) ? (string) $line['unit_name'] : '',
				'quantity'    => $quantity,
				'price'       => round( $split['base'] / $quantity, self::PRICE_DECIMALS ),
				'iva_rate'    => $split['rate'],
			);

			// El SAT solo quiere NoIdentificacion si existe. Enviar cadena vacía
			// es peor que no enviar el campo.
			if ( ! empty( $line['sku'] ) ) {
				$item['no_identificacion'] = (string) $line['sku'];
			}

			$items[] = $item;
		}

		// Gate de cuadre. Jidoka: si el CFDI no va a decir lo mismo que pagó el
		// cliente, no se emite payload — se para y se explica por qué.
		$expected = self::reconcile( $items );

		// La diferencia se redondea al centavo ANTES de compararla: en coma
		// flotante 116.00 - 116.01 da 0.010000000000005, que superaría la
		// tolerancia por un error de representación y no por un descuadre real.
		// Estamos comparando dinero, y el dinero tiene dos decimales.
		$diff = round( $expected - (float) $order_total, 2 );

		if ( abs( $diff ) > self::TOTAL_TOLERANCE ) {
			return new WP_Error(
				'facturamx_total_mismatch',
				sprintf(
					/* translators: 1: total calculado, 2: total del pedido, 3: diferencia. */
					__( 'El total calculado (%1$s) no coincide con el del pedido (%2$s). Diferencia: %3$s MXN.', 'facturamx-for-woocommerce' ),
					number_format( $expected, 2, '.', '' ),
					number_format( (float) $order_total, 2, '.', '' ),
					number_format( abs( $diff ), 2, '.', '' )
				)
			);
		}

		$payload = array(
			'payment_method' => 'PUE',
			'payment_form'   => isset( $opts['payment_form'] ) ? (string) $opts['payment_form'] : '',
			'customer'       => $customer,
			'items'          => $items,
		);

		// Serie y uso vacíos se omiten: FacturaMX aplica entonces los de la
		// organización, que es lo que queremos si aquí no se configuró nada.
		if ( ! empty( $opts['series'] ) ) {
			$payload['series'] = (string) $opts['series'];
		}

		if ( ! empty( $opts['use'] ) ) {
			$payload['use'] = (string) $opts['use'];
		}

		if ( ! empty( $opts['external_id'] ) ) {
			$payload['external_id'] = (string) $opts['external_id'];
		}

		return $payload;
	}

	/**
	 * Convierte el payload de factura (salida de map()/build()) en el cuerpo de
	 * POST /api/public/quotation: el pedido llega a FacturaMX como cotización en
	 * borrador y el comercio la convierte en factura desde el panel. Pura.
	 *
	 * Precios SIN IVA, como en la factura. external_id = id del pedido: la factura
	 * que salga de la cotización lo hereda y el portal ya no ofrece timbrarlo.
	 *
	 * @param array $payload Payload de factura.
	 * @return array
	 */
	public static function to_quotation( array $payload ) {
		$items = array();
		foreach ( isset( $payload['items'] ) ? (array) $payload['items'] : array() as $it ) {
			$item = array(
				'description' => (string) $it['description'],
				'quantity'    => $it['quantity'],
				'price'       => $it['price'],
				'iva_rate'    => $it['iva_rate'],
			);
			foreach ( array( 'product_key', 'unit_key', 'unit_name' ) as $k ) {
				if ( ! empty( $it[ $k ] ) ) {
					$item[ $k ] = (string) $it[ $k ];
				}
			}
			if ( ! empty( $it['no_identificacion'] ) ) {
				$item['sku'] = (string) $it['no_identificacion'];
			}
			$items[] = $item;
		}

		$body = array(
			'external_id'    => isset( $payload['external_id'] ) ? (string) $payload['external_id'] : '',
			'items'          => $items,
			'payment_form'   => isset( $payload['payment_form'] ) ? (string) $payload['payment_form'] : '',
			'payment_method' => isset( $payload['payment_method'] ) ? (string) $payload['payment_method'] : 'PUE',
		);

		$c        = isset( $payload['customer'] ) && is_array( $payload['customer'] ) ? $payload['customer'] : array();
		$customer = array_filter(
			array(
				'rfc'        => isset( $c['tax_id'] ) ? (string) $c['tax_id'] : '',
				'name'       => isset( $c['legal_name'] ) ? (string) $c['legal_name'] : '',
				'zip'        => isset( $c['zip'] ) ? (string) $c['zip'] : '',
				'email'      => isset( $c['email'] ) ? (string) $c['email'] : '',
				'tax_regime' => isset( $c['tax_system'] ) ? (string) $c['tax_system'] : '',
				'cfdi_use'   => isset( $payload['use'] ) ? (string) $payload['use'] : '',
			),
			'strlen'
		);
		if ( ! empty( $customer['rfc'] ) || ! empty( $customer['name'] ) ) {
			$body['customer'] = $customer;
		}

		return $body;
	}

	/**
	 * Total que tendrá el CFDI, con el mismo redondeo que aplica el documento
	 * fiscal: importe por línea a 2 decimales, IVA por línea a 2 decimales.
	 *
	 * Sumar los importes sin redondear (Σnet + Σtax) desvía hasta 0.004 MXN y
	 * además no describe lo que el SAT verá. Este modelo cuadró exacto en los
	 * cuatro pedidos de la sonda (decisión D2).
	 *
	 * **El IVA se calcula sobre la base SIN redondear**, y esto no es un detalle
	 * de estilo. Es lo que hace Facturapi: recibe `price` con seis decimales y
	 * `tax_included: false`, y aplica la tasa sobre `quantity * price` tal cual
	 * (`facturacion:src/lib/invoices/stamp.ts:148-151`). Redondear la base antes
	 * de multiplicar inventa hasta un centavo POR LÍNEA que el CFDI no va a
	 * tener:
	 *
	 *     round( 343.97      * 0.16, 2 ) = 55.04   ← base redondeada, mal
	 *     round( 343.9655172 * 0.16, 2 ) = 55.03   ← base exacta, lo que emite
	 *
	 * Los cuatro pedidos de la sonda no lo destaparon porque usaban precios que
	 * sobreviven al redondeo. Un pedido real sí: un producto de 399.00 con IVA
	 * incluido da un neto de 343.9655172. Con cuatro
	 * líneas así el desvío llega a 0.04 y **el gate de cuadre rechazaba un
	 * pedido perfectamente válido**. Ese era el daño, no el céntimo suelto.
	 *
	 * Es público porque la previsualización de S1.4 enseña este mismo número
	 * antes de timbrar.
	 *
	 * @param array $items Items del payload.
	 * @return float Total con IVA, redondeado a 2 decimales.
	 */
	public static function reconcile( $items ) {
		$total = 0.0;

		foreach ( $items as $item ) {
			$base    = $item['quantity'] * $item['price'];
			$total  += round( $base, 2 ) + round( $base * $item['iva_rate'], 2 );
		}

		return round( $total, 2 );
	}

	/**
	 * Reparte el importe de una línea entre base imponible e IVA.
	 *
	 * Tres caminos, en este orden:
	 *
	 *   1. WooCommerce cobró impuesto → se deriva la tasa de lo que cobró y el
	 *      importe de la línea YA es la base. Hay dinero real en ese reparto:
	 *      manda sobre cualquier declaración, porque cambiarlo descuadraría el
	 *      total contra lo que pagó el cliente.
	 *   2. No cobró impuesto y el producto declara una tasa → se usa esa. Es la
	 *      salida para lo genuinamente exento (alimentos, medicinas), y para el
	 *      8 % de la franja fronteriza.
	 *   3. No cobró impuesto y nadie declaró nada → la tasa por defecto, extraída
	 *      del importe. Lo que se vendió lleva IVA y el precio ya lo incluía.
	 *
	 * En 2 y 3 el total de la línea NO cambia: solo cambia cómo se parte. 199.00
	 * al 16 % son 171.551724 de base y 27.45 de IVA, que suman 199.00 otra vez.
	 * Por eso el gate de cuadre sigue dando el total del pedido.
	 *
	 * @param float      $net          Importe de la línea según WooCommerce.
	 * @param float      $tax          Impuesto que WooCommerce calculó.
	 * @param float|null $declared     Tasa declarada en el producto, o null.
	 * @param float      $default_rate Tasa por defecto de la tienda.
	 * @return array{base: float, rate: float}|WP_Error
	 */
	public static function split_tax( $net, $tax, $declared, $default_rate ) {
		$net = (float) $net;
		$tax = (float) $tax;

		if ( abs( $tax ) >= 0.0001 ) {
			$rate = self::resolve_tax_rate( $net, $tax );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			return array(
				'base' => $net,
				'rate' => $rate,
			);
		}

		$rate = null !== $declared ? (float) $declared : (float) $default_rate;

		if ( $rate <= 0 ) {
			return array(
				'base' => $net,
				'rate' => 0.0,
			);
		}

		return array(
			'base' => $net / ( 1 + $rate ),
			'rate' => $rate,
		);
	}

	/**
	 * Interpreta la tasa declarada en la meta del producto.
	 *
	 * Devuelve null cuando no hay declaración —o cuando la que hay no es válida—,
	 * que es lo que hace que se caiga a la tasa por defecto. Una meta corrupta se
	 * ignora en silencio, con el mismo criterio que resolve_product_key(): la
	 * pestaña del producto ya impide guardarla, y equivocarse aquí no justifica
	 * bloquear una factura.
	 *
	 * @param string $meta Valor de la meta del producto.
	 * @return float|null
	 */
	public static function resolve_declared_rate( $meta ) {
		if ( is_array( $meta ) || null === $meta || '' === trim( (string) $meta ) ) {
			return null;
		}

		$valid = FacturaMX_Validator::iva_rate( $meta );

		if ( is_wp_error( $valid ) || '' === $valid ) {
			return null;
		}

		return (float) $valid;
	}

	/**
	 * Elige la tasa del catálogo del SAT que corresponde a lo que WooCommerce
	 * calculó en esta línea.
	 *
	 * @param float $net Importe de la línea sin IVA.
	 * @param float $tax IVA de la línea.
	 * @return float|WP_Error
	 */
	public static function resolve_tax_rate( $net, $tax ) {
		$net = (float) $net;
		$tax = (float) $tax;

		if ( $net <= 0 ) {
			// Sin base imponible no hay tasa que derivar. Cero impuesto es
			// coherente; impuesto sobre nada, no.
			if ( abs( $tax ) < 0.0001 ) {
				return 0.0;
			}

			return new WP_Error(
				'facturamx_unknown_tax_rate',
				__( 'hay impuesto sin base imponible sobre la que calcularlo.', 'facturamx-for-woocommerce' )
			);
		}

		$derived = $tax / $net;

		foreach ( self::TAX_RATES as $rate ) {
			if ( abs( $derived - $rate ) <= self::RATE_TOLERANCE ) {
				return $rate;
			}
		}

		return new WP_Error(
			'facturamx_unknown_tax_rate',
			sprintf(
				/* translators: %s: porcentaje de impuesto calculado. */
				__( 'la tasa de impuesto es del %s %% y no corresponde a ninguna tasa del SAT.', 'facturamx-for-woocommerce' ),
				number_format( $derived * 100, 2, '.', '' )
			)
		);
	}

	/**
	 * Clave de producto del SAT: la meta del producto manda sobre el valor por
	 * defecto de los ajustes. Una meta inválida se ignora en silencio en favor
	 * del default — el metabox ya impide guardarla, y equivocarse aquí no
	 * justifica bloquear una factura.
	 *
	 * @param string $meta    Valor de la meta del producto.
	 * @param string $default Valor por defecto de los ajustes.
	 * @return string
	 */
	public static function resolve_product_key( $meta, $default ) {
		$valid = FacturaMX_Validator::sat_product_key( $meta );

		return is_wp_error( $valid ) ? (string) $default : $valid;
	}

	/**
	 * Clave de unidad del SAT, con la misma regla que resolve_product_key().
	 *
	 * @param string $meta    Valor de la meta del producto.
	 * @param string $default Valor por defecto de los ajustes.
	 * @return string
	 */
	public static function resolve_unit_key( $meta, $default ) {
		$valid = FacturaMX_Validator::sat_unit_key( $meta );

		return is_wp_error( $valid ) ? (string) $default : $valid;
	}
}
