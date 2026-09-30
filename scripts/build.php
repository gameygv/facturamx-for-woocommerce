<?php
/**
 * Empaqueta el plugin para wordpress.org (S2.5).
 *
 * Genera dist/facturamx-for-woocommerce-<versión>.zip con SOLO lo que se
 * ejecuta en una tienda: sin tests/, sin archivos ocultos y sin nada de dev/,
 * work/ o .raise/ (que viven fuera de la carpeta del plugin). Antes de empaquetar
 * exige que el lint, los tests y la coherencia de versión estén en verde.
 *
 * Uso:  php scripts/build.php
 *       (en Windows, si falta ZipArchive: php -d extension=zip scripts/build.php)
 */

$root   = dirname( __DIR__ );
$slug   = 'facturamx-for-woocommerce';
$source = $root . DIRECTORY_SEPARATOR . $slug;
$dist   = $root . DIRECTORY_SEPARATOR . 'dist';

// Carpetas y archivos del plugin que NO van al paquete.
$exclude_dirs  = array( 'tests' );
$exclude_files = array( '.DS_Store', 'Thumbs.db' );

foreach ( array( 'lint.php', 'check-version.php' ) as $gate ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . DIRECTORY_SEPARATOR . $gate ), $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "Gate fallido: $gate. No se empaqueta.\n" );
		exit( 1 );
	}
}
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $source . '/tests/run-tests.php' ), $code );
if ( 0 !== $code ) {
	fwrite( STDERR, "Los tests fallan. No se empaqueta.\n" );
	exit( 1 );
}

if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $source . "/$slug.php" ), $m ) ) {
	fwrite( STDERR, "No se encontró la versión en la cabecera.\n" );
	exit( 1 );
}
$version = $m[1];

if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0755, true );
}
$zip_path = $dist . DIRECTORY_SEPARATOR . "$slug-$version.zip";
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "Falta la extensión zip de PHP. Prueba: php -d extension=zip scripts/build.php
" );
	exit( 1 );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "No se pudo crear $zip_path\n" );
	exit( 1 );
}

$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ) );
$included = 0;
foreach ( $files as $file ) {
	$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $source ) + 1 ) );
	$first    = explode( '/', $relative )[0];

	if ( in_array( $first, $exclude_dirs, true ) || in_array( basename( $relative ), $exclude_files, true ) ) {
		continue;
	}
	if ( 0 === strpos( basename( $relative ), '.' ) ) {
		continue; // Archivos ocultos: nunca en el paquete.
	}

	$zip->addFile( $file->getPathname(), "$slug/$relative" );
	++$included;
}
$zip->close();

printf( "Paquete: %s (%d archivos, %.1f KB)\n", $zip_path, $included, filesize( $zip_path ) / 1024 );
