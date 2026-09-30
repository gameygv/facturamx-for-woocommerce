<?php
/**
 * Stubs mínimos de WordPress para poder ejecutar los tests con PHP a secas.
 *
 * Solo define lo que FacturaMX_Validator necesita. No pretende emular WordPress:
 * si un día el validador necesitara algo más de WP, es señal de que ha dejado
 * de ser puro y hay que revisarlo (ver decisión D1 en s1.1-design.md).
 */

// Los archivos del plugin abortan si no están dentro de WordPress. Definir
// ABSPATH es lo que permite cargarlos desde el runner sin relajar ese guard.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Constantes de tiempo de WordPress. FacturaMX_Client las usa para el TTL de la
// caché de catálogos.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- stub de test.
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- stub de test.
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Hora del sitio. En WordPress depende de la zona horaria configurada; aquí
	 * es UTC, porque lo que se prueba es el formato, no el huso.
	 */
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}
