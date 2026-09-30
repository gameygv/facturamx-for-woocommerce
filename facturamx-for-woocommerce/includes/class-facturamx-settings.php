<?php
/**
 * Ajustes del plugin: definición, registro, saneado y lectura.
 *
 * Los ajustes viven en una única opción (`facturamx_settings`) para que guardar
 * sea una sola escritura y desinstalar un solo borrado. La definición de los
 * campos —etiqueta, ayuda, validador, valor por defecto— está en un solo sitio
 * (`fields()`), y tanto el registro como el render y la lectura la consumen.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Settings {

	/** Nombre de la opción en wp_options. */
	const OPTION = 'facturamx_settings';

	/** Grupo de la Settings API. */
	const GROUP = 'facturamx_settings_group';

	/**
	 * Engancha el registro de la opción.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Definición de los campos. Única fuente de verdad (gate DRY del diseño).
	 *
	 * `validator` es el método de FacturaMX_Validator que decide si el valor entra o
	 * se rechaza. `required` marca los dos sin los cuales el plugin no puede
	 * hacer nada.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields() {
		return array(
			'api_url'             => array(
				'label'       => __( 'URL de la API', 'facturamx-for-woocommerce' ),
				'type'        => 'url',
				'default'     => 'https://facturamx.top',
				'validator'   => 'url',
				'required'    => true,
				'description' => __( 'Sin barra final. Ej: https://facturamx.top', 'facturamx-for-woocommerce' ),
			),
			'api_token'           => array(
				'label'       => __( 'Token de la API', 'facturamx-for-woocommerce' ),
				'type'        => 'token',
				'default'     => '',
				'validator'   => 'token',
				'required'    => true,
				'description' => __( 'Se genera en FacturaMX → Empresas → tu empresa → «API para facturación externa». Empieza por fmx_live_.', 'facturamx-for-woocommerce' ),
			),
			'default_product_key' => array(
				'label'       => __( 'Clave de producto del SAT', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '01010101',
				'validator'   => 'sat_product_key',
				'required'    => false,
				'description' => __( 'ClaveProdServ que se usa cuando el producto no tiene una propia. 01010101 = "No existe en el catálogo".', 'facturamx-for-woocommerce' ),
			),
			'default_unit_key'    => array(
				'label'       => __( 'Clave de unidad del SAT', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'H87',
				'validator'   => 'sat_unit_key',
				'required'    => false,
				'description' => __( 'ClaveUnidad. H87 = Pieza, E48 = Unidad de servicio.', 'facturamx-for-woocommerce' ),
			),
			'default_unit_name'   => array(
				'label'       => __( 'Nombre de la unidad', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'Pieza',
				'validator'   => 'free_text',
				'required'    => false,
				'description' => __( 'Texto que aparece en la columna Unidad del CFDI.', 'facturamx-for-woocommerce' ),
			),
			'default_iva_rate'    => array(
				'label'       => __( 'Tasa de IVA por defecto', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0.16',
				'validator'   => 'iva_rate',
				'required'    => false,
				'description' => __( 'Se aplica a las líneas en las que WooCommerce no calculó impuesto, extrayéndola del precio (que por ley ya lo incluye). 16 % general, 8 % franja fronteriza. Lo exento se marca producto a producto.', 'facturamx-for-woocommerce' ),
			),
			'payment_form'        => array(
				'label'       => __( 'Forma de pago', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '03',
				'validator'   => 'payment_form',
				'required'    => false,
				'description' => __( 'c_FormaPago. 03 = Transferencia, 04 = Tarjeta de crédito, 28 = Tarjeta de débito.', 'facturamx-for-woocommerce' ),
			),
			'series'              => array(
				'label'       => __( 'Serie', 'facturamx-for-woocommerce' ),
				'type'        => 'text',
				'default'     => 'WEB',
				'validator'   => 'series',
				'required'    => false,
				'description' => __( 'Déjalo vacío para usar la serie por defecto de la organización.', 'facturamx-for-woocommerce' ),
			),
			'invoicing_window'    => array(
				'label'       => __( 'Ventana de facturación (días)', 'facturamx-for-woocommerce' ),
				'type'        => 'number',
				'default'     => 30,
				'validator'   => 'window_days',
				'required'    => false,
				'description' => __( 'Días desde la compra durante los que el cliente puede autofacturar. El SAT exige que el CFDI se emita dentro del mismo mes.', 'facturamx-for-woocommerce' ),
			),
		);
	}

	/**
	 * Valores por defecto, derivados de la definición de campos.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		$defaults = array();

		foreach ( self::fields() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Todos los ajustes, con los defaults rellenando lo que falte.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Un ajuste concreto.
	 *
	 * @param string $key Clave del ajuste.
	 * @return mixed Cadena vacía si la clave no existe.
	 */
	public static function get( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : '';
	}

	/**
	 * ¿Hay lo mínimo para hablar con la API?
	 *
	 * Las stories siguientes lo consultan antes de intentar nada, para fallar
	 * con un mensaje claro en vez de con un 401 del servidor.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) self::get( 'api_url' ) )
			&& '' !== trim( (string) self::get( 'api_token' ) );
	}

	/**
	 * Enmascara el token para poder mostrarlo sin revelarlo.
	 *
	 * Se revelan los cuatro últimos caracteres —lo justo para que un
	 * administrador reconozca cuál de sus tokens está puesto— y solo si el
	 * valor es lo bastante largo como para que esos cuatro no sean el token
	 * entero.
	 *
	 * @param string $token Token en claro.
	 * @return string
	 */
	public static function mask( $token ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return '';
		}

		if ( strlen( $token ) < 12 ) {
			return '••••';
		}

		return '••••' . substr( $token, -4 );
	}

	/**
	 * ¿Este valor es el enmascarado que la pantalla acaba de pintar?
	 *
	 * Si el administrador guarda sin tocar el campo del token, lo que llega es
	 * la máscara. Guardarla destruiría el token. Se detecta y se conserva el
	 * valor almacenado.
	 *
	 * @param string $value Valor recibido del formulario.
	 * @return bool
	 */
	public static function is_masked( $value ) {
		return 0 === strpos( (string) $value, '••••' );
	}

	/**
	 * Registro en la Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanea el formulario entero.
	 *
	 * Un valor inválido no se persiste: se conserva el que ya estaba y se añade
	 * un error visible. Así un error de dedo en un campo no borra los demás ni
	 * deja el plugin a medias sin avisar (Jidoka).
	 *
	 * @param mixed $input Valores del formulario.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = self::all();
		$clean   = array();

		foreach ( self::fields() as $key => $field ) {
			$previous = $current[ $key ];
			$value    = array_key_exists( $key, $input ) ? $input[ $key ] : '';

			// El token enmascarado significa "no lo he tocado".
			if ( 'token' === $field['type'] && self::is_masked( $value ) ) {
				$clean[ $key ] = $previous;
				continue;
			}

			$result = self::apply_validator( $field['validator'], $value );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					self::OPTION,
					$result->get_error_code(),
					sprintf( '%s: %s', $field['label'], $result->get_error_message() )
				);
				$clean[ $key ] = $previous;
				continue;
			}

			$clean[ $key ] = $result;
		}

		return $clean;
	}

	/**
	 * Despacha al validador correspondiente.
	 *
	 * Los dos casos que no viven en FacturaMX_Validator son los que no tienen forma
	 * de fallar: un texto libre siempre es aceptable una vez saneado, y la
	 * ventana de días se acota en vez de rechazarse. Inventarles un validador
	 * con rama de error sería fingir una validación que no existe.
	 *
	 * @param string $validator Nombre del validador.
	 * @param mixed  $value     Valor a validar.
	 * @return mixed|WP_Error
	 */
	private static function apply_validator( $validator, $value ) {
		if ( 'free_text' === $validator ) {
			return sanitize_text_field( $value );
		}

		if ( 'window_days' === $validator ) {
			return max( 1, min( 365, (int) $value ) );
		}

		return call_user_func( array( 'FacturaMX_Validator', $validator ), $value );
	}
}
