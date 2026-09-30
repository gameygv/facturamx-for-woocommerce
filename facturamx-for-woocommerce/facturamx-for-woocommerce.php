<?php
/**
 * Plugin Name:       FacturaMX for WooCommerce
 * Plugin URI:        https://facturamx.top/plugin-woocommerce
 * Description:       Autofacturación CFDI 4.0 para WooCommerce a través de la API pública de FacturaMX.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 * Author:            FacturaMX
 * Author URI:        https://facturamx.top
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       facturamx-for-woocommerce
 *
 * `Requires Plugins` NO sustituye a facturamx_woocommerce_is_active(): la
 * cabecera solo existe desde WordPress 6.5 y aquí se declara soportar 6.0, así
 * que en 6.0–6.4 la comprobación en tiempo de ejecución es la única red.
 *
 * `WC tested up to` es empírico —E1 se verificó contra WooCommerce 10.9.4 sobre
 * WordPress 7.0.2 y S2.5 contra WooCommerce 11.1.2 sobre WordPress 7.1.2—. `WC requires at least` es un suelo declarado a partir de la
 * API que se usa, no de una prueba: todo lo posterior a WooCommerce 3.x que
 * toca el plugin (FeaturesUtil, wc_get_page_screen_id) va protegido con
 * class_exists o function_exists.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FACTURAMX_VERSION', '0.1.0' );
define( 'FACTURAMX_PLUGIN_FILE', __FILE__ );
define( 'FACTURAMX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FACTURAMX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * WooCommerce es un requisito duro: sin él no hay pedidos que facturar.
 *
 * Se comprueba en la activación (para poder abortarla con un mensaje claro) y
 * también en `plugins_loaded`, porque WooCommerce puede desactivarse después
 * con el plugin ya activo.
 *
 * @return bool
 */
function facturamx_woocommerce_is_active() {
	return class_exists( 'WooCommerce' ) || in_array(
		'woocommerce/woocommerce.php',
		(array) get_option( 'active_plugins', array() ),
		true
	);
}

/**
 * Aborta la activación si falta WooCommerce.
 */
function facturamx_activate() {
	if ( ! facturamx_woocommerce_is_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__(
				'FacturaMX para WooCommerce necesita WooCommerce para funcionar. Activa WooCommerce y vuelve a intentarlo.',
				'facturamx-for-woocommerce'
			),
			esc_html__( 'Falta WooCommerce', 'facturamx-for-woocommerce' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( __FILE__, 'facturamx_activate' );

/**
 * Aviso persistente si WooCommerce se desactiva con el plugin ya activo.
 */
function facturamx_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'FacturaMX para WooCommerce está activo pero WooCommerce no. El plugin no hará nada hasta que actives WooCommerce.',
		'facturamx-for-woocommerce'
	);
	echo '</p></div>';
}

/**
 * Declara compatibilidad con HPOS (almacenamiento de pedidos en tablas propias).
 *
 * HPOS es el almacenamiento por defecto en las instalaciones nuevas de
 * WooCommerce. Sin esta declaración se marca el plugin como incompatible y la
 * tienda puede deshabilitar su funcionalidad de pedidos.
 */
function facturamx_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'facturamx_declare_hpos_compatibility' );

/**
 * Carga las clases del plugin.
 *
 * Sin autoloader PSR-4 a propósito: son pocos archivos y el plugin se despliega
 * por SCP sin composer (ADR-003 en el diseño de la épica).
 */
function facturamx_load() {
	if ( ! facturamx_woocommerce_is_active() ) {
		add_action( 'admin_notices', 'facturamx_missing_woocommerce_notice' );
		return;
	}

	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-validator.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-settings.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-order-mapper.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-readiness.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-client.php';
	// Receptor e Invoice se cargan fuera del bloque is_admin() a propósito: el
	// portal público de S1.5 los necesita igual que el metabox del admin.
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-receptor.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-invoice.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-eligibility.php';
	// Download va fuera de is_admin() por partida doble: el portal público pinta
	// sus enlaces y, sobre todo, el proxy se sirve por admin-post.php, donde
	// is_admin() vale true pero el visitante no ha iniciado sesión.
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-download.php';
	// El metabox se CARGA siempre aunque solo se ENGANCHE en el admin: en él vive
	// el contrato de las metas del receptor (CUSTOMER_PREFIX y CUSTOMER_FIELDS),
	// y el portal escribe exactamente esas. Duplicar las claves aquí crearía dos
	// sitios donde cambiarlas y uno donde olvidarlo.
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-order-metabox.php';
	require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-portal.php';

	FacturaMX_Settings::init();
	FacturaMX_Download::init();
	FacturaMX_Portal::init();

	if ( is_admin() ) {
		require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-settings-page.php';
		require_once FACTURAMX_PLUGIN_DIR . 'includes/class-facturamx-product-fields.php';

		FacturaMX_Settings_Page::init();
		FacturaMX_Product_Fields::init();
		FacturaMX_Order_Metabox::init();
	}
}
add_action( 'plugins_loaded', 'facturamx_load' );
