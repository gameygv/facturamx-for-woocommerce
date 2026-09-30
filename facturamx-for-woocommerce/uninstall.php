<?php
/**
 * Desinstalación: borra lo que el plugin guardó en la base de datos.
 *
 * Los ajustes viven en una sola opción (`facturamx_settings`), así que desinstalar es
 * un `delete_option`. No se tocan los metadatos de los pedidos: el UUID y las
 * URLs del CFDI son parte del historial fiscal del pedido, no del plugin, y
 * borrarlos dejaría al comerciante sin rastro de facturas ya timbradas.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'facturamx_settings' );
delete_option( 'facturamx_connection_verified' );
