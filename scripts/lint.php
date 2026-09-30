<?php
/**
 * Lint del plugin: comprobación de sintaxis en todos los archivos PHP.
 *
 * No usamos php-cs-fixer ni phpstan: el plugin se despliega por SCP sin build
 * step (ADR-003) y meter composer solo para linting no compensa.
 *
 * Está escrito en PHP —y no en bash— porque PHP_BINARY apunta al intérprete en
 * ejecución, así que funciona aunque quien invoque el script tenga un PATH sin
 * php (que es justo lo que pasa cuando lo lanza `rai gate check`).
 *
 * Uso:  php scripts/lint.php
 */

// Se lintea el plugin y, si existe, `scripts/`.
$roots = array(
	dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'facturamx-for-woocommerce',
	dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'scripts',
);

$count  = 0;
$failed = 0;

foreach ( $roots as $root ) {
	if ( ! is_dir( $root ) ) {
		fwrite( STDERR, "ERROR: no existe el directorio: {$root}\n" );
		exit( 1 );
	}

	$files = new RegexIterator(
		new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ),
		'/\.php$/'
	);

	foreach ( $files as $file ) {
		$path = $file->getPathname();
		$count++;

		$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path );
		exec( $command . ' 2>&1', $output, $status );

		if ( 0 !== $status ) {
			$failed++;
			echo "FALLO: {$path}\n";
			echo implode( "\n", $output ) . "\n";
		}

		$output = array();
	}
}

if ( $failed > 0 ) {
	printf( "\nLint FALLIDO — %d de %d archivos con errores\n", $failed, $count );
	exit( 1 );
}

printf( "Lint OK — %d archivos PHP sin errores de sintaxis\n", $count );
exit( 0 );
