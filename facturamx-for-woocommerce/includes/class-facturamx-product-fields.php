<?php
/**
 * Datos fiscales por producto: pestaña en la ficha del producto de WooCommerce.
 *
 * Los ajustes del plugin definen una clave de producto, una unidad y una tasa de
 * IVA para toda la tienda. Eso basta para empezar, pero no para un catálogo
 * real: un café y un envío no comparten ClaveProdServ, y una farmacia vende a la
 * vez medicinas exentas y cosmética gravada. Aquí se afina producto a producto.
 *
 * Dejar un campo vacío NO es un error: significa "usa el valor global". Lo que
 * se rechaza es un valor escrito a mano que no existe en el catálogo del SAT,
 * porque eso llegaría al CFDI y lo rechazaría el timbrado.
 *
 * La tasa de IVA es el único campo donde vacío y «0» significan cosas distintas:
 * vacío es "usa la global", 0 es "este producto está legalmente exento". Ver
 * FacturaMX_Order_Mapper::split_tax().
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Product_Fields {

	/**
	 * Engancha la pestaña y el guardado.
	 */
	public static function init() {
		add_action( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Definición de los campos. Única fuente de verdad: el render, el guardado y
	 * el saneado la consumen.
	 *
	 * Los nombres de meta se toman de FacturaMX_Order_Mapper para que el metabox y el
	 * mapeo no puedan divergir.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function fields() {
		return array(
			FacturaMX_Order_Mapper::META_PRODUCT_KEY => array(
				'label'       => __( 'Clave de producto del SAT', 'facturamx-for-woocommerce' ),
				'validator'   => 'sat_product_key',
				'placeholder' => '50202306',
				'description' => __( 'ClaveProdServ de 8 dígitos. Vacío = usa la clave global de los ajustes.', 'facturamx-for-woocommerce' ),
			),
			FacturaMX_Order_Mapper::META_UNIT_KEY    => array(
				'label'       => __( 'Clave de unidad del SAT', 'facturamx-for-woocommerce' ),
				'validator'   => 'sat_unit_key',
				'placeholder' => 'H87',
				'description' => __( 'ClaveUnidad. H87 = Pieza, KGM = Kilogramo. Vacío = usa la global.', 'facturamx-for-woocommerce' ),
			),
			FacturaMX_Order_Mapper::META_UNIT_NAME   => array(
				'label'       => __( 'Nombre de la unidad', 'facturamx-for-woocommerce' ),
				'validator'   => 'free_text',
				'placeholder' => 'Pieza',
				'description' => __( 'Texto que aparece en la columna Unidad del CFDI. Vacío = usa el global.', 'facturamx-for-woocommerce' ),
			),
			FacturaMX_Order_Mapper::META_IVA_RATE    => array(
				'label'       => __( 'Tasa de IVA', 'facturamx-for-woocommerce' ),
				'validator'   => 'iva_rate',
				'placeholder' => '16',
				'description' => __( 'Escribe 0 SOLO si el producto está legalmente exento (alimentos, medicinas). Vacío = usa la tasa global. Si WooCommerce ya cobró impuesto en el pedido, manda lo que cobró.', 'facturamx-for-woocommerce' ),
			),
		);
	}

	/**
	 * Sanea un valor del formulario.
	 *
	 * Pura: no toca la base de datos ni WordPress más allá de sanitize_text_field.
	 * Por eso puede probarse con `php tests/run-tests.php`.
	 *
	 * @param string $meta_key Clave de la meta.
	 * @param string $value    Valor recibido.
	 * @return string|WP_Error Cadena vacía si el campo se deja en blanco.
	 */
	public static function sanitize( $meta_key, $value ) {
		$fields = self::fields();

		if ( ! isset( $fields[ $meta_key ] ) ) {
			return new WP_Error(
				'facturamx_unknown_field',
				__( 'Campo desconocido.', 'facturamx-for-woocommerce' )
			);
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 'free_text' === $fields[ $meta_key ]['validator'] ) {
			return sanitize_text_field( $value );
		}

		return call_user_func( array( 'FacturaMX_Validator', $fields[ $meta_key ]['validator'] ), $value );
	}

	/**
	 * Añade la pestaña "FacturaMX" al panel de datos del producto.
	 *
	 * La clave es `facturamx` y no algo más corto porque `$tabs` es un array
	 * compartido por todos los plugins que añaden pestañas a la ficha de
	 * producto: dos que elijan la misma clave y uno pierde la suya, sin aviso.
	 *
	 * @param array $tabs Pestañas existentes.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['facturamx'] = array(
			'label'    => __( 'FacturaMX', 'facturamx-for-woocommerce' ),
			'target'   => 'facturamx_product_data',
			'class'    => array(),
			'priority' => 80,
		);

		return $tabs;
	}

	/**
	 * Pinta los tres campos.
	 */
	public static function render_panel() {
		global $post;

		echo '<div id="facturamx_product_data" class="panel woocommerce_options_panel hidden">';

		foreach ( self::fields() as $meta_key => $field ) {
			woocommerce_wp_text_input(
				array(
					'id'          => $meta_key,
					'label'       => $field['label'],
					'placeholder' => $field['placeholder'],
					'description' => $field['description'],
					'desc_tip'    => true,
					'value'       => get_post_meta( $post->ID, $meta_key, true ),
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Guarda los tres campos.
	 *
	 * Un valor inválido NO se persiste y se avisa. Guardarlo "por si acaso"
	 * significaría descubrir el error al timbrar, que es el peor momento.
	 *
	 * @param WC_Product $product Producto que se está guardando.
	 */
	public static function save( $product ) {
		$errors = array();

		foreach ( array_keys( self::fields() ) as $meta_key ) {
			if ( ! isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce ya verificó el nonce del producto.
				continue;
			}

			$raw   = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce de WooCommerce; lo sanea self::sanitize() en la línea siguiente.
			$clean = self::sanitize( $meta_key, $raw );

			if ( is_wp_error( $clean ) ) {
				$errors[] = $clean->get_error_message();
				continue;
			}

			$product->update_meta_data( $meta_key, $clean );
		}

		if ( $errors ) {
			set_transient( self::notice_key(), $errors, 60 );
		}
	}

	/**
	 * Muestra los errores del último guardado, una sola vez.
	 */
	public static function render_notices() {
		$errors = get_transient( self::notice_key() );

		if ( ! $errors ) {
			return;
		}

		delete_transient( self::notice_key() );

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'FacturaMX no guardó estos campos del producto:', 'facturamx-for-woocommerce' );
		echo '</p><ul style="list-style:disc;margin-left:2em">';

		foreach ( (array) $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Clave del transient de avisos, por usuario: dos administradores editando
	 * a la vez no deben verse los errores del otro.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'facturamx_product_errors_' . get_current_user_id();
	}
}
