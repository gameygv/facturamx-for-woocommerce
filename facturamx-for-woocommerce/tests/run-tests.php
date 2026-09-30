<?php
/**
 * Runner de tests sin composer ni PHPUnit.
 *
 * Solo prueba lógica pura (validación y saneado). Todo lo que necesita
 * WordPress se verifica a mano — ver s1.1-plan.md § T4.
 *
 * Uso:  php tests/run-tests.php
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../includes/class-facturamx-validator.php';
require_once __DIR__ . '/../includes/class-facturamx-settings.php';
require_once __DIR__ . '/../includes/class-facturamx-settings-page.php';
require_once __DIR__ . '/../includes/class-facturamx-order-mapper.php';
require_once __DIR__ . '/../includes/class-facturamx-product-fields.php';
require_once __DIR__ . '/../includes/class-facturamx-client.php';
require_once __DIR__ . '/../includes/class-facturamx-receptor.php';
require_once __DIR__ . '/../includes/class-facturamx-invoice.php';
require_once __DIR__ . '/../includes/class-facturamx-order-metabox.php';
require_once __DIR__ . '/../includes/class-facturamx-eligibility.php';
require_once __DIR__ . '/../includes/class-facturamx-download.php';
require_once __DIR__ . '/../includes/class-facturamx-portal.php';
require_once __DIR__ . '/../includes/class-facturamx-readiness.php';

$GLOBALS['facturamx_tests']  = array();
$GLOBALS['facturamx_group']  = 'general';

function facturamx_group( $name ) {
	$GLOBALS['facturamx_group'] = $name;
	if ( ! isset( $GLOBALS['facturamx_tests'][ $name ] ) ) {
		$GLOBALS['facturamx_tests'][ $name ] = array(
			'pass'   => 0,
			'fail'   => 0,
			'errors' => array(),
		);
	}
}

/** Comprueba que el valor devuelto es exactamente el esperado. */
function facturamx_is( $actual, $expected, $label ) {
	$group = $GLOBALS['facturamx_group'];
	if ( $actual === $expected ) {
		$GLOBALS['facturamx_tests'][ $group ]['pass']++;
		return;
	}
	$GLOBALS['facturamx_tests'][ $group ]['fail']++;
	$GLOBALS['facturamx_tests'][ $group ]['errors'][] = sprintf(
		'%s — esperaba %s, obtuvo %s',
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/** Comprueba que el valor devuelto es un WP_Error con el código esperado. */
function facturamx_err( $actual, $expected_code, $label ) {
	$group = $GLOBALS['facturamx_group'];
	if ( is_wp_error( $actual ) && $actual->get_error_code() === $expected_code ) {
		$GLOBALS['facturamx_tests'][ $group ]['pass']++;
		return;
	}
	$GLOBALS['facturamx_tests'][ $group ]['fail']++;
	$GLOBALS['facturamx_tests'][ $group ]['errors'][] = sprintf(
		'%s — esperaba WP_Error(%s), obtuvo %s',
		$label,
		$expected_code,
		is_wp_error( $actual ) ? 'WP_Error(' . $actual->get_error_code() . ')' : var_export( $actual, true )
	);
}

// ---------------------------------------------------------------------------
// FacturaMX_Validator::url
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::url' );

facturamx_is( FacturaMX_Validator::url( 'https://facturamx.top' ), 'https://facturamx.top', 'url simple' );
facturamx_is( FacturaMX_Validator::url( 'https://facturamx.top/' ), 'https://facturamx.top', 'quita barra final' );
facturamx_is( FacturaMX_Validator::url( '  https://facturamx.top  ' ), 'https://facturamx.top', 'recorta espacios' );
facturamx_err( FacturaMX_Validator::url( 'no-es-una-url' ), 'facturamx_invalid_url', 'texto suelto' );
facturamx_err( FacturaMX_Validator::url( 'ftp://facturamx.top' ), 'facturamx_invalid_url', 'esquema no permitido' );
facturamx_err( FacturaMX_Validator::url( '' ), 'facturamx_invalid_url', 'vacia' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::sat_product_key
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::sat_product_key' );

facturamx_is( FacturaMX_Validator::sat_product_key( '01010101' ), '01010101', 'clave valida' );
facturamx_is( FacturaMX_Validator::sat_product_key( ' 50202306 ' ), '50202306', 'recorta espacios' );
facturamx_err( FacturaMX_Validator::sat_product_key( '123' ), 'facturamx_invalid_product_key', 'demasiado corta' );
facturamx_err( FacturaMX_Validator::sat_product_key( '0101010a' ), 'facturamx_invalid_product_key', 'con letra' );
facturamx_err( FacturaMX_Validator::sat_product_key( '' ), 'facturamx_invalid_product_key', 'vacia' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::sat_unit_key
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::sat_unit_key' );

facturamx_is( FacturaMX_Validator::sat_unit_key( 'H87' ), 'H87', 'clave valida' );
facturamx_is( FacturaMX_Validator::sat_unit_key( 'h87' ), 'H87', 'normaliza a mayusculas' );
facturamx_is( FacturaMX_Validator::sat_unit_key( 'E48' ), 'E48', 'unidad de servicio' );
facturamx_err( FacturaMX_Validator::sat_unit_key( 'DEMASIADO' ), 'facturamx_invalid_unit_key', 'demasiado larga' );
facturamx_err( FacturaMX_Validator::sat_unit_key( '' ), 'facturamx_invalid_unit_key', 'vacia' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::payment_form
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::payment_form' );

facturamx_is( FacturaMX_Validator::payment_form( '03' ), '03', 'transferencia' );
facturamx_is( FacturaMX_Validator::payment_form( '3' ), '03', 'normaliza a dos digitos' );
facturamx_is( FacturaMX_Validator::payment_form( '99' ), '99', 'por definir' );
facturamx_err( FacturaMX_Validator::payment_form( 'AB' ), 'facturamx_invalid_payment_form', 'con letras' );
facturamx_err( FacturaMX_Validator::payment_form( '' ), 'facturamx_invalid_payment_form', 'vacia' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::token
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::token' );

// El formato lo decide FacturaMX (src/actions/api-token.ts: `fmx_live_` + 64 hex).
// S2.2 lo renombró por error a `facturamx_live_` con el resto de prefijos, y los
// tests lo siguieron: ningún token real se podía guardar.
$good_token = 'fmx_live_' . str_repeat( 'a', 64 );

facturamx_is( FacturaMX_Validator::token( $good_token ), $good_token, 'token valido' );
facturamx_is( FacturaMX_Validator::token( '  ' . $good_token . '  ' ), $good_token, 'recorta espacios' );
facturamx_err( FacturaMX_Validator::token( 'abc' ), 'facturamx_invalid_token', 'demasiado corto' );
facturamx_err( FacturaMX_Validator::token( 'otro_prefijo_' . str_repeat( 'a', 64 ) ), 'facturamx_invalid_token', 'prefijo incorrecto' );
facturamx_err( FacturaMX_Validator::token( '' ), 'facturamx_invalid_token', 'vacio' );
facturamx_err( FacturaMX_Validator::token( 'facturamx_live_' . str_repeat( 'a', 64 ) ), 'facturamx_invalid_token', 'el prefijo renombrado por S2.2 no existe en FacturaMX' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::series
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::series' );

facturamx_is( FacturaMX_Validator::series( 'WEB' ), 'WEB', 'serie valida' );
facturamx_is( FacturaMX_Validator::series( 'web' ), 'WEB', 'normaliza a mayusculas' );
facturamx_is( FacturaMX_Validator::series( '' ), '', 'vacia es valida (se delega en la organizacion)' );
facturamx_err( FacturaMX_Validator::series( 'WEB-2' ), 'facturamx_invalid_series', 'caracter no alfanumerico' );

// ---------------------------------------------------------------------------
// FacturaMX_Validator::iva_rate
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Validator::iva_rate' );

facturamx_is( FacturaMX_Validator::iva_rate( '16' ), '0.16', '16 se entiende como porcentaje' );
facturamx_is( FacturaMX_Validator::iva_rate( '16%' ), '0.16', 'el simbolo de porcentaje se admite' );
facturamx_is( FacturaMX_Validator::iva_rate( '0.16' ), '0.16', 'la fraccion se admite tal cual' );
facturamx_is( FacturaMX_Validator::iva_rate( '8' ), '0.08', 'franja fronteriza' );
facturamx_is( FacturaMX_Validator::iva_rate( '0' ), '0', 'cero es una declaracion valida, no un vacio' );
facturamx_is( FacturaMX_Validator::iva_rate( '' ), '', 'vacio significa «no declarado»' );
facturamx_err( FacturaMX_Validator::iva_rate( '12' ), 'facturamx_invalid_iva_rate', 'una tasa que no existe en el catalogo del SAT' );
facturamx_err( FacturaMX_Validator::iva_rate( 'dieciseis' ), 'facturamx_invalid_iva_rate', 'texto que no es un numero' );

// ---------------------------------------------------------------------------
// FacturaMX_Settings::mask
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Settings::mask' );

facturamx_is( FacturaMX_Settings::mask( 'fmx_live_' . str_repeat( 'a', 60 ) . 'ffd0' ), '••••ffd0', 'muestra los ultimos cuatro' );
facturamx_is( FacturaMX_Settings::mask( '' ), '', 'vacio no se enmascara' );
facturamx_is( FacturaMX_Settings::mask( 'abc' ), '••••', 'demasiado corto para revelar nada' );

// ---------------------------------------------------------------------------
// FacturaMX_Settings_Page::interpret_ping
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Settings_Page::interpret_ping' );

$counts = array(
	'tax_regimes' => 22,
	'cfdi_uses'   => 24,
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 200, 422, $counts ),
	array( 'ok' => true, 'message' => 'Conexión correcta · 22 regímenes fiscales, 24 usos de CFDI' ),
	'exito: catalogos ok y token aceptado'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 200, 429, $counts ),
	array( 'ok' => true, 'message' => 'Conexión correcta · 22 regímenes fiscales, 24 usos de CFDI' ),
	'429 tambien prueba el token: el limite se aplica despues de autenticar'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 200, 401, $counts ),
	array( 'ok' => false, 'message' => 'El servidor rechazó el token (401). Revísalo en FacturaMX.' ),
	'token rechazado'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 'cURL error 6', null, array() ),
	array( 'ok' => false, 'message' => 'No se pudo contactar con el servidor: cURL error 6.' ),
	'host inalcanzable'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 404, null, array() ),
	array( 'ok' => false, 'message' => 'La URL responde, pero no parece una API de FacturaMX.' ),
	'la url no es una api de facturamx'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 200, null, array() ),
	array( 'ok' => false, 'message' => 'La URL responde, pero no parece una API de FacturaMX.' ),
	'responde 200 pero sin catalogos'
);

facturamx_is(
	FacturaMX_Settings_Page::interpret_ping( 200, 500, $counts ),
	array( 'ok' => false, 'message' => 'El servidor respondió de forma inesperada al comprobar el token (500).' ),
	'respuesta inesperada: no se afirma exito'
);

// ---------------------------------------------------------------------------
// FacturaMX_Order_Mapper::resolve_tax_rate
//
// Los pares (net, tax) son valores REALES leídos de pedidos de WooCommerce en
// el entorno local — ver dev/probes/reconcile.php. Ninguna tasa derivada da
// 0.16 exacto, por eso la derivación elige la tasa del catálogo del SAT en vez
// de transmitirse tal cual (decisión D3).
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Mapper::resolve_tax_rate' );

facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 236.327587, 37.81 ), 0.16, 'derivada 0.15999 → 0.16' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 86.206897, 13.79 ), 0.16, 'derivada 0.159964 → 0.16' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 201.12931, 32.18 ), 0.16, 'derivada 0.159997 → 0.16' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 172.413793, 27.59 ), 0.16, 'derivada 0.160022 → 0.16' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 500.0, 0.0 ), 0.0, 'linea exenta' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 100.0, 8.0 ), 0.08, 'tasa frontera 8 %' );
facturamx_is( FacturaMX_Order_Mapper::resolve_tax_rate( 0.0, 0.0 ), 0.0, 'linea a coste cero' );

facturamx_err( FacturaMX_Order_Mapper::resolve_tax_rate( 100.0, 7.0 ), 'facturamx_unknown_tax_rate', '7 % no esta en el catalogo' );
facturamx_err( FacturaMX_Order_Mapper::resolve_tax_rate( 100.0, 12.0 ), 'facturamx_unknown_tax_rate', '12 % no esta en el catalogo' );
facturamx_err( FacturaMX_Order_Mapper::resolve_tax_rate( 0.0, 5.0 ), 'facturamx_unknown_tax_rate', 'impuesto sin base' );

// ---------------------------------------------------------------------------
// FacturaMX_Order_Mapper::build — los cuatro pedidos de la sonda
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Mapper::build' );

$facturamx_customer = array(
	'legal_name' => 'PUBLICO EN GENERAL',
	'tax_id'     => 'XAXX010101000',
	'tax_system' => '616',
	'zip'        => '45050',
);

$facturamx_opts = array(
	'external_id'  => '27',
	'payment_form' => '03',
	'series'       => 'WEB',
	'use'          => 'G03',
);

/**
 * Línea normalizada tal y como la produce map().
 *
 * `$iva_rate` es lo que el producto declara en su meta `_facturamx_iva_rate`. Vacío
 * —el caso normal— significa «no declarado».
 */
function facturamx_line( $desc, $qty, $net, $tax, $key = '01010101', $sku = '', $iva_rate = '' ) {
	return array(
		'description' => $desc,
		'quantity'    => $qty,
		'net'         => $net,
		'tax'         => $tax,
		'product_key' => $key,
		'unit_key'    => 'H87',
		'unit_name'   => 'Pieza',
		'iva_rate'    => $iva_rate,
		'sku'         => $sku,
	);
}

// Caso A · cupón 10 % + envío → get_total() = 332.14
$facturamx_a = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Café de altura', 3.0, 236.327587, 37.81, '50202306', 'CAFE-001' ),
		facturamx_line( 'Envío', 1.0, 50.0, 8.0, '78102200' ),
	),
	332.14,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_a ), false, 'A · devuelve payload, no error' );
facturamx_is( $facturamx_a['items'][0]['price'], 78.775862, 'A · precio unitario a 6 decimales' );
facturamx_is( $facturamx_a['items'][0]['iva_rate'], 0.16, 'A · tasa ajustada al catalogo' );
facturamx_is( $facturamx_a['items'][0]['no_identificacion'], 'CAFE-001', 'A · sku en no_identificacion' );
facturamx_is( isset( $facturamx_a['items'][1]['no_identificacion'] ), false, 'A · sin sku, la clave no aparece' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_a['items'] ), 332.14, 'A · el cuadre CFDI da el total del pedido' );
facturamx_is( $facturamx_a['external_id'], '27', 'A · external_id del pedido' );
facturamx_is( $facturamx_a['payment_method'], 'PUE', 'A · payment_method PUE' );
facturamx_is( $facturamx_a['series'], 'WEB', 'A · serie' );
facturamx_is( $facturamx_a['customer'], $facturamx_customer, 'A · receptor tal cual' );

// Caso B · gravado + exento DECLARADO → get_total() = 600.00
//
// La exención es explícita: el producto declara 0 en su meta. Ese es el único
// camino por el que una línea puede salir sin IVA, y es deliberado — ver el
// caso B2, que es la misma línea sin declarar nada.
$facturamx_b = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Café de altura', 1.0, 86.206897, 13.79 ),
		facturamx_line( 'Libro (exento)', 2.0, 500.0, 0.0, '01010101', '', '0' ),
	),
	600.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_b ), false, 'B · devuelve payload' );
facturamx_is( $facturamx_b['items'][1]['iva_rate'], 0.0, 'B · la linea exenta declarada lleva 0.0, no 0.16' );
facturamx_is( $facturamx_b['items'][1]['price'], 250.0, 'B · precio de la linea exenta' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_b['items'] ), 600.00, 'B · cuadre con linea exenta' );

// Caso B2 · la MISMA línea sin declarar nada → ya no se asume exenta.
//
// Que WooCommerce no calcule impuesto no es una postura fiscal: es una tienda
// mal configurada, o los impuestos apagados. La ley dice que lo que se vende
// lleva IVA y que el precio ya lo incluye, así que se le extrae. El total del
// pedido NO cambia — solo cambia cómo se parte:
//
//   declarado exento:   500.00 de base +   0.00 de IVA = 500.00
//   sin declarar:       431.03 de base +  68.97 de IVA = 500.00
$facturamx_b2 = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Café de altura', 1.0, 86.206897, 13.79 ),
		facturamx_line( 'Libro (sin declarar)', 2.0, 500.0, 0.0 ),
	),
	600.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_b2 ), false, 'B2 · devuelve payload' );
facturamx_is( $facturamx_b2['items'][1]['iva_rate'], 0.16, 'B2 · sin declaracion se aplica la tasa por defecto, no se asume exento' );
facturamx_is( $facturamx_b2['items'][1]['price'], 215.517241, 'B2 · el IVA se extrae del precio: 500 / 1.16 / 2' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_b2['items'] ), 600.00, 'B2 · el total del pedido no se mueve: solo cambia el reparto' );

// La tasa por defecto es configurable: la franja fronteriza factura al 8 %.
$facturamx_frontera = FacturaMX_Order_Mapper::build(
	array( facturamx_line( 'Producto de frontera', 1.0, 108.0, 0.0 ) ),
	108.00,
	$facturamx_customer,
	array_merge( $facturamx_opts, array( 'default_iva_rate' => '0.08' ) )
);

facturamx_is( $facturamx_frontera['items'][0]['iva_rate'], 0.08, 'frontera · la tasa por defecto sale de los ajustes' );
facturamx_is( $facturamx_frontera['items'][0]['price'], 100.0, 'frontera · 108 / 1.08 = 100 de base' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_frontera['items'] ), 108.00, 'frontera · cuadra al 8 %' );

// Lo que WooCommerce SÍ cobró manda sobre la declaración: ahí hay dinero real y
// cambiarlo descuadraría el total contra lo que pagó el cliente.
$facturamx_woo_manda = FacturaMX_Order_Mapper::build(
	array( facturamx_line( 'Producto gravado pero declarado exento', 1.0, 100.0, 16.0, '01010101', '', '0' ) ),
	116.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( $facturamx_woo_manda['items'][0]['iva_rate'], 0.16, 'precedencia · el impuesto cobrado por Woo gana a la meta del producto' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_woo_manda['items'] ), 116.00, 'precedencia · cuadra con lo que pago el cliente' );

// Una meta corrupta no bloquea la factura: se cae a la tasa por defecto, con el
// mismo criterio que resolve_product_key().
facturamx_is( FacturaMX_Order_Mapper::resolve_declared_rate( '' ), null, 'declarada · vacio es «no declarado»' );
facturamx_is( FacturaMX_Order_Mapper::resolve_declared_rate( '0' ), 0.0, 'declarada · cero es una declaracion, no un vacio' );
facturamx_is( FacturaMX_Order_Mapper::resolve_declared_rate( '16' ), 0.16, 'declarada · porcentaje' );
facturamx_is( FacturaMX_Order_Mapper::resolve_declared_rate( 'basura' ), null, 'declarada · meta invalida se ignora, no rompe' );

// Caso C · precio impar ×7 + comisión → get_total() = 250.71
$facturamx_c = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Producto impar', 7.0, 201.12931, 32.18 ),
		facturamx_line( 'Gestión', 1.0, 15.0, 2.4, '84111506' ),
	),
	250.71,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_c ), false, 'C · devuelve payload' );
facturamx_is( $facturamx_c['items'][0]['price'], 28.732759, 'C · precio no representable en 2 decimales' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_c['items'] ), 250.71, 'C · cuadre con precio impar y comision' );

// Caso D · el mismo pedido del reembolso, visto solo por sus líneas → 200.00
$facturamx_d = FacturaMX_Order_Mapper::build(
	array( facturamx_line( 'Café de altura', 2.0, 172.413793, 27.59 ) ),
	200.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_d ), false, 'D · devuelve payload' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_d['items'] ), 200.00, 'D · cuadra pese al reembolso — por eso hace falta el guard de map()' );

// Caso E · un pedido REAL de una tienda: una línea de la clase estándar
// (16 %) y otra de una clase que en esa tienda no tiene tasa configurada
// y por eso WooCommerce le cobró 0. No se obedece: se le extrae el IVA.
//
// Los cuatro casos anteriores usan precios que sobreviven al redondeo, así que no
// distinguían entre calcular el IVA sobre la base exacta o sobre la base ya
// redondeada. Este sí: 399.00 MXN con IVA incluido da un neto de 343.9655172, y
// ahí las dos formas se separan un centavo.
//
//   sobre la base redondeada:  round( 343.97       * 0.16, 2 ) = 55.04  → 399.01
//   sobre la base exacta:      round( 343.9655172  * 0.16, 2 ) = 55.03  → 399.00
//
// La segunda es la que vale, porque es la que hace Facturapi: recibe el precio
// con seis decimales y `tax_included: false`, y calcula el impuesto sobre
// `quantity * price` sin redondear (facturacion:src/lib/invoices/stamp.ts:148-151).
// La primera inventa un centavo que el CFDI no va a tener.
$facturamx_e = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Producto A 23ml 3 Pack', 1.0, 343.9655172, 55.0344828 ),
		facturamx_line( 'Producto B 50 gr', 1.0, 199.00, 0.00 ),
	),
	598.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_e ), false, 'E · devuelve payload' );
facturamx_is( $facturamx_e['items'][0]['iva_rate'], 0.16, 'E · la línea de clase estándar deriva 16 %' );
facturamx_is( $facturamx_e['items'][1]['iva_rate'], 0.16, 'E · una clase de impuesto sin tasa configurada NO exime: se le extrae el IVA' );
facturamx_is( $facturamx_e['items'][1]['price'], 171.551724, 'E · 199.00 con IVA incluido son 171.551724 de base' );
facturamx_is( FacturaMX_Order_Mapper::reconcile( $facturamx_e['items'] ), 598.00, 'E · el IVA se calcula sobre la base exacta, como Facturapi — no sobre la redondeada' );

// Cuatro líneas del mismo producto: el centavo por línea se acumula y, calculado
// sobre la base redondeada, rompería el gate de cuadre y bloquearía una factura
// perfectamente válida. Es el daño real del defecto, no el céntimo suelto.
$facturamx_e2 = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Producto A 23ml 3 Pack', 1.0, 343.9655172, 55.0344828 ),
		facturamx_line( 'Producto A 23ml 3 Pack (b)', 1.0, 343.9655172, 55.0344828 ),
		facturamx_line( 'Producto A 23ml 3 Pack (c)', 1.0, 343.9655172, 55.0344828 ),
		facturamx_line( 'Producto A 23ml 3 Pack (d)', 1.0, 343.9655172, 55.0344828 ),
	),
	1596.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_is( is_wp_error( $facturamx_e2 ), false, 'E2 · cuatro líneas iguales NO rompen el gate de cuadre' );
facturamx_is(
	is_wp_error( $facturamx_e2 ) ? $facturamx_e2->get_error_message() : FacturaMX_Order_Mapper::reconcile( $facturamx_e2['items'] ),
	1596.00,
	'E2 · el error no se acumula línea a línea'
);

// Serie vacía: se omite para que FacturaMX use la de la organización.
$facturamx_sin_serie = FacturaMX_Order_Mapper::build(
	array( facturamx_line( 'Café de altura', 1.0, 86.206897, 13.79 ) ),
	100.00,
	$facturamx_customer,
	array( 'external_id' => '1', 'payment_form' => '03', 'series' => '', 'use' => 'G03' )
);

facturamx_is( isset( $facturamx_sin_serie['series'] ), false, 'serie vacia se omite del payload' );

// ---------------------------------------------------------------------------
// FacturaMX_Order_Mapper::build — guards (Jidoka: se para, no se timbra)
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Mapper::build guards' );

$facturamx_mismatch = FacturaMX_Order_Mapper::build(
	array(
		facturamx_line( 'Café de altura', 3.0, 236.327587, 37.81 ),
		facturamx_line( 'Envío', 1.0, 50.0, 8.0 ),
	),
	500.00,
	$facturamx_customer,
	$facturamx_opts
);

facturamx_err( $facturamx_mismatch, 'facturamx_total_mismatch', 'descuadre detectado' );
facturamx_is(
	is_wp_error( $facturamx_mismatch ) && false !== strpos( $facturamx_mismatch->get_error_message(), '167.86' ),
	true,
	'el mensaje dice la diferencia en pesos'
);

facturamx_err(
	FacturaMX_Order_Mapper::build(
		array( facturamx_line( 'Descuento especial', 1.0, -25.0, 0.0 ) ),
		-25.00,
		$facturamx_customer,
		$facturamx_opts
	),
	'facturamx_negative_line',
	'linea negativa aborta'
);

facturamx_err(
	FacturaMX_Order_Mapper::build(
		array( facturamx_line( 'Raro', 1.0, 100.0, 7.0 ) ),
		107.00,
		$facturamx_customer,
		$facturamx_opts
	),
	'facturamx_unknown_tax_rate',
	'tasa fuera del catalogo aborta'
);

facturamx_err(
	FacturaMX_Order_Mapper::build( array(), 0.0, $facturamx_customer, $facturamx_opts ),
	'facturamx_no_items',
	'pedido sin lineas facturables'
);

facturamx_err(
	FacturaMX_Order_Mapper::build(
		array( facturamx_line( 'Café de altura', 0.0, 100.0, 16.0 ) ),
		116.00,
		$facturamx_customer,
		$facturamx_opts
	),
	'facturamx_invalid_quantity',
	'cantidad cero'
);

// Tolerancia: un centavo de deriva se acepta, dos no.
facturamx_is(
	is_wp_error( FacturaMX_Order_Mapper::build( array( facturamx_line( 'X', 1.0, 100.0, 16.0 ) ), 116.01, $facturamx_customer, $facturamx_opts ) ),
	false,
	'un centavo de deriva entra en tolerancia'
);
facturamx_err(
	FacturaMX_Order_Mapper::build( array( facturamx_line( 'X', 1.0, 100.0, 16.0 ) ), 116.02, $facturamx_customer, $facturamx_opts ),
	'facturamx_total_mismatch',
	'dos centimos ya no'
);

// ---------------------------------------------------------------------------
// FacturaMX_Order_Mapper::resolve_product_key
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Mapper::resolve_product_key' );

facturamx_is( FacturaMX_Order_Mapper::resolve_product_key( '50202306', '01010101' ), '50202306', 'la meta del producto manda' );
facturamx_is( FacturaMX_Order_Mapper::resolve_product_key( '', '01010101' ), '01010101', 'sin meta, el default global' );
facturamx_is( FacturaMX_Order_Mapper::resolve_product_key( '  50202306  ', '01010101' ), '50202306', 'recorta espacios' );
facturamx_is( FacturaMX_Order_Mapper::resolve_product_key( 'basura', '01010101' ), '01010101', 'meta invalida cae al default' );

// ---------------------------------------------------------------------------
// FacturaMX_Order_Mapper::currency_error
//
// El CFDI se emite en pesos. Si el pedido está en otra divisa, `get_total()`
// devuelve un número que NO son pesos, y el gate de cuadre lo daría por bueno
// porque compara ese mismo número consigo mismo: 120.00 USD saldría como
// 120.00 MXN y la diferencia sería 0.00. Ningún test aritmético lo detecta,
// igual que ninguno detectaba el 0 % de IVA heredado (T3-bis de S1.6).
//
// Se rechaza, no se convierte (D1): convertir exige un tipo de cambio y una
// fecha, y el documento resultante afirmaría algo que el comerciante no ha
// decidido.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Mapper::currency_error' );

facturamx_is( FacturaMX_Order_Mapper::currency_error( 'MXN' ), null, 'pesos pasa' );
facturamx_is( FacturaMX_Order_Mapper::currency_error( 'mxn' ), null, 'minusculas pasa: WooCommerce guarda mayusculas, pero rechazarlo seria un falso positivo' );
facturamx_is( FacturaMX_Order_Mapper::currency_error( '  MXN  ' ), null, 'espacios alrededor pasan' );

facturamx_err( FacturaMX_Order_Mapper::currency_error( 'USD' ), 'facturamx_currency_unsupported', 'dolares se rechaza' );
facturamx_err( FacturaMX_Order_Mapper::currency_error( 'EUR' ), 'facturamx_currency_unsupported', 'euros se rechaza' );

// Jidoka: ante un hecho que falta, se para. Suponer que "vacio es MXN" es
// exactamente como empezo este defecto.
facturamx_err( FacturaMX_Order_Mapper::currency_error( '' ), 'facturamx_currency_unsupported', 'moneda vacia se rechaza' );
facturamx_err( FacturaMX_Order_Mapper::currency_error( '   ' ), 'facturamx_currency_unsupported', 'solo espacios se rechaza' );
facturamx_err( FacturaMX_Order_Mapper::currency_error( null ), 'facturamx_currency_unsupported', 'null se rechaza' );

// El mensaje nombra las dos monedas: quien lo lee necesita saber en cual esta
// el pedido y cual se exige. No es un mensaje publico, va al comerciante.
$facturamx_cur_err = FacturaMX_Order_Mapper::currency_error( 'USD' );
facturamx_is( is_wp_error( $facturamx_cur_err ) && false !== strpos( $facturamx_cur_err->get_error_message(), 'USD' ), true, 'el mensaje nombra la moneda del pedido' );
facturamx_is( is_wp_error( $facturamx_cur_err ) && false !== strpos( $facturamx_cur_err->get_error_message(), 'MXN' ), true, 'el mensaje nombra la moneda exigida' );

// ---------------------------------------------------------------------------
// FacturaMX_Product_Fields::sanitize
//
// Vacío NO es un error: significa "usa el valor global de los ajustes". Lo que
// se rechaza es un valor puesto a mano que no existe en el catálogo del SAT.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Product_Fields::sanitize' );

facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_PRODUCT_KEY, '50202306' ), '50202306', 'clave de producto valida' );
facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_PRODUCT_KEY, '  50202306 ' ), '50202306', 'recorta espacios' );
facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_PRODUCT_KEY, '' ), '', 'vacio hereda el global' );
facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_PRODUCT_KEY, '   ' ), '', 'solo espacios hereda el global' );
facturamx_err( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_PRODUCT_KEY, '123' ), 'facturamx_invalid_product_key', 'clave corta se rechaza' );

facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_UNIT_KEY, 'h87' ), 'H87', 'unidad normalizada a mayusculas' );
facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_UNIT_KEY, '' ), '', 'unidad vacia hereda el global' );
facturamx_err( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_UNIT_KEY, 'DEMASIADO' ), 'facturamx_invalid_unit_key', 'unidad demasiado larga' );

facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_UNIT_NAME, ' Kilogramo ' ), 'Kilogramo', 'nombre de unidad libre' );
facturamx_is( FacturaMX_Product_Fields::sanitize( FacturaMX_Order_Mapper::META_UNIT_NAME, '' ), '', 'nombre vacio hereda el global' );

facturamx_err( FacturaMX_Product_Fields::sanitize( 'meta_que_no_existe', 'x' ), 'facturamx_unknown_field', 'campo desconocido' );

// ---------------------------------------------------------------------------
// FacturaMX_Product_Fields::add_tab — la clave que comparten todos los plugins
//
// `$tabs` es un array compartido por todo plugin que añada pestañas a la ficha
// de producto: la clave tiene que ser propia, no tres letras. Y el filtro tiene
// que CONSERVAR lo que ya había — devolver solo la pestaña propia haría
// desaparecer las de WooCommerce y las de los demás.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Product_Fields::add_tab' );

$facturamx_tabs = FacturaMX_Product_Fields::add_tab( array( 'general' => array( 'label' => 'General' ) ) );

facturamx_is( isset( $facturamx_tabs['facturamx'] ), true, 'la pestana se registra con clave propia' );
facturamx_is( isset( $facturamx_tabs['fmx'] ), false, 'no queda la clave corta que podia colisionar' );
facturamx_is( isset( $facturamx_tabs['general'] ), true, 'las pestanas ajenas siguen ahi' );
facturamx_is( $facturamx_tabs['facturamx']['target'], 'facturamx_product_data', 'apunta al panel que pinta render_panel' );

// ---------------------------------------------------------------------------
// FacturaMX_Client::classify — la tabla de decisión, sin red
//
// Una fila por código de la tabla leída en src/app/api/public/invoice/route.ts
// del repo de FacturaMX. Se decide por CÓDIGO HTTP, nunca por el texto del
// mensaje (decisión D2): el texto se propaga al usuario, no se inspecciona.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Client::classify' );

// 200 · timbrado correcto.
$facturamx_ok = FacturaMX_Client::classify( 200, array( 'uuid' => 'X' ), null );
facturamx_is( $facturamx_ok['action'], 'return', '200 devuelve' );
facturamx_is( $facturamx_ok['data']['uuid'], 'X', '200 propaga el cuerpo' );

// 200 + deduplicated · éxito, con el flag intacto (D5).
$facturamx_dedup = FacturaMX_Client::classify( 200, array( 'uuid' => 'X', 'deduplicated' => true ), null );
facturamx_is( $facturamx_dedup['action'], 'return', 'deduplicated es exito, no error' );
facturamx_is( $facturamx_dedup['data']['deduplicated'], true, 'el flag deduplicated se propaga intacto' );

// 401 · token rechazado. No se reintenta: reintentar solo gasta tiempo.
$facturamx_401 = FacturaMX_Client::classify( 401, array( 'error' => 'Token ausente o inválido' ), null );
facturamx_is( $facturamx_401['action'], 'fail', '401 falla' );
facturamx_is( $facturamx_401['code'], 'facturamx_unauthorized', '401 codigo propio' );

// 409 · la organización no puede facturar. El mensaje de la API se propaga.
$facturamx_409 = FacturaMX_Client::classify( 409, array( 'error' => 'No hay timbres disponibles' ), null );
facturamx_is( $facturamx_409['action'], 'fail', '409 falla' );
facturamx_is( $facturamx_409['code'], 'facturamx_org_not_ready', '409 codigo propio' );
facturamx_is(
	false !== strpos( $facturamx_409['message'], 'No hay timbres disponibles' ),
	true,
	'409 propaga el texto de la API, no lo sustituye'
);

// 422 · payload inválido. El payload no va a mejorar solo.
$facturamx_422 = FacturaMX_Client::classify( 422, array( 'error' => 'RFC inválido' ), null );
facturamx_is( $facturamx_422['action'], 'fail', '422 falla' );
facturamx_is( $facturamx_422['code'], 'facturamx_invalid_payload', '422 codigo propio' );

// 429 · rate limit. Respeta Retry-After acotado a MAX_RETRY_WAIT (D4).
$facturamx_429 = FacturaMX_Client::classify( 429, array( 'error' => 'Límite excedido' ), 12 );
facturamx_is( $facturamx_429['action'], 'retry', '429 con espera corta reintenta' );
facturamx_is( $facturamx_429['wait'], 12, '429 respeta Retry-After' );

$facturamx_429_largo = FacturaMX_Client::classify( 429, array( 'error' => 'Límite excedido' ), 55 );
facturamx_is( $facturamx_429_largo['action'], 'fail', '429 con Retry-After por encima del tope no espera' );
facturamx_is( $facturamx_429_largo['code'], 'facturamx_rate_limited', '429 largo codigo propio' );

$facturamx_429_sin = FacturaMX_Client::classify( 429, array( 'error' => 'Límite excedido' ), null );
facturamx_is( $facturamx_429_sin['action'], 'retry', '429 sin cabecera reintenta igual' );
facturamx_is( $facturamx_429_sin['wait'] > 0, true, '429 sin cabecera espera algo, no cero' );

// 502 · fallo aguas arriba. Reintento con espera breve fija.
$facturamx_502 = FacturaMX_Client::classify( 502, array( 'error' => 'Facturapi no responde' ), null );
facturamx_is( $facturamx_502['action'], 'retry', '502 reintenta' );
facturamx_is( $facturamx_502['wait'], 2, '502 espera fija breve' );
facturamx_is( $facturamx_502['code'], 'facturamx_upstream_error', '502 codigo propio' );

// Código no contemplado: lo desconocido NO se reintenta.
$facturamx_418 = FacturaMX_Client::classify( 418, array( 'error' => 'Soy una tetera' ), null );
facturamx_is( $facturamx_418['action'], 'fail', 'codigo desconocido falla, no reintenta' );
facturamx_is( $facturamx_418['code'], 'facturamx_unexpected_response', 'codigo desconocido tiene su propio codigo' );

// 200 sin cuerpo decodificable: no es un timbrado.
$facturamx_200_vacio = FacturaMX_Client::classify( 200, null, null );
facturamx_is( $facturamx_200_vacio['action'], 'fail', '200 sin cuerpo no es un timbrado' );
facturamx_is( $facturamx_200_vacio['code'], 'facturamx_unexpected_response', '200 sin cuerpo: respuesta inesperada' );

// 200 con cuerpo sin uuid: tampoco.
$facturamx_200_sin_uuid = FacturaMX_Client::classify( 200, array( 'ok' => true ), null );
facturamx_is( $facturamx_200_sin_uuid['action'], 'fail', '200 sin uuid no es un timbrado' );

// El error sin texto no deja al usuario sin mensaje.
$facturamx_sin_texto = FacturaMX_Client::classify( 409, array(), null );
facturamx_is( '' !== $facturamx_sin_texto['message'], true, 'siempre hay mensaje aunque la API no mande texto' );

// ---------------------------------------------------------------------------
// FacturaMX_Client::endpoint — construcción de la URL
//
// La base viene de un campo de texto que rellena una persona. Lo que se prueba
// aquí es que ninguna variante razonable de ese texto produzca una URL con la
// barra duplicada o ausente.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Client::endpoint' );

facturamx_is( FacturaMX_Client::endpoint( 'https://facturamx.top', '/api/public/invoice' ), 'https://facturamx.top/api/public/invoice', 'base sin barra' );
facturamx_is( FacturaMX_Client::endpoint( 'https://facturamx.top/', '/api/public/invoice' ), 'https://facturamx.top/api/public/invoice', 'base con barra final' );
facturamx_is( FacturaMX_Client::endpoint( '  https://facturamx.top//  ', '/api/public/invoice' ), 'https://facturamx.top/api/public/invoice', 'espacios y barras de mas' );
facturamx_is( FacturaMX_Client::endpoint( 'https://facturamx.top', 'api/public/catalogs' ), 'https://facturamx.top/api/public/catalogs', 'ruta sin barra inicial' );
facturamx_is( FacturaMX_Client::endpoint( '', '/api/public/invoice' ), '', 'sin base no hay URL que construir' );

// ---------------------------------------------------------------------------
// FacturaMX_Client::retry_after_from — lectura de la cabecera
//
// wp_remote_retrieve_headers devuelve un objeto que se comporta como array y
// cuyas claves llegan en minúsculas, pero no hay garantía de mayúsculas ni de
// que el valor sea numérico. Se lee defensivamente y se devuelve null si no
// hay un número de segundos utilizable.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Client::retry_after_from' );

facturamx_is( FacturaMX_Client::retry_after_from( array( 'retry-after' => '12' ) ), 12, 'cabecera en minusculas' );
facturamx_is( FacturaMX_Client::retry_after_from( array( 'Retry-After' => '12' ) ), 12, 'cabecera capitalizada' );
facturamx_is( FacturaMX_Client::retry_after_from( array( 'retry-after' => array( '7', '9' ) ) ), 7, 'cabecera repetida: la primera' );
facturamx_is( FacturaMX_Client::retry_after_from( array() ), null, 'sin cabecera' );
facturamx_is( FacturaMX_Client::retry_after_from( array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ) ), null, 'formato fecha: no se interpreta' );
facturamx_is( FacturaMX_Client::retry_after_from( array( 'retry-after' => '0' ) ), null, 'cero no es una espera' );

// El caso real: wp_remote_retrieve_headers devuelve el diccionario de Requests,
// no un array. Un cast a array sobre él daría las propiedades protegidas.
class FacturaMX_Fake_Headers {
	private $data;
	public function __construct( $data ) {
		$this->data = $data;
	}
	public function getAll() {
		return $this->data;
	}
}

facturamx_is( FacturaMX_Client::retry_after_from( new FacturaMX_Fake_Headers( array( 'retry-after' => '18' ) ) ), 18, 'diccionario de Requests, no array' );
facturamx_is( FacturaMX_Client::retry_after_from( 'no soy cabeceras' ), null, 'entrada que no son cabeceras' );

// ---------------------------------------------------------------------------
// FacturaMX_Receptor — saneado y validación fiscal del receptor (S1.4 T1)
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Receptor::sanitize' );

$clean = FacturaMX_Receptor::sanitize(
	array(
		'legal_name' => '  Gamey González Vera  ',
		'tax_id'     => ' gogv 850101ab1 ',
		'tax_system' => ' 612 ',
		'zip'        => ' 77710 ',
		'email'      => '  GAMEY@Example.COM ',
	)
);

// El nombre es un dato fiscal literal: debe coincidir carácter a carácter con
// la Constancia de Situación Fiscal. Se recorta, no se "mejora".
facturamx_is( $clean['legal_name'], 'Gamey González Vera', 'legal_name solo se recorta' );

// El servidor valida el RFC en mayúsculas pero reenvía el original a Facturapi
// (stamp.ts:129). Si el plugin no normaliza, nadie lo hace. Decisión D3.
facturamx_is( $clean['tax_id'], 'GOGV850101AB1', 'tax_id en mayúsculas y sin espacios interiores' );
facturamx_is( $clean['tax_system'], '612', 'tax_system recortado' );
facturamx_is( $clean['zip'], '77710', 'zip recortado' );
facturamx_is( $clean['email'], 'gamey@example.com', 'email en minúsculas' );

// Ausente no es lo mismo que vacío: el servidor trata email como opcional y una
// cadena vacía llegaría a Facturapi como dirección de correo.
$sin_email = FacturaMX_Receptor::sanitize(
	array(
		'legal_name' => 'X',
		'email'      => '   ',
	)
);
facturamx_is( isset( $sin_email['email'] ), false, 'email vacío no aparece en el array' );

// Un CP de tres dígitos NO se rellena con ceros. Corregir en silencio un dato
// fiscal es peor que rechazarlo: 00680 y 77710 son municipios distintos.
$corto = FacturaMX_Receptor::sanitize( array( 'zip' => ' 680 ' ) );
facturamx_is( $corto['zip'], '680', 'zip corto no se rellena con ceros' );

// Claves ausentes en la entrada salen como cadena vacía, no como notice de PHP.
$vacio = FacturaMX_Receptor::sanitize( array() );
facturamx_is( $vacio['legal_name'], '', 'entrada vacía no genera notice' );
facturamx_is( $vacio['tax_id'], '', 'tax_id ausente sale vacío' );

facturamx_group( 'FacturaMX_Receptor::validate' );

// Catálogos con la forma real de /api/public/catalogs: { code, name }.
$catalogos = array(
	'tax_regimes'   => array(
		array(
			'code' => '601',
			'name' => 'General de Ley Personas Morales',
		),
		array(
			'code' => '612',
			'name' => 'Personas Físicas con Actividades Empresariales',
		),
		array(
			'code' => '616',
			'name' => 'Sin obligaciones fiscales',
		),
	),
	'cfdi_uses'     => array(
		array(
			'code' => 'G03',
			'name' => 'Gastos en general',
		),
		array(
			'code' => 'S01',
			'name' => 'Sin efectos fiscales',
		),
	),
	'payment_forms' => array(
		array(
			'code' => '03',
			'name' => 'Transferencia electrónica de fondos',
		),
		array(
			'code' => '04',
			'name' => 'Tarjeta de crédito',
		),
	),
);

$bueno = FacturaMX_Receptor::sanitize(
	array(
		'legal_name' => 'Gamey González Vera',
		'tax_id'     => 'GOGV850101AB1',
		'tax_system' => '612',
		'zip'        => '77710',
	)
);

facturamx_is( FacturaMX_Receptor::validate( $bueno, $catalogos, 'G03', '03' ), true, 'receptor completo y válido' );

/** Devuelve el receptor bueno con un campo cambiado. */
$con = function ( $campo, $valor ) use ( $bueno ) {
	$c           = $bueno;
	$c[ $campo ] = $valor;
	return $c;
};

// Los seis mensajes se comparan carácter a carácter con los del servidor
// (fiscal-validation.ts y public-auth.ts). Si divergen, el operador leería un
// texto en la previsualización y otro distinto en el 422.
$e = FacturaMX_Receptor::validate( $con( 'tax_id', 'XAXX010101' ), $catalogos, 'G03', '03' );
facturamx_err( $e, 'facturamx_invalid_customer', 'RFC de 10 caracteres se rechaza' );
facturamx_is( $e->get_error_message(), 'RFC inválido', 'mensaje literal del servidor: RFC' );

$e = FacturaMX_Receptor::validate( $con( 'zip', '0680' ), $catalogos, 'G03', '03' );
facturamx_is( $e->get_error_message(), 'Código postal inválido (deben ser 5 dígitos)', 'mensaje literal: CP' );

$e = FacturaMX_Receptor::validate( $con( 'tax_system', '999' ), $catalogos, 'G03', '03' );
facturamx_is( $e->get_error_message(), 'Régimen fiscal fuera del catálogo SAT (c_RegimenFiscal)', 'mensaje literal: régimen' );

$e = FacturaMX_Receptor::validate( $bueno, $catalogos, 'X99', '03' );
facturamx_is( $e->get_error_message(), 'Uso de CFDI fuera del catálogo SAT (c_UsoCFDI)', 'mensaje literal: uso' );

$e = FacturaMX_Receptor::validate( $bueno, $catalogos, 'G03', '99' );
facturamx_is( $e->get_error_message(), 'Forma de pago fuera del catálogo SAT (c_FormaPago)', 'mensaje literal: forma de pago' );

$e = FacturaMX_Receptor::validate( $con( 'legal_name', '' ), $catalogos, 'G03', '03' );
facturamx_is( $e->get_error_message(), 'customer.legal_name es obligatorio', 'mensaje literal: razón social' );

// El payload admite `use` vacío, pero entonces el servidor pone S01 —"sin
// efectos fiscales"—, justo lo contrario de lo que quiere quien pide factura.
// Decisión D4: aquí es obligatorio.
facturamx_err( FacturaMX_Receptor::validate( $bueno, $catalogos, '', '03' ), 'facturamx_invalid_customer', 'uso vacío se rechaza aunque el payload lo admita' );
facturamx_err( FacturaMX_Receptor::validate( $bueno, $catalogos, 'G03', '' ), 'facturamx_invalid_customer', 'forma de pago vacía se rechaza' );

// Persona moral (12) y persona física (13): los dos formatos son válidos.
facturamx_is( FacturaMX_Receptor::validate( $con( 'tax_id', 'EKU9003173C9' ), $catalogos, 'G03', '03' ), true, 'RFC de persona moral (12)' );
facturamx_is( FacturaMX_Receptor::validate( $con( 'tax_id', 'GOGV850101AB1' ), $catalogos, 'G03', '03' ), true, 'RFC de persona física (13)' );

// El RFC genérico de público en general. Un regex mal escrito lo rechazaría, y
// es exactamente el que más se usa.
facturamx_is( FacturaMX_Receptor::validate( $con( 'tax_id', 'XAXX010101000' ), $catalogos, 'G03', '03' ), true, 'RFC genérico XAXX010101000' );

// El SAT admite Ñ y & en las siglas de la razón social.
facturamx_is( FacturaMX_Receptor::validate( $con( 'tax_id', 'ÑA&850101AB1' ), $catalogos, 'G03', '03' ), true, 'RFC con Ñ y &' );

// Sin catálogos no se valida a la ligera: se para. Jidoka. Si catalogs() falló,
// dar por bueno un régimen sería adivinar.
facturamx_err( FacturaMX_Receptor::validate( $bueno, array(), 'G03', '03' ), 'facturamx_no_catalogs', 'sin catálogos se para' );
facturamx_err( FacturaMX_Receptor::validate( $bueno, array( 'tax_regimes' => array() ), 'G03', '03' ), 'facturamx_no_catalogs', 'catálogo vacío se para' );

// El orden importa: sanear antes de validar. Sin sanear, un RFC en minúsculas
// se rechaza aquí — que es mejor que aceptarlo y que lo rechace Facturapi.
facturamx_err( FacturaMX_Receptor::validate( $con( 'tax_id', 'gogv850101ab1' ), $catalogos, 'G03', '03' ), 'facturamx_invalid_customer', 'RFC sin sanear se rechaza' );
facturamx_is( FacturaMX_Receptor::validate( FacturaMX_Receptor::sanitize( $con( 'tax_id', 'gogv850101ab1' ) ), $catalogos, 'G03', '03' ), true, 'el mismo RFC, saneado, pasa' );

// ---------------------------------------------------------------------------
// FacturaMX_Invoice — qué pasa después de un timbrado correcto (S1.4 T2)
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Invoice::meta_from_response' );

$respuesta = array(
	'uuid'                 => 'A1B2C3D4-0000-1111-2222-333344445555',
	'invoice_id'           => 'inv_9f2c',
	'facturapi_invoice_id' => '68f0aa11bb22cc33dd44ee55',
	'folio'                => 1,
	'series'               => 'WEB',
	'pdf_url'              => 'https://facturamx.top/api/public/invoice/inv_9f2c/pdf',
	'xml_url'              => 'https://facturamx.top/api/public/invoice/inv_9f2c/xml',
);

$meta = FacturaMX_Invoice::meta_from_response( $respuesta );

facturamx_is( count( $meta ), 7, 'siete claves de meta' );
facturamx_is( $meta['_facturamx_uuid'], 'A1B2C3D4-0000-1111-2222-333344445555', 'uuid' );
facturamx_is( $meta['_facturamx_invoice_id'], 'inv_9f2c', 'invoice_id' );
facturamx_is( $meta['_facturamx_series'], 'WEB', 'serie' );
facturamx_is( $meta['_facturamx_pdf_url'], 'https://facturamx.top/api/public/invoice/inv_9f2c/pdf', 'pdf_url' );
facturamx_is( $meta['_facturamx_xml_url'], 'https://facturamx.top/api/public/invoice/inv_9f2c/xml', 'xml_url' );

// El folio se guarda como cadena. La meta de WordPress no conserva el tipo:
// leerla devuelve '1', y compararla con 1 usando === daría un falso negativo
// más adelante. Se guarda ya como lo que se va a leer.
facturamx_is( $meta['_facturamx_folio'], '1', 'folio como cadena, no como int' );

// La API no manda fecha de timbrado: la pone el plugin.
facturamx_is( 19, strlen( $meta['_facturamx_stamped_at'] ), 'stamped_at con formato Y-m-d H:i:s' );

// Un 200 sin UUID no es una factura. Misma regla que FacturaMX_Client::classify(),
// y por la misma razón: guardar meta de una factura que no existe dejaría el
// pedido marcado como facturado para siempre.
facturamx_err( FacturaMX_Invoice::meta_from_response( array( 'invoice_id' => 'inv_9f2c' ) ), 'facturamx_no_uuid', 'respuesta sin uuid' );
facturamx_err( FacturaMX_Invoice::meta_from_response( array( 'uuid' => '' ) ), 'facturamx_no_uuid', 'uuid vacío' );
facturamx_err( FacturaMX_Invoice::meta_from_response( array( 'uuid' => '   ' ) ), 'facturamx_no_uuid', 'uuid con solo espacios' );
facturamx_err( FacturaMX_Invoice::meta_from_response( 'no soy una respuesta' ), 'facturamx_no_uuid', 'respuesta que no es array' );

// La serie la pone la organización y puede no volver en la respuesta. Que
// falte no es un error: es una factura sin serie.
$sin_serie = FacturaMX_Invoice::meta_from_response(
	array(
		'uuid'       => 'A1B2C3D4-0000-1111-2222-333344445555',
		'invoice_id' => 'inv_9f2c',
	)
);
facturamx_is( $sin_serie['_facturamx_series'], '', 'serie ausente queda vacía, no rompe' );
facturamx_is( $sin_serie['_facturamx_folio'], '', 'folio ausente queda vacío' );

// `deduplicated` NO se guarda en la meta: es información sobre ESTA petición,
// no sobre la factura. La misma factura recuperada mañana no estaría duplicada.
facturamx_is( isset( $meta['_facturamx_deduplicated'] ), false, 'deduplicated no se persiste' );

facturamx_group( 'FacturaMX_Invoice::note_text' );

$nota = FacturaMX_Invoice::note_text( $respuesta );
facturamx_is( false !== strpos( $nota, 'A1B2C3D4-0000-1111-2222-333344445555' ), true, 'la nota lleva el UUID' );
facturamx_is( false !== strpos( $nota, 'WEB-1' ), true, 'la nota lleva serie y folio' );
facturamx_is( false !== strpos( $nota, 'emitida' ), true, 'emisión nueva: "emitida"' );
facturamx_is( false !== strpos( $nota, 'recuper' ), false, 'emisión nueva: no dice "recuperada"' );

$dedup                  = $respuesta;
$dedup['deduplicated']  = true;
$nota_dedup             = FacturaMX_Invoice::note_text( $dedup );

// La distinción importa: si alguien lee "emitida" dos veces en el historial de
// un pedido, va a pensar que se timbró dos veces y va a ir a cancelar una.
facturamx_is( false !== strpos( $nota_dedup, 'recuper' ), true, 'deduplicada: dice "recuperada"' );
facturamx_is( false !== strpos( $nota_dedup, 'A1B2C3D4-0000-1111-2222-333344445555' ), true, 'la nota deduplicada también lleva el UUID' );

facturamx_group( 'FacturaMX_Invoice::meta_keys' );

// Las siete claves se definen una sola vez y las consumen el extractor y el
// lector. Si divergieran, persist() escribiría una y stamped_data() leería otra.
facturamx_is( count( FacturaMX_Invoice::meta_keys() ), 7, 'siete claves declaradas' );
facturamx_is( array_keys( $meta ), FacturaMX_Invoice::meta_keys(), 'el extractor produce exactamente las claves declaradas' );

// ---------------------------------------------------------------------------
// FacturaMX_Order_Metabox — lo que se puede extraer puro del metabox (S1.4 T3)
//
// Los handlers AJAX NO se testean aquí: hacerlo con stubs de wp_send_json_*
// sería comprobar que mi stub coincide con mi código. Su verificación es la
// sonda de T4, igual que en S1.2 D1 y S1.3 T2.
// ---------------------------------------------------------------------------
facturamx_group( 'FacturaMX_Order_Metabox::screens' );

// D10: las dos pantallas. Una tienda puede tener HPOS y otra no, y una pantalla
// sin metabox no da error: simplemente no está.
facturamx_is(
	FacturaMX_Order_Metabox::screens( 'woocommerce_page_wc-orders' ),
	array( 'shop_order', 'woocommerce_page_wc-orders' ),
	'con HPOS se registran las dos'
);
facturamx_is( FacturaMX_Order_Metabox::screens( '' ), array( 'shop_order' ), 'sin HPOS, solo la clásica' );
facturamx_is( FacturaMX_Order_Metabox::screens( 'shop_order' ), array( 'shop_order' ), 'no se registra dos veces la misma pantalla' );

facturamx_group( 'FacturaMX_Order_Metabox::preview_result' );

// Un pedido de 1000 + 16% de IVA. El payload ya viene cuadrado de build().
$payload_previo = array(
	'payment_method' => 'PUE',
	'payment_form'   => '03',
	'customer'       => $bueno,
	'items'          => array(
		array(
			'description' => 'Hospedaje anual',
			'quantity'    => 1.0,
			'price'       => 1000.0,
			'iva_rate'    => 0.16,
		),
	),
);

$previo = FacturaMX_Order_Metabox::preview_result( $payload_previo, 1160.00 );

facturamx_is( $previo['ok'], true, 'payload válido: ok' );
facturamx_is( $previo['code'], '', 'sin código de error' );
facturamx_is( $previo['payload'], $payload_previo, 'devuelve el payload íntegro para enseñarlo' );
facturamx_is( $previo['reconciliation']['items'], 1, 'un concepto' );
facturamx_is( $previo['reconciliation']['cfdi_total'], '1160.00', 'total del CFDI' );
facturamx_is( $previo['reconciliation']['order_total'], '1160.00', 'total del pedido' );
facturamx_is( $previo['reconciliation']['difference'], '0.00', 'diferencia cero' );

// El veredicto lleva la cifra, no solo la palabra «cuadra». Un «cuadra» sin
// número no se puede contrastar con nada: el operador no puede verificarlo.
facturamx_is( false !== strpos( $previo['verdict'], '1160.00' ), true, 'el veredicto enseña el total' );

// Un WP_Error del mapeador se transmite tal cual: el metabox no reescribe los
// mensajes del mapeador ni los clasifica (misma regla que D2 de S1.3).
$descuadre = FacturaMX_Order_Metabox::preview_result(
	new WP_Error( 'facturamx_total_mismatch', 'El total calculado (1160.00) no coincide con el del pedido (1000.00). Diferencia: 160.00 MXN.' ),
	1000.00
);

facturamx_is( $descuadre['ok'], false, 'descuadre: no ok' );
facturamx_is( $descuadre['code'], 'facturamx_total_mismatch', 'conserva el código del mapeador' );
facturamx_is( false !== strpos( $descuadre['verdict'], '160.00' ), true, 'conserva la diferencia en pesos' );
facturamx_is( $descuadre['payload'], array(), 'sin payload que enseñar' );
facturamx_is( $descuadre['reconciliation'], array(), 'sin cuadre que enseñar' );

// Un payload sin items no revienta: cuadra a cero contra un pedido a cero.
$vacio = FacturaMX_Order_Metabox::preview_result( array( 'customer' => $bueno ), 0.0 );
facturamx_is( $vacio['reconciliation']['items'], 0, 'payload sin items: cero conceptos' );
facturamx_is( $vacio['reconciliation']['cfdi_total'], '0.00', 'payload sin items: total cero' );

// ---------------------------------------------------------------------------
// FacturaMX_Eligibility — quién puede facturar desde el portal público
// ---------------------------------------------------------------------------
facturamx_group( 'eligibility' );

$dia = 86400;
$hoy = 1753747200; // 2026-07-29 00:00:00 UTC

/** Pedido facturable: pagado, sin reembolso, de ayer, sin factura. */
$pedido_ok = array(
	'exists'     => true,
	'total'      => 1160.00,
	'is_paid'    => true,
	'refunded'   => 0.0,
	'created_ts' => $hoy - $dia,
	'is_stamped' => false,
);

$opts_ok = array(
	'amount'      => 1160.00,
	'window_days' => 30,
	'now'         => $hoy,
);

facturamx_is( FacturaMX_Eligibility::check( $pedido_ok, $opts_ok ), true, 'pedido correcto: facturable' );

// --- El corazón de la story: los dos rechazos indistinguibles --------------
$no_existe   = FacturaMX_Eligibility::check( array( 'exists' => false ), $opts_ok );
$mal_importe = FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 1159.00 ) + $opts_ok );

facturamx_err( $no_existe, 'facturamx_not_found', 'pedido inexistente' );
facturamx_err( $mal_importe, 'facturamx_not_found', 'importe equivocado' );

// No basta con que coincidan los códigos: el TEXTO tiene que ser el mismo
// carácter a carácter. Es lo único que le impide al visitante saber si el
// pedido existe.
facturamx_is(
	$no_existe->get_error_message() === $mal_importe->get_error_message(),
	true,
	'inexistente y mal importe: MISMO texto'
);

// --- Tolerancia de ±0.01 ---------------------------------------------------
// La diferencia se redondea ANTES de compararse (PAT-D-007): 1160.01 - 1160.00
// da 0.010000000000218 en coma flotante, y un `> 0.01` lo rechazaría.
facturamx_is( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 1160.01 ) + $opts_ok ), true, 'tolerancia: +0.01 entra' );
facturamx_is( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 1159.99 ) + $opts_ok ), true, 'tolerancia: -0.01 entra' );
facturamx_err( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 1160.02 ) + $opts_ok ), 'facturamx_not_found', 'tolerancia: +0.02 fuera' );
facturamx_err( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 1159.98 ) + $opts_ok ), 'facturamx_not_found', 'tolerancia: -0.02 fuera' );

// Un importe vacío no es «cero»: es un formulario a medias. Tratarlo como 0.00
// dejaría pasar un pedido de importe cero sin que nadie teclee nada.
facturamx_err( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => '' ) + $opts_ok ), 'facturamx_not_found', 'importe vacío' );
facturamx_err( FacturaMX_Eligibility::check( $pedido_ok, array( 'amount' => 'hola' ) + $opts_ok ), 'facturamx_not_found', 'importe no numérico' );

// --- Los tres rechazos con mensaje propio ----------------------------------
facturamx_err( FacturaMX_Eligibility::check( array( 'is_paid' => false ) + $pedido_ok, $opts_ok ), 'facturamx_not_paid', 'pedido no pagado' );
facturamx_err( FacturaMX_Eligibility::check( array( 'refunded' => 116.00 ) + $pedido_ok, $opts_ok ), 'facturamx_refunded', 'pedido con reembolso' );
facturamx_err( FacturaMX_Eligibility::check( array( 'refunded' => 0.01 ) + $pedido_ok, $opts_ok ), 'facturamx_refunded', 'reembolso de un céntimo' );
facturamx_err(
	FacturaMX_Eligibility::check( array( 'created_ts' => $hoy - ( 31 * $dia ) ) + $pedido_ok, $opts_ok ),
	'facturamx_window_expired',
	'pedido fuera de ventana'
);

// La frontera se fija aquí, no se deja al azar: 30 días exactos ENTRA.
facturamx_is(
	FacturaMX_Eligibility::check( array( 'created_ts' => $hoy - ( 30 * $dia ) ) + $pedido_ok, $opts_ok ),
	true,
	'ventana: 30 días exactos entra'
);
facturamx_err(
	FacturaMX_Eligibility::check( array( 'created_ts' => $hoy - ( 30 * $dia ) - 1 ) + $pedido_ok, $opts_ok ),
	'facturamx_window_expired',
	'ventana: 30 días y un segundo, fuera'
);

// --- El assert que impide el oráculo ---------------------------------------
// Un pedido que falla TODAS las reglas a la vez devuelve facturamx_not_found, no
// facturamx_not_paid. Si alguien reordena las comprobaciones para «dar un mensaje más
// útil», este es el assert que se rompe.
$todo_mal = array(
	'exists'     => true,
	'total'      => 1160.00,
	'is_paid'    => false,
	'refunded'   => 500.00,
	'created_ts' => $hoy - ( 90 * $dia ),
	'is_stamped' => false,
);

facturamx_err(
	FacturaMX_Eligibility::check( $todo_mal, array( 'amount' => 1.00 ) + $opts_ok ),
	'facturamx_not_found',
	'ORDEN: el importe equivocado gana a todos los demás rechazos'
);

// Con el importe correcto, el mismo pedido sí explica por qué no se puede
// facturar: el visitante ya ha demostrado que el pedido es suyo.
facturamx_err( FacturaMX_Eligibility::check( $todo_mal, $opts_ok ), 'facturamx_not_paid', 'ORDEN: con el importe correcto sí se explica el motivo' );

// --- Ya facturado: es una rama, no un rechazo ------------------------------
// Un pedido ya facturado es elegible aunque esté fuera de ventana o tenga un
// reembolso posterior: su dueño solo quiere descargar lo que ya se emitió.
$facturado_viejo = array(
	'exists'     => true,
	'total'      => 1160.00,
	'is_paid'    => true,
	'refunded'   => 116.00,
	'created_ts' => $hoy - ( 200 * $dia ),
	'is_stamped' => true,
);

facturamx_is( FacturaMX_Eligibility::check( $facturado_viejo, $opts_ok ), true, 'ya facturado: elegible aunque viejo y reembolsado' );
facturamx_err(
	FacturaMX_Eligibility::check( $facturado_viejo, array( 'amount' => 1.00 ) + $opts_ok ),
	'facturamx_not_found',
	'ya facturado: el importe sigue siendo la contraseña'
);

// --- Moneda: el mismo hecho que el gate del mapeador, dicho antes ----------
// El del mapeador es el que no se puede saltar. Este solo evita que el
// visitante rellene un formulario entero para que falle al final.
$pedido_usd = array( 'currency' => 'USD' ) + $pedido_ok;

facturamx_err( FacturaMX_Eligibility::check( $pedido_usd, $opts_ok ), 'facturamx_currency_unsupported', 'pedido en dólares: se explica' );
facturamx_is( FacturaMX_Eligibility::check( array( 'currency' => 'MXN' ) + $pedido_ok, $opts_ok ), true, 'pedido en pesos: facturable' );
facturamx_is( FacturaMX_Eligibility::check( array( 'currency' => 'mxn' ) + $pedido_ok, $opts_ok ), true, 'pesos en minúsculas: facturable' );

// Éste es el assert que importa. Si la moneda se comprobara ANTES del importe,
// el mensaje distinto convertiría el portal en un enumerador de pedidos:
// bastaría probar números hasta que el error cambiara de texto.
facturamx_err(
	FacturaMX_Eligibility::check( $pedido_usd, array( 'amount' => 1.00 ) + $opts_ok ),
	'facturamx_not_found',
	'ORDEN: con el importe equivocado, la moneda no se delata'
);

// Y éste impide que el gate le esconda a nadie su propia factura: ya está
// emitida, rechazarla ahora no deshace nada.
facturamx_is(
	FacturaMX_Eligibility::check( array( 'currency' => 'USD', 'is_stamped' => true ) + $pedido_ok, $opts_ok ),
	true,
	'ORDEN: un pedido en dólares ya facturado sigue siendo descargable'
);

// Vacío no es «pesos». Suponerlo es como empezó este defecto (Jidoka).
facturamx_err( FacturaMX_Eligibility::check( array( 'currency' => '' ) + $pedido_ok, $opts_ok ), 'facturamx_currency_unsupported', 'moneda vacía: se rechaza' );

// La moneda del portal y la del mapeador son LA MISMA decisión, en un solo
// sitio. Dos comprobaciones separadas divergirían en cuanto una cambiara.
facturamx_is(
	FacturaMX_Eligibility::check( $pedido_usd, $opts_ok )->get_error_message()
		=== FacturaMX_Order_Mapper::currency_error( 'USD' )->get_error_message(),
	true,
	'portal y mapeador dicen exactamente lo mismo'
);

// --- Los textos, que la sonda comparará con lo que devuelve el portal ------
$mensajes = FacturaMX_Eligibility::messages();

facturamx_is( count( $mensajes ), 4, 'cuatro mensajes fijos, uno por rechazo' );

// El de la moneda NO está en el catálogo, y es a propósito: su texto nombra la
// moneda del pedido, así que no es una constante. Sale del mapeador.
facturamx_is( array_key_exists( 'facturamx_currency_unsupported', $mensajes ), false, 'el texto de la moneda no es fijo: no está en el catálogo' );
facturamx_is( $mensajes['facturamx_not_found'], $no_existe->get_error_message(), 'el texto publicado es el que se devuelve' );

// Ningún mensaje puede nombrar el importe: hacerlo diría que el pedido existe.
foreach ( $mensajes as $codigo => $texto ) {
	$minus = strtolower( $texto );
	facturamx_is(
		false === strpos( $minus, 'importe' ) && false === strpos( $minus, 'total' ),
		true,
		"el mensaje de {$codigo} no nombra el importe"
	);
}

// ---------------------------------------------------------------------------
// FacturaMX_Download::parse_key() y la lista blanca de formatos
// ---------------------------------------------------------------------------
facturamx_group( 'download' );

$secreto = 'a3f1c9e4b70d2856f1ac94be3d0721ef'; // 32 hex.

facturamx_is(
	FacturaMX_Download::parse_key( '27.' . $secreto ),
	array(
		'order_id' => 27,
		'secret'   => $secreto,
	),
	'clave bien formada: id entero y secreto de 32 hex'
);

// Todo lo que no encaje EXACTAMENTE devuelve false. No hay grises: lo que no se
// puede parsear no llega a tocar la base de datos, y el proxy responde 404.
$claves_malas = array(
	'basura'                     => 'sin punto',
	''                           => 'cadena vacía',
	'27.'                        => 'sin secreto',
	'.' . $secreto               => 'sin id',
	'0.' . $secreto              => 'id cero',
	'-1.' . $secreto             => 'id negativo',
	'27.a3f1'                    => 'secreto corto',
	'27.' . $secreto . '0'       => 'secreto largo',
	'27.ZZZZc9e4b70d2856f1ac94be3d0721ef' => 'secreto no hexadecimal',
	'27.' . $secreto . '.' . $secreto     => 'dos puntos',
	'27a.' . $secreto            => 'id no numérico',
	' 27.' . $secreto            => 'espacio delante',
);

foreach ( $claves_malas as $clave => $motivo ) {
	facturamx_is( FacturaMX_Download::parse_key( $clave ), false, "clave rechazada ({$motivo})" );
}

// La lista blanca es blanca de verdad: se enumera lo permitido, no lo prohibido.
// Con lista negra, 'PDF' o 'pdf/../' pasarían y acabarían en una ruta remota.
facturamx_is( FacturaMX_Download::is_format( 'pdf' ), true, 'formato pdf permitido' );
facturamx_is( FacturaMX_Download::is_format( 'xml' ), true, 'formato xml permitido' );

foreach ( array( 'PDF', 'Xml', 'exe', '', 'pdf/../', 'pdf ', 'json' ) as $formato ) {
	facturamx_is( FacturaMX_Download::is_format( $formato ), false, "formato rechazado ('{$formato}')" );
}

// La clave generada tiene que encajar en su propio parseador. Si generar y
// parsear divergen, todos los enlaces nacen rotos y ningún test lo vería.
$generada = FacturaMX_Download::build_key( 27 );
$parseada = FacturaMX_Download::parse_key( $generada );
facturamx_is( is_array( $parseada ), true, 'la clave generada se parsea' );
facturamx_is( $parseada['order_id'], 27, 'la clave generada conserva el id' );
facturamx_is( strlen( $parseada['secret'] ), 32, 'el secreto generado mide 32 hex' );
facturamx_is(
	FacturaMX_Download::build_key( 27 ) !== FacturaMX_Download::build_key( 27 ),
	true,
	'dos claves del mismo pedido son distintas'
);

// ---------------------------------------------------------------------------
// FacturaMX_Portal: lo que teclea el visitante
// ---------------------------------------------------------------------------
facturamx_group( 'portal' );

// El importe es la contraseña, y el visitante lo copia de donde puede: del
// correo de WooCommerce, del banco, de la pantalla de gracias. Llega con signo
// de peso, con separador de miles, con coma decimal o con espacios. Rechazar
// '1,160.00' sería rechazar a un cliente que ha escrito su importe bien.
$importes = array(
	'1160'      => 1160.0,
	'1160.00'   => 1160.0,
	'1,160.00'  => 1160.0,
	'$1,160.00' => 1160.0,
	'$ 1160'    => 1160.0,
	'1160,00'   => 1160.0,
	'1.160,00'  => 1160.0,
	' 1160.00 ' => 1160.0,
	'1 160.00'  => 1160.0,
	'0.01'      => 0.01,
	'1,16'      => 1.16,
	'1,160'     => 1160.0,
);

foreach ( $importes as $tecleado => $esperado ) {
	facturamx_is( FacturaMX_Portal::normalize_amount( $tecleado ), $esperado, "importe '{$tecleado}'" );
}

// Lo que no es un número NO es cero. Devolver 0.0 abriría de par en par
// cualquier pedido de importe cero ante un formulario vacío.
foreach ( array( '', '   ', 'hola', '$', '-100', 'null', '1160.00.00' ) as $basura ) {
	facturamx_is( FacturaMX_Portal::normalize_amount( $basura ), null, "importe no numérico '{$basura}'" );
}

facturamx_is( FacturaMX_Portal::normalize_amount( null ), null, 'importe null' );
facturamx_is( FacturaMX_Portal::normalize_amount( array( 1 ) ), null, 'importe array' );

// El número de pedido se copia del correo, donde WooCommerce lo imprime con
// almohadilla. Exigir que la quiten sería cobrarle al cliente un error nuestro.
$numeros = array(
	'27'    => 27,
	'#27'   => 27,
	' 27 '  => 27,
	'#0027' => 27,
);

foreach ( $numeros as $tecleado => $esperado ) {
	facturamx_is( FacturaMX_Portal::normalize_order_number( $tecleado ), $esperado, "pedido '{$tecleado}'" );
}

foreach ( array( '', '0', '#', 'abc', '-3', '2.7', '27a' ) as $basura ) {
	facturamx_is( FacturaMX_Portal::normalize_order_number( $basura ), 0, "pedido inválido '{$basura}'" );
}

// La clave del código en los catálogos es 'code'. Escribir 'key' devolvería tres
// listas vacías SIN ningún error: el cliente vería tres selectores en blanco y
// nada en los registros. Es el fallo silencioso que este assert impide.
$catalogos_ejemplo = array(
	'tax_regimes'   => array(
		array(
			'code' => '601',
			'name' => 'General de Ley Personas Morales',
		),
		array( 'code' => '612' ),
	),
	'cfdi_uses'     => array(
		array(
			'code' => 'G03',
			'name' => 'Gastos en general',
		),
	),
	'payment_forms' => array(
		array(
			'code' => '03',
			'name' => 'Transferencia',
		),
	),
);

$opciones = FacturaMX_Portal::catalog_options( $catalogos_ejemplo );

facturamx_is( count( $opciones['tax_regimes'] ), 2, 'regímenes aplanados' );
facturamx_is( $opciones['tax_regimes'][0]['value'], '601', 'el valor es el código del SAT' );
facturamx_is( $opciones['tax_regimes'][0]['label'], '601 — General de Ley Personas Morales', 'la etiqueta lleva código y nombre' );
facturamx_is( $opciones['tax_regimes'][1]['label'], '612 — 612', 'sin nombre, la etiqueta repite el código' );
facturamx_is( $opciones['cfdi_uses'][0]['value'], 'G03', 'usos aplanados' );
facturamx_is( $opciones['payment_forms'][0]['value'], '03', 'formas de pago aplanadas' );

// Una entrada sin 'code' se descarta sin romper el resto de la lista.
$sucio = FacturaMX_Portal::catalog_options(
	array(
		'tax_regimes' => array(
			array( 'name' => 'sin código' ),
			array( 'code' => '601' ),
			'no soy un array',
		),
	)
);
facturamx_is( count( $sucio['tax_regimes'] ), 1, 'las entradas sin código se descartan' );
facturamx_is( count( $sucio['cfdi_uses'] ), 0, 'un catálogo ausente da una lista vacía, no un aviso' );
facturamx_is( count( FacturaMX_Portal::catalog_options( 'no soy un array' )['payment_forms'] ), 0, 'entrada no-array: tres listas vacías' );

// ---------------------------------------------------------------------------
// FacturaMX_Portal::plain_price — el importe del resumen llega como texto
// ---------------------------------------------------------------------------
facturamx_group( 'portal · plain_price' );

// Lo que devuelve wc_price() en una tienda en MXN.
$wc_html = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">&#36;</span>678.00</bdi></span>';
facturamx_is( FacturaMX_Portal::plain_price( $wc_html ), '$678.00', 'sin etiquetas y SIN entidades: «$», no «&#36;»' );
facturamx_is( FacturaMX_Portal::plain_price( '<bdi>1,234.50&nbsp;MXN</bdi>' ), "1,234.50\xC2\xA0MXN", 'el &nbsp; pasa a espacio duro' );
facturamx_is( FacturaMX_Portal::plain_price( ' <b>$5</b> ' ), '$5', 'recorta espacios' );

// ---------------------------------------------------------------------------
// FacturaMX_Readiness — S2.4: no se llega al primer timbre a medio configurar
// ---------------------------------------------------------------------------
facturamx_group( 'readiness' );

$fp = FacturaMX_Readiness::fingerprint( 'https://facturamx.top', 'fmx_live_' . str_repeat( 'a', 64 ) );
facturamx_is( $fp, FacturaMX_Readiness::fingerprint( 'https://facturamx.top', 'fmx_live_' . str_repeat( 'a', 64 ) ), 'la huella es determinista' );
facturamx_is( $fp === FacturaMX_Readiness::fingerprint( 'https://facturamx.top', 'fmx_live_' . str_repeat( 'b', 64 ) ), false, 'otro token → otra huella' );
facturamx_is( $fp === FacturaMX_Readiness::fingerprint( 'https://otra.api', 'fmx_live_' . str_repeat( 'a', 64 ) ), false, 'otra URL → otra huella' );
facturamx_is( false === strpos( $fp, str_repeat( 'a', 64 ) ), true, 'la huella no contiene el token' );

$ok_payload = array(
	'items' => array(
		array( 'description' => 'Aceite de coco', 'product_key' => '50171550' ),
		array( 'description' => 'Envío', 'product_key' => '78102200' ),
	),
);
facturamx_is( FacturaMX_Readiness::blocking_reasons( $ok_payload, true ), array(), 'todo en orden: nada bloquea' );

$sin_probar = FacturaMX_Readiness::blocking_reasons( $ok_payload, false );
facturamx_is( count( $sin_probar ), 1, 'conexión sin probar: un motivo' );
facturamx_is( false !== stripos( $sin_probar[0], 'Probar conexión' ), true, 'el motivo dice qué pulsar' );

$comodin = FacturaMX_Readiness::blocking_reasons(
	array(
		'items' => array(
			array( 'description' => 'Jabón artesanal', 'product_key' => '01010101' ),
			array( 'description' => 'Aceite de coco', 'product_key' => '50171550' ),
			array( 'description' => 'Vela', 'product_key' => '01010101' ),
		),
	),
	true
);
facturamx_is( count( $comodin ), 1, 'clave comodín: un motivo que agrupa los productos' );
facturamx_is( false !== strpos( $comodin[0], 'Jabón artesanal' ) && false !== strpos( $comodin[0], 'Vela' ), true, 'nombra cada producto sin clave' );
facturamx_is( false === strpos( $comodin[0], 'Aceite de coco' ), true, 'no nombra los que sí la tienen' );

facturamx_is( count( FacturaMX_Readiness::blocking_reasons( array( 'items' => array( array( 'description' => 'X', 'product_key' => '01010101' ) ) ), false ) ), 2, 'los dos motivos a la vez' );
facturamx_is( FacturaMX_Readiness::blocking_reasons( array(), true ), array(), 'payload sin items: nada que bloquear aquí (lo rechaza el mapper)' );

// ---------------------------------------------------------------------------
// Informe
// ---------------------------------------------------------------------------
$total_pass = 0;
$total_fail = 0;
$failures   = array();

foreach ( $GLOBALS['facturamx_tests'] as $name => $result ) {
	$count       = $result['pass'] + $result['fail'];
	$total_pass += $result['pass'];
	$total_fail += $result['fail'];
	$failures    = array_merge( $failures, $result['errors'] );

	printf(
		"%s %s %d/%d\n",
		$name,
		str_repeat( '.', max( 1, 38 - strlen( $name ) ) ),
		$result['pass'],
		$count
	);
}

echo "\n";

if ( $total_fail > 0 ) {
	foreach ( $failures as $failure ) {
		echo "  FALLO: {$failure}\n";
	}
	printf( "\nFALLOS — %d asserts, %d fallos\n", $total_pass + $total_fail, $total_fail );
	exit( 1 );
}

printf( "OK — %d asserts, 0 fallos\n", $total_pass );
exit( 0 );
