<?php
/**
 * La versión se escribe en tres sitios y tiene que decir lo mismo en los tres.
 *
 * - `Version:` en la cabecera del plugin — lo que lee WordPress para saber si
 *   hay actualización.
 * - `FACTURAMX_VERSION` — lo que usa el código (cache busting, cabecera
 *   User-Agent de las peticiones a la API).
 * - `Stable tag:` en el readme.txt — lo que el repositorio de wordpress.org lee
 *   para decidir QUÉ etiqueta de SVN se descargan los usuarios.
 *
 * El tercero es el que hace daño: si `Stable tag` se queda atrás, el
 * repositorio sirve la versión vieja aunque la nueva esté subida, y no avisa.
 * Nadie se acuerda de tocar tres archivos a la vez, así que lo comprueba una
 * máquina.
 *
 * Escrito en PHP y no en bash por la misma razón que scripts/lint.php:
 * PHP_BINARY funciona aunque el PATH de quien lo invoque no tenga php.
 *
 * Uso:  php scripts/check-version.php
 */

$plugin_dir = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'facturamx-for-woocommerce';
$main_file  = $plugin_dir . DIRECTORY_SEPARATOR . 'facturamx-for-woocommerce.php';
$readme     = $plugin_dir . DIRECTORY_SEPARATOR . 'readme.txt';

foreach ( array( $main_file, $readme ) as $required ) {
	if ( ! is_file( $required ) ) {
		fwrite( STDERR, "ERROR: no existe el archivo: {$required}\n" );
		exit( 1 );
	}
}

$main_source   = file_get_contents( $main_file );
$readme_source = file_get_contents( $readme );

/**
 * Extrae un valor con una expresión regular, o aborta diciendo qué faltaba.
 *
 * Que falte la declaración es tan grave como que discrepe: un readme sin
 * `Stable tag` lo rechaza el validador del repositorio.
 *
 * @param string $pattern Expresión con un grupo de captura.
 * @param string $source  Contenido donde buscar.
 * @param string $label   Nombre humano de lo que se busca.
 * @return string
 */
function facturamx_extract( $pattern, $source, $label ) {
	if ( 1 !== preg_match( $pattern, $source, $matches ) ) {
		fwrite( STDERR, "ERROR: no se encontró {$label}\n" );
		exit( 1 );
	}

	return trim( $matches[1] );
}

$found = array(
	'Version: (cabecera del plugin)' => facturamx_extract(
		'/^\s*\*\s*Version:\s*(.+)$/m',
		$main_source,
		'la cabecera Version:'
	),
	'FACTURAMX_VERSION (constante)'  => facturamx_extract(
		"/define\(\s*'FACTURAMX_VERSION'\s*,\s*'([^']+)'\s*\)/",
		$main_source,
		'la constante FACTURAMX_VERSION'
	),
	'Stable tag: (readme.txt)'       => facturamx_extract(
		'/^Stable tag:\s*(.+)$/m',
		$readme_source,
		'la cabecera Stable tag: del readme'
	),
);

$unique = array_unique( array_values( $found ) );

if ( count( $unique ) > 1 ) {
	echo "Versiones DISCREPANTES:\n";

	foreach ( $found as $label => $value ) {
		printf( "  %-32s %s\n", $label, $value );
	}

	exit( 1 );
}

printf( "Versión coherente en los tres sitios — %s\n", reset( $unique ) );
exit( 0 );
