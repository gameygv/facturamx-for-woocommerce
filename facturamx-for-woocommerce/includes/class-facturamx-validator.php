<?php
/**
 * Validación y saneado de los ajustes del plugin.
 *
 * Todos los métodos son estáticos y puros: reciben un valor, devuelven el valor
 * normalizado o un WP_Error. No leen opciones, no tocan la red y no dependen de
 * WordPress más allá de WP_Error y `__()`. Esa pureza es deliberada — permite
 * ejecutar `php tests/run-tests.php` sin levantar WordPress (decisión D1 en
 * s1.1-design.md); `tests/stubs.php` define las dos.
 *
 * Los mensajes van envueltos en `__()` porque los escribe el plugin y los lee
 * el administrador de la tienda. Los de FacturaMX_Receptor no, y no es una
 * incoherencia: aquéllos son copia literal de la respuesta del servidor.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Validator {

	/**
	 * URL base de la API de FacturaMX.
	 *
	 * Normaliza quitando espacios y la barra final, para que el resto del código
	 * pueda concatenar rutas sin preocuparse de duplicar separadores.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function url( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return self::error(
				'facturamx_invalid_url',
				__( 'La URL de la API es obligatoria.', 'facturamx-for-woocommerce' )
			);
		}

		// wp_parse_url devuelve false ante una URL muy malformada; se trata igual
		// que "faltan el esquema y el host".
		$parts = wp_parse_url( $value );
		$parts = is_array( $parts ) ? $parts : array();

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::error(
				'facturamx_invalid_url',
				__( 'La URL de la API no tiene un formato válido.', 'facturamx-for-woocommerce' )
			);
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return self::error(
				'facturamx_invalid_url',
				__( 'La URL de la API debe empezar por http:// o https://.', 'facturamx-for-woocommerce' )
			);
		}

		return rtrim( $value, '/' );
	}

	/**
	 * ClaveProdServ del SAT: exactamente 8 dígitos.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function sat_product_key( $value ) {
		$value = trim( (string) $value );

		if ( 1 !== preg_match( '/^[0-9]{8}$/', $value ) ) {
			return self::error(
				'facturamx_invalid_product_key',
				__( 'La clave de producto del SAT debe tener exactamente 8 dígitos (ej. 01010101).', 'facturamx-for-woocommerce' )
			);
		}

		return $value;
	}

	/**
	 * ClaveUnidad del SAT: 1 a 3 caracteres alfanuméricos, en mayúsculas.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function sat_unit_key( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		if ( 1 !== preg_match( '/^[A-Z0-9]{1,3}$/', $value ) ) {
			return self::error(
				'facturamx_invalid_unit_key',
				__( 'La clave de unidad del SAT debe tener entre 1 y 3 caracteres alfanuméricos (ej. H87).', 'facturamx-for-woocommerce' )
			);
		}

		return $value;
	}

	/**
	 * Forma de pago del SAT (c_FormaPago): 2 dígitos.
	 *
	 * Acepta un solo dígito y lo rellena a la izquierda, porque escribir "3" en
	 * vez de "03" es un error de dedo trivial y no hay ambigüedad posible.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function payment_form( $value ) {
		$value = trim( (string) $value );

		if ( 1 !== preg_match( '/^[0-9]{1,2}$/', $value ) ) {
			return self::error(
				'facturamx_invalid_payment_form',
				__( 'La forma de pago debe ser un código de 2 dígitos del catálogo del SAT (ej. 03).', 'facturamx-for-woocommerce' )
			);
		}

		return str_pad( $value, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Token de la API pública de FacturaMX: `fmx_live_` + 64 hexadecimales. El
	 * prefijo lo decide FacturaMX; S2.2 lo renombró por error con el resto de prefijos.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function token( $value ) {
		$value = trim( (string) $value );

		if ( 1 !== preg_match( '/^fmx_live_[a-f0-9]{64}$/i', $value ) ) {
			return self::error(
				'facturamx_invalid_token',
				__( 'El token no tiene el formato esperado (fmx_live_ seguido de 64 caracteres).', 'facturamx-for-woocommerce' )
			);
		}

		return $value;
	}

	/**
	 * Serie de la factura. Opcional: si va vacía se usa la serie por defecto de
	 * la organización en FacturaMX.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error
	 */
	public static function series( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[A-Z0-9]{1,25}$/', $value ) ) {
			return self::error(
				'facturamx_invalid_series',
				__( 'La serie solo puede contener letras y números (ej. WEB).', 'facturamx-for-woocommerce' )
			);
		}

		return $value;
	}

	/**
	 * Tasa de IVA, en cualquiera de las formas en que un administrador la escribe.
	 *
	 * Acepta «16», «16%», «0.16» y «16,00» y devuelve siempre la misma cadena
	 * canónica («0.16»), porque el valor viaja a la meta del producto y de ahí al
	 * mapeo: si cada quien lo guarda en un formato, el mapeo tendría que adivinar.
	 *
	 * Se devuelve CADENA, no float, a propósito. Vacío significa «no declarado» y
	 * `0` significa «declarado como 0 %». En float los dos serían 0.0 y se
	 * perdería justo la distinción que hace falta para los productos exentos.
	 *
	 * @param string $value Valor introducido.
	 * @return string|WP_Error Cadena vacía si se deja en blanco.
	 */
	public static function iva_rate( $value ) {
		$value = trim( str_replace( array( '%', ' ' ), '', (string) $value ) );
		$value = str_replace( ',', '.', $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[0-9]+(\.[0-9]+)?$/', $value ) ) {
			return self::error(
				'facturamx_invalid_iva_rate',
				__( 'La tasa de IVA debe ser un número (ej. 16, 16% o 0.16).', 'facturamx-for-woocommerce' )
			);
		}

		$rate = (float) $value;

		// «16» es 16 %, no 1600 %. Nadie emite una tasa mayor que 1 en fracción.
		if ( $rate > 1 ) {
			$rate = $rate / 100;
		}

		foreach ( FacturaMX_Order_Mapper::TAX_RATES as $known ) {
			if ( abs( $rate - $known ) <= FacturaMX_Order_Mapper::RATE_TOLERANCE ) {
				return rtrim( rtrim( number_format( $known, 4, '.', '' ), '0' ), '.' );
			}
		}

		return self::error(
			'facturamx_invalid_iva_rate',
			__( 'Esa tasa no está en el catálogo del SAT. Las admitidas son 0 %, 8 % y 16 %.', 'facturamx-for-woocommerce' )
		);
	}

	/**
	 * Construye el WP_Error. Aislado para no repetir el `new` en cada método.
	 *
	 * @param string $code    Código de error.
	 * @param string $message Mensaje para el administrador.
	 * @return WP_Error
	 */
	private static function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
