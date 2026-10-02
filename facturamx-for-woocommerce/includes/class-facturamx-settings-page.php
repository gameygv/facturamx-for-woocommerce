<?php
/**
 * Pantalla de ajustes y comprobación de conexión.
 *
 * La interpretación del resultado del ping vive en `interpret_ping()`, que es
 * un método **puro**: recibe códigos y devuelve un veredicto, sin tocar la red
 * ni WordPress. Así los cuatro casos del diseño se pueden testear con
 * `php tests/run-tests.php` (decisión D1 en s1.1-design.md).
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Settings_Page {

	/** Slug de la página en el admin. */
	const SLUG = 'facturamx-settings';

	/** Capability exigida: la misma que para ver pedidos. */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Engancha el menú y el handler AJAX.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_ajax_facturamx_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
	}

	/**
	 * Añade la pantalla bajo el menú de WooCommerce.
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'FacturaMX', 'facturamx-for-woocommerce' ),
			__( 'FacturaMX', 'facturamx-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'facturamx-for-woocommerce' ) );
		}

		$values = FacturaMX_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FacturaMX · Autofacturación', 'facturamx-for-woocommerce' ); ?></h1>

			<?php settings_errors( FacturaMX_Settings::OPTION ); ?>

			<?php if ( ! FacturaMX_Settings::is_configured() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Faltan la URL de la API o el token. Hasta que los rellenes, el plugin no emitirá ninguna factura.', 'facturamx-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( FacturaMX_Settings::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( FacturaMX_Settings::fields() as $key => $field ) : ?>
						<?php
						$name  = FacturaMX_Settings::OPTION . '[' . $key . ']';
						$id    = 'facturamx-' . str_replace( '_', '-', $key );
						$value = $values[ $key ];

						if ( 'token' === $field['type'] ) {
							$value = FacturaMX_Settings::mask( $value );
						}
						?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $id ); ?>">
									<?php echo esc_html( $field['label'] ); ?>
									<?php if ( $field['required'] ) : ?>
										<span class="description">*</span>
									<?php endif; ?>
								</label>
							</th>
							<td>
								<input
									type="<?php echo 'number' === $field['type'] ? 'number' : 'text'; ?>"
									id="<?php echo esc_attr( $id ); ?>"
									name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									class="regular-text"
									<?php echo 'number' === $field['type'] ? 'min="1" max="365"' : ''; ?>
								/>
								<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_readiness(); ?>

			<h2><?php esc_html_e( 'Comprobar la conexión', 'facturamx-for-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Comprueba la URL y el token sin emitir ninguna factura ni gastar timbres.', 'facturamx-for-woocommerce' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="facturamx-test-connection">
					<?php esc_html_e( 'Probar conexión', 'facturamx-for-woocommerce' ); ?>
				</button>
				<span id="facturamx-test-result" style="margin-left:10px;"></span>
			</p>

			<?php ob_start(); ?>
			jQuery( function ( $ ) {
				$( '#facturamx-test-connection' ).on( 'click', function () {
					var button = $( this );
					var result = $( '#facturamx-test-result' );

					button.prop( 'disabled', true );
					result.css( 'color', '' ).text( <?php echo wp_json_encode( __( 'Comprobando…', 'facturamx-for-woocommerce' ) ); ?> );

					$.post( ajaxurl, {
						action: 'facturamx_test_connection',
						_ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( 'facturamx_test_connection' ) ); ?>
					} ).done( function ( response ) {
						var ok = response && response.data && response.data.ok;
						result
							.css( 'color', ok ? '#008a20' : '#d63638' )
							.text( response.data.message );
					} ).fail( function () {
						result
							.css( 'color', '#d63638' )
							.text( <?php echo wp_json_encode( __( 'No se pudo completar la comprobación.', 'facturamx-for-woocommerce' ) ); ?> );
					} ).always( function () {
						button.prop( 'disabled', false );
					} );
				} );
			} );
			<?php facturamx_inline_script( 'facturamx-settings', (string) ob_get_clean() ); ?>
		</div>
		<?php
	}

	/**
	 * Lista de lo que falta para poder timbrar (S2.4). Mientras haya algo en
	 * rojo, FacturaMX_Client::stamp() no emite ninguna factura.
	 */
	private static function render_readiness() {
		$verified = FacturaMX_Readiness::is_verified();
		$missing  = FacturaMX_Readiness::products_without_key();
		$ok_style = 'color:#008a20;';
		$ko_style = 'color:#d63638;';
		?>
		<h2><?php esc_html_e( 'Antes del primer timbre', 'facturamx-for-woocommerce' ); ?></h2>
		<ul>
			<li style="<?php echo esc_attr( $verified ? $ok_style : $ko_style ); ?>">
				<?php
				echo $verified
					? esc_html__( '✓ Conexión comprobada con el token actual.', 'facturamx-for-woocommerce' )
					: esc_html__( '✗ Falta comprobar la conexión con el token actual: pulsa «Probar conexión» (abajo) después de guardar.', 'facturamx-for-woocommerce' );
				?>
			</li>
			<li style="<?php echo esc_attr( 0 === $missing['count'] ? $ok_style : $ko_style ); ?>">
				<?php
				if ( 0 === $missing['count'] ) {
					esc_html_e( '✓ Ningún producto publicado saldría con la clave comodín 01010101.', 'facturamx-for-woocommerce' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: %d: número de productos sin clave del SAT propia. */
							_n(
								'✗ %d producto publicado no tiene clave del SAT propia y saldría con la comodín 01010101. Asígnasela en su pestaña «FacturaMX»:',
								'✗ %d productos publicados no tienen clave del SAT propia y saldrían con la comodín 01010101. Asígnasela en su pestaña «FacturaMX»:',
								$missing['count'],
								'facturamx-for-woocommerce'
							),
							$missing['count']
						)
					);
				}
				?>
				<?php if ( $missing['products'] ) : ?>
					<ul style="list-style:disc;margin-left:2em;">
						<?php foreach ( $missing['products'] as $product ) : ?>
							<li><a href="<?php echo esc_url( $product['edit'] ); ?>"><?php echo esc_html( $product['name'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</li>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Mientras haya algo en rojo, el plugin no emite facturas: ni desde el pedido ni desde el portal de autofacturación. Así no se gasta ningún timbre en un CFDI con datos incompletos.', 'facturamx-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * Handler AJAX de "Probar conexión".
	 *
	 * Las dos llamadas (decisión D2 del diseño de S1.1) las hace ahora
	 * FacturaMX_Client::probe(); aquí solo quedan los permisos y la traducción del
	 * resultado. Antes esta pantalla construía URL, cabecera y timeout a mano
	 * y era el segundo sitio del plugin que sabía hablar con la API — el gemba
	 * walk de S1.3 lo detectó y la decisión D6 lo corrigió.
	 *
	 * `interpret_ping()` no cambia: recibe códigos HTTP, no respuestas.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'facturamx_test_connection' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => __( 'No tienes permisos para hacer esta comprobación.', 'facturamx-for-woocommerce' ),
				),
				403
			);
		}

		if ( ! FacturaMX_Settings::is_configured() ) {
			wp_send_json_success(
				array(
					'ok'      => false,
					'message' => __( 'Rellena la URL de la API y el token antes de probar la conexión.', 'facturamx-for-woocommerce' ),
				)
			);
		}

		$probe   = FacturaMX_Client::probe();
		$verdict = self::interpret_ping( $probe['catalogs'], $probe['invoice'], $probe['counts'] );

		// S2.4: solo una conexión probada con la URL y el token ACTUALES deja
		// timbrar. Cambiar cualquiera de los dos obliga a probar de nuevo.
		if ( $verdict['ok'] ) {
			FacturaMX_Readiness::mark_verified();
		}

		wp_send_json_success( $verdict );
	}

	/**
	 * Traduce el resultado de las dos llamadas a un veredicto para el
	 * administrador. Puro: sin red, sin opciones, sin WordPress.
	 *
	 * @param int|string      $catalogs Código HTTP del GET a /catalogs, o el mensaje de error de transporte.
	 * @param int|string|null $invoice  Código HTTP del POST a /invoice, o null si no se llegó a llamar.
	 * @param array           $counts   Recuento de catálogos: tax_regimes, cfdi_uses.
	 * @return array{ok: bool, message: string}
	 */
	public static function interpret_ping( $catalogs, $invoice, $counts = array() ) {
		if ( is_string( $catalogs ) ) {
			return self::verdict(
				false,
				sprintf(
					/* translators: %s: mensaje de error de transporte */
					__( 'No se pudo contactar con el servidor: %s.', 'facturamx-for-woocommerce' ),
					$catalogs
				)
			);
		}

		if ( 200 !== (int) $catalogs || empty( $counts ) ) {
			return self::verdict(
				false,
				__( 'La URL responde, pero no parece una API de FacturaMX.', 'facturamx-for-woocommerce' )
			);
		}

		if ( is_string( $invoice ) ) {
			return self::verdict(
				false,
				sprintf(
					/* translators: %s: mensaje de error de transporte */
					__( 'No se pudo contactar con el servidor: %s.', 'facturamx-for-woocommerce' ),
					$invoice
				)
			);
		}

		if ( 401 === $invoice ) {
			return self::verdict(
				false,
				__( 'El servidor rechazó el token (401). Revísalo en FacturaMX.', 'facturamx-for-woocommerce' )
			);
		}

		// 422 = el token pasó y murió al validar el cuerpo vacío, que es justo
		// lo que buscábamos. 429 = el token pasó y chocó con el rate-limit, que
		// se aplica después de autenticar: también prueba que el token es bueno.
		if ( 422 === $invoice || 429 === $invoice ) {
			return self::verdict(
				true,
				sprintf(
					/* translators: 1: número de regímenes fiscales, 2: número de usos de CFDI */
					__( 'Conexión correcta · %1$d regímenes fiscales, %2$d usos de CFDI', 'facturamx-for-woocommerce' ),
					$counts['tax_regimes'],
					$counts['cfdi_uses']
				)
			);
		}

		return self::verdict(
			false,
			sprintf(
				/* translators: %s: código HTTP inesperado */
				__( 'El servidor respondió de forma inesperada al comprobar el token (%s).', 'facturamx-for-woocommerce' ),
				(string) $invoice
			)
		);
	}

	/**
	 * Forma del veredicto. Aislada para no repetir la estructura seis veces.
	 *
	 * @param bool   $ok      ¿La conexión funciona?
	 * @param string $message Mensaje para el administrador.
	 * @return array{ok: bool, message: string}
	 */
	private static function verdict( $ok, $message ) {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}
}
