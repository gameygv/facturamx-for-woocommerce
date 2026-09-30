<?php
/**
 * Receptor del CFDI: saneado y validación fiscal.
 *
 * Espeja `validatePublicInvoiceInput()` del servidor (decisión D2 de S1.4), con
 * una asimetría deliberada:
 *
 *   - Los dos regex (RFC y código postal) se duplican aquí. Son del SAT, no del
 *     producto: llevan años sin cambiar.
 *   - Los catálogos NO se copian. Se reciben como parámetro, y quien los pasa es
 *     FacturaMX_Client::catalogs(), que los descarga de /api/public/catalogs. Una copia
 *     pegada en el plugin se desincronizaría en cuanto el SAT publicara una
 *     entrada nueva, y entonces el plugin rechazaría algo que el servidor acepta
 *     — un fallo sin error que leer, solo un cliente que no puede facturar.
 *   - Los mensajes se copian LITERALES del servidor, para que el operador lea el
 *     mismo texto venga de la previsualización o venga de un 422.
 *
 * Esto no contradice la decisión D2 de S1.3 («no repliques la lógica del
 * servidor»): aquella prohibía CLASIFICAR errores por texto. Aquí no se
 * clasifica nada, se valida entrada contra una lista descargada del servidor.
 *
 * Puro: no toca la red, ni la base de datos, ni opciones. Se prueba entero con
 * `php tests/run-tests.php`.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Receptor {

	/**
	 * Patrón del RFC del SAT: 3 letras (persona moral) o 4 (persona física),
	 * 6 dígitos de fecha (AAMMDD) y 3 de homoclave.
	 *
	 * Copiado de `fiscal-validation.ts`. La `u` es obligatoria: sin ella la Ñ
	 * ocupa dos bytes y `{3,4}` cuenta bytes, no caracteres.
	 */
	const RFC_REGEX = '/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{2}[A\d]$/u';

	/** Código postal mexicano: cinco dígitos, ni uno más. */
	const ZIP_REGEX = '/^\d{5}$/';

	/** Campos que el endpoint exige en `customer` (public-auth.ts:23). */
	const REQUIRED = array( 'legal_name', 'tax_id', 'tax_system', 'zip' );

	/**
	 * Normaliza los datos del formulario antes de validarlos o enviarlos.
	 *
	 * El RFC se pone en mayúsculas aquí y no en el servidor por una razón
	 * concreta: `fiscal-validation.ts` valida `tax_id.trim().toUpperCase()` pero
	 * `stamp.ts` reenvía `rfc: input.customer.tax_id` SIN normalizar. Un RFC en
	 * minúsculas pasaría el filtro y llegaría así a Facturapi. Decisión D3.
	 *
	 * `legal_name` es la excepción: solo se recorta. Tiene que coincidir
	 * carácter a carácter con la Constancia de Situación Fiscal, y decidir por
	 * el usuario cómo se escribe su razón social es cambiar un dato fiscal.
	 *
	 * @param array $raw Datos tal y como llegan del formulario.
	 * @return array Receptor con la forma que espera la API.
	 */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$get = function ( $key ) use ( $raw ) {
			return isset( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		};

		$customer = array(
			'legal_name' => trim( $get( 'legal_name' ) ),
			// Los espacios interiores también se van: nadie escribe un RFC con
			// espacios a propósito, pero se teclean al copiar de una CSF en PDF.
			'tax_id'     => strtoupper( preg_replace( '/\s+/u', '', $get( 'tax_id' ) ) ),
			'tax_system' => trim( $get( 'tax_system' ) ),
			// El CP se recorta pero NO se rellena con ceros. Corregir en silencio
			// un dato fiscal es peor que rechazarlo: 00680 y 68000 son sitios
			// distintos y solo el usuario sabe cuál es el suyo.
			'zip'        => preg_replace( '/\s+/u', '', $get( 'zip' ) ),
		);

		$email = strtolower( trim( $get( 'email' ) ) );

		// Ausente no es lo mismo que vacío: el servidor trata `email` como
		// opcional, y una cadena vacía llegaría a Facturapi como dirección.
		if ( '' !== $email ) {
			$customer['email'] = $email;
		}

		return $customer;
	}

	/**
	 * Valida el receptor, el uso de CFDI y la forma de pago contra las mismas
	 * reglas que aplicaría el servidor.
	 *
	 * @param array  $customer     Receptor ya saneado por sanitize().
	 * @param array  $catalogs     Catálogos de FacturaMX_Client::catalogs().
	 * @param string $use          Uso de CFDI (c_UsoCFDI).
	 * @param string $payment_form Forma de pago (c_FormaPago).
	 * @return true|WP_Error
	 */
	public static function validate( $customer, $catalogs, $use, $payment_form ) {
		$regimes = self::codes( $catalogs, 'tax_regimes' );
		$uses    = self::codes( $catalogs, 'cfdi_uses' );
		$forms   = self::codes( $catalogs, 'payment_forms' );

		// Jidoka: sin catálogos no se valida a la ligera, se para. Si catalogs()
		// falló, dar por bueno un régimen sería adivinar — y el precio de
		// adivinar mal es un timbre gastado en un CFDI que el SAT rechaza.
		if ( empty( $regimes ) || empty( $uses ) || empty( $forms ) ) {
			return new WP_Error(
				'facturamx_no_catalogs',
				__( 'No se pudieron cargar los catálogos del SAT. Comprueba la conexión antes de facturar.', 'facturamx-for-woocommerce' )
			);
		}

		$customer = is_array( $customer ) ? $customer : array();

		foreach ( self::REQUIRED as $field ) {
			if ( ! isset( $customer[ $field ] ) || '' === trim( (string) $customer[ $field ] ) ) {
				// Texto literal de public-auth.ts:64.
				return self::fail( sprintf( 'customer.%s es obligatorio', $field ) );
			}
		}

		if ( ! preg_match( self::RFC_REGEX, (string) $customer['tax_id'] ) ) {
			return self::fail( 'RFC inválido' );
		}

		if ( ! preg_match( self::ZIP_REGEX, (string) $customer['zip'] ) ) {
			return self::fail( 'Código postal inválido (deben ser 5 dígitos)' );
		}

		if ( ! in_array( (string) $customer['tax_system'], $regimes, true ) ) {
			return self::fail( 'Régimen fiscal fuera del catálogo SAT (c_RegimenFiscal)' );
		}

		// `use` y `payment_form` son obligatorios aquí aunque el payload los
		// admita vacíos: el servidor convierte un `use` vacío en S01, «sin
		// efectos fiscales», que es lo contrario de lo que quiere quien pide una
		// factura. Un default silencioso que produce un documento fiscalmente
		// inútil no es un default, es una trampa. Decisión D4.
		if ( ! in_array( (string) $use, $uses, true ) ) {
			return self::fail( 'Uso de CFDI fuera del catálogo SAT (c_UsoCFDI)' );
		}

		if ( ! in_array( (string) $payment_form, $forms, true ) ) {
			return self::fail( 'Forma de pago fuera del catálogo SAT (c_FormaPago)' );
		}

		return true;
	}

	/**
	 * Extrae los códigos de un catálogo de la respuesta de la API.
	 *
	 * @param array  $catalogs Respuesta de FacturaMX_Client::catalogs().
	 * @param string $key      cfdi_uses, tax_regimes o payment_forms.
	 * @return string[]
	 */
	private static function codes( $catalogs, $key ) {
		if ( ! is_array( $catalogs ) || empty( $catalogs[ $key ] ) || ! is_array( $catalogs[ $key ] ) ) {
			return array();
		}

		$codes = array();

		foreach ( $catalogs[ $key ] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['code'] ) ) {
				$codes[] = (string) $entry['code'];
			}
		}

		return $codes;
	}

	/**
	 * Error de validación del receptor. Los mensajes van sin `__()` a propósito:
	 * son copias literales de los del servidor y traducirlos en un sitio y no en
	 * el otro es exactamente lo que esta clase quiere evitar.
	 *
	 * @param string $message Texto del servidor.
	 * @return WP_Error
	 */
	private static function fail( $message ) {
		return new WP_Error( 'facturamx_invalid_customer', $message );
	}
}
