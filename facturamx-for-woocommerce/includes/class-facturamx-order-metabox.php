<?php
/**
 * Metabox de facturación en la pantalla del pedido.
 *
 * Dos botones de naturaleza distinta y por eso dos caminos distintos:
 *
 *   - **Previsualizar** es gratis y reversible. No llama NUNCA a
 *     `/api/public/invoice` (decisión D1): el endpoint no tiene modo dry_run
 *     —validar y timbrar ocurren en la misma llamada—, así que «mandar el
 *     payload a ver qué contesta» ES timbrar. En su lugar valida en local con
 *     las mismas reglas del servidor, contra catálogos descargados del propio
 *     servidor, y enseña el payload y el cuadre reales.
 *   - **Timbrar** cuesta un timbre y no se deshace.
 *
 * Son dos acciones AJAX separadas con nonces distintos (decisión D5). Con una
 * sola acción y un parámetro `stamp=1`, cualquier `isset()` invertido convierte
 * una previsualización en un timbrado. Contra un acto irreversible, separar
 * caminos vale más que ahorrarse cuatro líneas de nonce.
 *
 * Tres capas contra el doble timbrado (decisión D6), ninguna suficiente sola:
 *
 *   1. El botón se deshabilita al pulsarlo — para el doble clic, no para el F5.
 *   2. `FacturaMX_Invoice::is_stamped()` se comprueba EN SERVIDOR antes de llamar a
 *      `stamp()` — para la petición deliberada, no para la carrera.
 *   3. `external_id` deduplica en la API — para la carrera.
 *
 * @package FacturaMX_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FacturaMX_Order_Metabox {

	/** Quien puede facturar es quien puede gestionar pedidos. */
	const CAPABILITY = 'manage_woocommerce';

	/** Acciones y nonces, deliberadamente distintos entre sí. */
	const ACTION_PREVIEW = 'facturamx_preview_invoice';
	const ACTION_STAMP   = 'facturamx_stamp_invoice';
	const ACTION_QUOTE   = 'facturamx_send_quotation';

	/** Metas de la cotización enviada a FacturaMX. */
	const QUOTE_URL_META    = '_facturamx_quote_url';
	const QUOTE_NUMBER_META = '_facturamx_quote_number';

	/** Prefijo de las metas del receptor (decisión D9). */
	const CUSTOMER_PREFIX = '_facturamx_customer_';

	/** Campos del formulario que se persisten en el pedido. */
	const CUSTOMER_FIELDS = array(
		'legal_name',
		'tax_id',
		'tax_system',
		'zip',
		'email',
		'use',
		'payment_form',
	);

	/**
	 * Engancha el metabox y las dos acciones AJAX.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_' . self::ACTION_PREVIEW, array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::ACTION_STAMP, array( __CLASS__, 'ajax_stamp' ) );
		add_action( 'wp_ajax_' . self::ACTION_QUOTE, array( __CLASS__, 'ajax_quote' ) );
	}

	/**
	 * Pantallas donde vive el metabox.
	 *
	 * Se registran las dos (decisión D10): con HPOS la pantalla del pedido es una
	 * página de administración, no un post type; sin HPOS sigue siendo un post
	 * type. Una tienda puede tener cualquiera de las dos. Registrar solo una haría que el
	 * metabox no apareciera en la otra, y el fallo sería invisible: una pantalla
	 * sin metabox no da error, simplemente no está.
	 *
	 * @param string|null $hpos_screen Id de pantalla de HPOS. Null lo resuelve
	 *                                 de WooCommerce; se inyecta en los tests.
	 * @return string[]
	 */
	public static function screens( $hpos_screen = null ) {
		if ( null === $hpos_screen ) {
			$hpos_screen = function_exists( 'wc_get_page_screen_id' )
				? (string) wc_get_page_screen_id( 'shop-order' )
				: '';
		}

		$screens = array( 'shop_order' );

		if ( '' !== $hpos_screen && 'shop_order' !== $hpos_screen ) {
			$screens[] = (string) $hpos_screen;
		}

		return $screens;
	}

	/**
	 * Registra el metabox en las dos pantallas.
	 */
	public static function register() {
		foreach ( self::screens() as $screen ) {
			add_meta_box(
				'facturamx-order-invoice',
				__( 'Facturación (FacturaMX)', 'facturamx-for-woocommerce' ),
				array( __CLASS__, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Arma la respuesta de previsualización a partir de lo que devolvió el
	 * mapeador. Pura: sin red, sin pedido, sin opciones.
	 *
	 * El cuadre se recalcula aquí aunque `FacturaMX_Order_Mapper::build()` ya lo haya
	 * comprobado. No es desconfianza: el operador necesita VER el número, no
	 * solo saber que alguien lo comprobó. Un «cuadra» sin cifra no se puede
	 * contrastar con nada.
	 *
	 * @param array|WP_Error $payload_or_error Salida de FacturaMX_Order_Mapper::map().
	 * @param float          $order_total      Total del pedido de WooCommerce.
	 * @return array
	 */
	public static function preview_result( $payload_or_error, $order_total ) {
		if ( is_wp_error( $payload_or_error ) ) {
			return array(
				'ok'             => false,
				'code'           => $payload_or_error->get_error_code(),
				'verdict'        => $payload_or_error->get_error_message(),
				'payload'        => array(),
				'reconciliation' => array(),
			);
		}

		$items = isset( $payload_or_error['items'] ) && is_array( $payload_or_error['items'] )
			? $payload_or_error['items']
			: array();

		$cfdi_total = FacturaMX_Order_Mapper::reconcile( $items );
		$difference = round( $cfdi_total - (float) $order_total, 2 );

		return array(
			'ok'             => true,
			'code'           => '',
			'verdict'        => sprintf(
				/* translators: 1: número de conceptos, 2: total del CFDI. */
				__( 'El CFDI cuadra con el pedido: %1$d conceptos, %2$s MXN.', 'facturamx-for-woocommerce' ),
				count( $items ),
				number_format( $cfdi_total, 2, '.', '' )
			),
			'payload'        => $payload_or_error,
			'reconciliation' => array(
				'items'       => count( $items ),
				'cfdi_total'  => number_format( $cfdi_total, 2, '.', '' ),
				'order_total' => number_format( (float) $order_total, 2, '.', '' ),
				'difference'  => number_format( $difference, 2, '.', '' ),
			),
		);
	}

	/**
	 * Pinta el metabox.
	 *
	 * @param WP_Post|WC_Order $post_or_order Lo que WordPress o HPOS le pasan.
	 */
	public static function render( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'No se pudo leer este pedido.', 'facturamx-for-woocommerce' ) . '</p>';
			return;
		}

		if ( FacturaMX_Invoice::is_stamped( $order ) ) {
			self::render_stamped( $order );
			return;
		}

		// Sin token no hay nada que ofrecer: enseñar los botones sería invitar a
		// un fallo que ya sabemos que va a ocurrir.
		if ( ! FacturaMX_Settings::is_configured() ) {
			printf(
				'<p>%s</p><p><a href="%s">%s</a></p>',
				esc_html__( 'Falta configurar la URL de la API y el token para poder facturar.', 'facturamx-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=facturamx-settings' ) ),
				esc_html__( 'Ir a los ajustes de FacturaMX', 'facturamx-for-woocommerce' )
			);
			return;
		}

		$catalogs = FacturaMX_Client::catalogs();

		// Jidoka: sin catálogos no se factura a ciegas. Un formulario con los
		// selectores vacíos deja teclear un régimen inventado, y eso no se
		// descubre hasta que el SAT lo rechaza.
		if ( is_wp_error( $catalogs ) ) {
			printf(
				'<p>%s</p>',
				esc_html( $catalogs->get_error_message() )
			);
			return;
		}

		$saved = self::saved_customer( $order );

		self::render_form( $order, $catalogs, $saved );
	}

	/**
	 * Pedido ya facturado: los datos de la factura y ningún botón.
	 *
	 * @param WC_Order $order Pedido.
	 */
	private static function render_stamped( $order ) {
		$data      = FacturaMX_Invoice::stamped_data( $order );
		$reference = trim( $data['_facturamx_series'] . '-' . $data['_facturamx_folio'], '-' );

		echo '<p><strong>' . esc_html__( 'Este pedido ya está facturado.', 'facturamx-for-woocommerce' ) . '</strong></p>';

		if ( '' !== $reference ) {
			echo '<p>' . esc_html__( 'Serie y folio:', 'facturamx-for-woocommerce' ) . ' <code>' . esc_html( $reference ) . '</code></p>';
		}

		echo '<p>' . esc_html__( 'UUID:', 'facturamx-for-woocommerce' ) . '<br><code style="font-size:11px;">' . esc_html( $data['_facturamx_uuid'] ) . '</code></p>';

		if ( '' !== $data['_facturamx_stamped_at'] ) {
			echo '<p>' . esc_html__( 'Timbrada el:', 'facturamx-for-woocommerce' ) . ' ' . esc_html( $data['_facturamx_stamped_at'] ) . '</p>';
		}

		// Los enlaces NO son `_facturamx_pdf_url` / `_facturamx_xml_url`. Esas URL apuntan al
		// endpoint de FacturaMX, que exige `Authorization: Bearer` y devuelve 401
		// a cualquier navegador: los dos botones que pintó S1.4 nunca llegaron a
		// funcionar. Se sustituyen por el proxy de WordPress, que pone el token
		// del lado del servidor. Las metas se siguen guardando —son el registro
		// de lo que devolvió la API— pero ya no se pintan como href.
		foreach ( array(
			'pdf' => __( 'Descargar PDF', 'facturamx-for-woocommerce' ),
			'xml' => __( 'Descargar XML', 'facturamx-for-woocommerce' ),
		) as $format => $label ) {
			$href = FacturaMX_Download::url( $order, $format );

			if ( '' !== $href ) {
				printf(
					'<p><a class="button" href="%s">%s</a></p>',
					esc_url( $href ),
					esc_html( $label )
				);
			}
		}
	}

	/**
	 * Formulario del receptor y los dos botones.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param array    $catalogs Catálogos del SAT.
	 * @param array    $saved    Receptor guardado en el pedido, si lo hay.
	 */
	private static function render_form( $order, $catalogs, $saved ) {
		$order_id = (int) $order->get_id();
		?>
		<div class="facturamx-metabox" data-order="<?php echo esc_attr( (string) $order_id ); ?>">
			<p>
				<label for="facturamx-legal-name"><?php esc_html_e( 'Razón social', 'facturamx-for-woocommerce' ); ?></label>
				<input type="text" id="facturamx-legal-name" class="widefat" value="<?php echo esc_attr( $saved['legal_name'] ); ?>">
				<span class="description"><?php esc_html_e( 'Tal y como aparece en la Constancia de Situación Fiscal.', 'facturamx-for-woocommerce' ); ?></span>
			</p>
			<p>
				<label for="facturamx-tax-id"><?php esc_html_e( 'RFC', 'facturamx-for-woocommerce' ); ?></label>
				<input type="text" id="facturamx-tax-id" class="widefat" value="<?php echo esc_attr( $saved['tax_id'] ); ?>">
			</p>
			<p>
				<label for="facturamx-zip"><?php esc_html_e( 'Código postal fiscal', 'facturamx-for-woocommerce' ); ?></label>
				<input type="text" id="facturamx-zip" class="widefat" value="<?php echo esc_attr( $saved['zip'] ); ?>">
			</p>
			<p>
				<label for="facturamx-email"><?php esc_html_e( 'Correo (opcional)', 'facturamx-for-woocommerce' ); ?></label>
				<input type="email" id="facturamx-email" class="widefat" value="<?php echo esc_attr( $saved['email'] ); ?>">
			</p>
			<p>
				<label for="facturamx-tax-system"><?php esc_html_e( 'Régimen fiscal', 'facturamx-for-woocommerce' ); ?></label>
				<?php self::render_select( 'facturamx-tax-system', $catalogs, 'tax_regimes', $saved['tax_system'] ); ?>
			</p>
			<p>
				<label for="facturamx-use"><?php esc_html_e( 'Uso del CFDI', 'facturamx-for-woocommerce' ); ?></label>
				<?php self::render_select( 'facturamx-use', $catalogs, 'cfdi_uses', $saved['use'] ); ?>
			</p>
			<p>
				<label for="facturamx-payment-form"><?php esc_html_e( 'Forma de pago', 'facturamx-for-woocommerce' ); ?></label>
				<?php self::render_select( 'facturamx-payment-form', $catalogs, 'payment_forms', $saved['payment_form'] ); ?>
			</p>

			<p>
				<button type="button" class="button" id="facturamx-preview"><?php esc_html_e( 'Previsualizar', 'facturamx-for-woocommerce' ); ?></button>
				<button type="button" class="button button-primary" id="facturamx-stamp" disabled><?php esc_html_e( 'Timbrar', 'facturamx-for-woocommerce' ); ?></button>
			</p>
			<p>
				<?php $facturamx_quote_url = (string) $order->get_meta( self::QUOTE_URL_META, true ); ?>
				<?php if ( '' !== $facturamx_quote_url ) : ?>
					<a href="<?php echo esc_url( $facturamx_quote_url ); ?>" target="_blank" rel="noopener">
						<?php
						/* translators: %s: número de cotización en FacturaMX. */
						echo esc_html( sprintf( __( 'Ver la cotización #%s en FacturaMX', 'facturamx-for-woocommerce' ), (string) $order->get_meta( self::QUOTE_NUMBER_META, true ) ) );
						?>
					</a>
				<?php else : ?>
					<button type="button" class="button" id="facturamx-quote"><?php esc_html_e( 'Enviar a FacturaMX como cotización', 'facturamx-for-woocommerce' ); ?></button>
					<br><span class="description"><?php esc_html_e( 'No timbra nada: la cotización se convierte en factura desde el panel de FacturaMX.', 'facturamx-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Previsualizar no emite nada ni gasta timbres. Timbrar sí, y no se puede deshacer.', 'facturamx-for-woocommerce' ); ?>
			</p>

			<div id="facturamx-result"></div>
			<pre id="facturamx-payload" style="display:none;max-height:220px;overflow:auto;font-size:11px;background:#f6f7f7;padding:8px;"></pre>
		</div>

		<script>
		jQuery( function ( $ ) {
			var box     = $( '.facturamx-metabox' );
			var result  = $( '#facturamx-result' );
			var payload = $( '#facturamx-payload' );
			var stamp   = $( '#facturamx-stamp' );

			function fields() {
				return {
					order_id: box.data( 'order' ),
					customer: {
						legal_name: $( '#facturamx-legal-name' ).val(),
						tax_id: $( '#facturamx-tax-id' ).val(),
						tax_system: $( '#facturamx-tax-system' ).val(),
						zip: $( '#facturamx-zip' ).val(),
						email: $( '#facturamx-email' ).val()
					},
					use: $( '#facturamx-use' ).val(),
					payment_form: $( '#facturamx-payment-form' ).val()
				};
			}

			function show( ok, message ) {
				result.css( 'color', ok ? '#008a20' : '#d63638' ).text( message );
			}

			$( '#facturamx-preview' ).on( 'click', function () {
				var button = $( this );
				button.prop( 'disabled', true );
				stamp.prop( 'disabled', true );
				payload.hide();
				show( true, <?php echo wp_json_encode( __( 'Comprobando…', 'facturamx-for-woocommerce' ) ); ?> );

				$.post( ajaxurl, $.extend( fields(), {
					action: <?php echo wp_json_encode( self::ACTION_PREVIEW ); ?>,
					_ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( self::ACTION_PREVIEW ) ); ?>
				} ) ).done( function ( response ) {
					var data = response && response.data ? response.data : {};
					show( !! data.ok, data.verdict || '' );

					if ( data.ok ) {
						payload.text( JSON.stringify( data.payload, null, 2 ) ).show();
						stamp.prop( 'disabled', false );
					}
				} ).fail( function () {
					show( false, <?php echo wp_json_encode( __( 'No se pudo completar la previsualización.', 'facturamx-for-woocommerce' ) ); ?> );
				} ).always( function () {
					button.prop( 'disabled', false );
				} );
			} );

			stamp.on( 'click', function () {
				if ( ! window.confirm( <?php echo wp_json_encode( __( 'Se va a emitir una factura real. Esta acción gasta un timbre y no se puede deshacer. ¿Continuar?', 'facturamx-for-woocommerce' ) ); ?> ) ) {
					return;
				}

				// Se deshabilita ANTES de la petición y no se vuelve a habilitar
				// pase lo que pase: si hay que reintentar, se recarga la pantalla.
				// Un botón que revive tras un timeout es una factura duplicada.
				stamp.prop( 'disabled', true );
				$( '#facturamx-preview' ).prop( 'disabled', true );
				show( true, <?php echo wp_json_encode( __( 'Timbrando… no cierres esta pantalla.', 'facturamx-for-woocommerce' ) ); ?> );

				$.post( ajaxurl, $.extend( fields(), {
					action: <?php echo wp_json_encode( self::ACTION_STAMP ); ?>,
					_ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( self::ACTION_STAMP ) ); ?>
				} ) ).done( function ( response ) {
					var data = response && response.data ? response.data : {};
					show( !! data.ok, data.message || '' );

					if ( data.ok ) {
						window.location.reload();
					}
				} ).fail( function ( xhr ) {
					var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					show( false, data.message || <?php echo wp_json_encode( __( 'No se pudo completar el timbrado. Recarga la pantalla antes de reintentar.', 'facturamx-for-woocommerce' ) ); ?> );
				} );
			} );

			$( '#facturamx-quote' ).on( 'click', function () {
				var button = $( this );
				button.prop( 'disabled', true );
				show( true, <?php echo wp_json_encode( __( 'Enviando a FacturaMX…', 'facturamx-for-woocommerce' ) ); ?> );
				$.post( ajaxurl, $.extend( fields(), {
					action: <?php echo wp_json_encode( self::ACTION_QUOTE ); ?>,
					_ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( self::ACTION_QUOTE ) ); ?>
				} ) ).done( function ( response ) {
					var data = response && response.data ? response.data : {};
					show( !! data.ok, data.message || '' );
					if ( data.ok ) {
						window.location.reload();
					} else {
						button.prop( 'disabled', false );
					}
				} ).fail( function ( xhr ) {
					var data = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					show( false, data.message || <?php echo wp_json_encode( __( 'No se pudo enviar la cotización.', 'facturamx-for-woocommerce' ) ); ?> );
					button.prop( 'disabled', false );
				} );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Pinta un selector a partir de un catálogo del SAT.
	 *
	 * @param string $id       Id del elemento.
	 * @param array  $catalogs Catálogos completos.
	 * @param string $key      cfdi_uses, tax_regimes o payment_forms.
	 * @param string $selected Valor guardado.
	 */
	private static function render_select( $id, $catalogs, $key, $selected ) {
		$entries = isset( $catalogs[ $key ] ) && is_array( $catalogs[ $key ] ) ? $catalogs[ $key ] : array();

		echo '<select id="' . esc_attr( $id ) . '" class="widefat">';
		echo '<option value="">' . esc_html__( '— Elegir —', 'facturamx-for-woocommerce' ) . '</option>';

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['code'] ) ) {
				continue;
			}

			$code = (string) $entry['code'];
			$name = isset( $entry['name'] ) ? (string) $entry['name'] : $code;

			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				selected( $code, $selected, false ),
				esc_html( $code . ' — ' . $name )
			);
		}

		echo '</select>';
	}

	/**
	 * Handler de «Previsualizar». No timbra: no hay ninguna llamada a la API de
	 * facturación en este camino, y así se verifica con grep, no de memoria.
	 */
	public static function ajax_preview() {
		check_ajax_referer( self::ACTION_PREVIEW );

		$order = self::authorize();

		if ( is_wp_error( $order ) ) {
			self::fail( $order, 403 );
		}

		$request = self::read_request();
		$check   = self::validate_request( $request );

		if ( is_wp_error( $check ) ) {
			self::fail( $check );
		}

		// El receptor se guarda al previsualizar (decisión D9): así sobrevive a
		// una recarga y S1.5 escribirá estas mismas metas desde el portal.
		self::save_customer( $order, $request );

		$payload = FacturaMX_Order_Mapper::map(
			$order,
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
			)
		);

		wp_send_json_success( self::preview_result( $payload, (float) $order->get_total() ) );
	}

	/**
	 * Handler de «Enviar a FacturaMX como cotización». No timbra: crea una
	 * cotización en borrador (idempotente por el id del pedido) y guarda su enlace.
	 * Los datos fiscales pueden ir incompletos: se completan en el panel.
	 */
	public static function ajax_quote() {
		check_ajax_referer( self::ACTION_QUOTE );

		$order = self::authorize();
		if ( is_wp_error( $order ) ) {
			self::fail( $order, 403 );
		}
		if ( FacturaMX_Invoice::is_stamped( $order ) ) {
			self::fail(
				new WP_Error( 'facturamx_already_stamped', __( 'Este pedido ya tiene factura.', 'facturamx-for-woocommerce' ) ),
				409
			);
		}

		$request = self::read_request();
		self::save_customer( $order, $request );

		$payload = FacturaMX_Order_Mapper::map(
			$order,
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
				'external_id'  => (string) $order->get_id(),
			)
		);
		if ( is_wp_error( $payload ) ) {
			self::fail( $payload );
		}

		$quote = FacturaMX_Client::send_quotation( FacturaMX_Order_Mapper::to_quotation( $payload ) );
		if ( is_wp_error( $quote ) ) {
			self::fail( $quote, 502 );
		}

		$order->update_meta_data( self::QUOTE_URL_META, esc_url_raw( (string) $quote['quote_url'] ) );
		$order->update_meta_data( self::QUOTE_NUMBER_META, (string) $quote['quote_number'] );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %s: número de cotización. */
				__( 'FacturaMX: pedido enviado como cotización #%s (sin timbrar).', 'facturamx-for-woocommerce' ),
				(string) $quote['quote_number']
			),
			false
		);

		wp_send_json_success(
			array(
				'ok'        => true,
				'message'   => sprintf(
					/* translators: %s: número de cotización. */
					__( 'Cotización #%s creada en FacturaMX.', 'facturamx-for-woocommerce' ),
					(string) $quote['quote_number']
				),
				'quote_url' => (string) $quote['quote_url'],
			)
		);
	}

	/**
	 * Handler de «Timbrar». El único camino del plugin que gasta un timbre.
	 */
	public static function ajax_stamp() {
		check_ajax_referer( self::ACTION_STAMP );

		$order = self::authorize();

		if ( is_wp_error( $order ) ) {
			self::fail( $order, 403 );
		}

		// Capa 2 contra el doble timbrado (D6). En servidor, no en el navegador:
		// el botón deshabilitado no detiene una petición hecha a mano.
		if ( FacturaMX_Invoice::is_stamped( $order ) ) {
			self::fail(
				new WP_Error(
					'facturamx_already_stamped',
					__( 'Este pedido ya tiene factura. Recarga la pantalla para verla.', 'facturamx-for-woocommerce' )
				),
				409
			);
		}

		$request = self::read_request();
		$check   = self::validate_request( $request );

		// Se revalida entero aunque la previsualización haya pasado: entre una y
		// otra el usuario pudo cambiar el formulario, y lo que llega es POST.
		if ( is_wp_error( $check ) ) {
			self::fail( $check );
		}

		self::save_customer( $order, $request );

		$payload = FacturaMX_Order_Mapper::map(
			$order,
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
			)
		);

		if ( is_wp_error( $payload ) ) {
			self::fail( $payload );
		}

		$response = FacturaMX_Client::stamp( $payload );

		// Decisión D7: un fallo no deja rastro en la meta. Tras un timeout nadie
		// sabe si la factura se emitió, y escribir «falló» sería afirmarlo.
		if ( is_wp_error( $response ) ) {
			self::fail( $response, 502 );
		}

		$meta = FacturaMX_Invoice::persist( $order, $response );

		if ( is_wp_error( $meta ) ) {
			self::fail( $meta, 502 );
		}

		wp_send_json_success(
			array(
				'ok'      => true,
				'message' => FacturaMX_Invoice::note_text( $response ),
				'uuid'    => $meta['_facturamx_uuid'],
			)
		);
	}

	/**
	 * Comprueba permiso y pedido. Devuelve el pedido o el error a responder.
	 *
	 * @return WC_Order|WP_Error
	 */
	private static function authorize() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'facturamx_forbidden',
				__( 'No tienes permisos para facturar pedidos.', 'facturamx-for-woocommerce' )
			);
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() va antes, en cada handler.
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			return new WP_Error(
				'facturamx_invalid_order',
				__( 'No se encontró el pedido que se quiere facturar.', 'facturamx-for-woocommerce' )
			);
		}

		return $order;
	}

	/**
	 * Lee y sanea el formulario. El saneado vive entero en FacturaMX_Receptor.
	 *
	 * @return array
	 */
	private static function read_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el nonce
		// lo comprueba check_ajax_referer() al entrar en cada handler.
		$raw = isset( $_POST['customer'] ) && is_array( $_POST['customer'] )
			? wp_unslash( $_POST['customer'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- lo sanea FacturaMX_Receptor::sanitize() justo abajo, campo a campo.
			: array();

		return array(
			'customer'     => FacturaMX_Receptor::sanitize( $raw ),
			'use'          => isset( $_POST['use'] ) ? sanitize_text_field( wp_unslash( $_POST['use'] ) ) : '',
			'payment_form' => isset( $_POST['payment_form'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_form'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Valida el receptor contra los catálogos descargados del servidor.
	 *
	 * @param array $request Salida de read_request().
	 * @return true|WP_Error
	 */
	private static function validate_request( $request ) {
		$catalogs = FacturaMX_Client::catalogs();

		if ( is_wp_error( $catalogs ) ) {
			return $catalogs;
		}

		return FacturaMX_Receptor::validate(
			$request['customer'],
			$catalogs,
			$request['use'],
			$request['payment_form']
		);
	}

	/**
	 * Guarda el receptor en la meta del pedido (decisión D9).
	 *
	 * @param WC_Order $order   Pedido.
	 * @param array    $request Salida de read_request().
	 */
	private static function save_customer( $order, $request ) {
		$values = array_merge(
			$request['customer'],
			array(
				'use'          => $request['use'],
				'payment_form' => $request['payment_form'],
			)
		);

		foreach ( self::CUSTOMER_FIELDS as $field ) {
			$order->update_meta_data(
				self::CUSTOMER_PREFIX . $field,
				isset( $values[ $field ] ) ? (string) $values[ $field ] : ''
			);
		}

		$order->save();
	}

	/**
	 * Lee el receptor guardado, con todas las claves presentes.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	private static function saved_customer( $order ) {
		$saved = array();

		foreach ( self::CUSTOMER_FIELDS as $field ) {
			$saved[ $field ] = (string) $order->get_meta( self::CUSTOMER_PREFIX . $field, true );
		}

		// La forma de pago por defecto sale de los ajustes: es la del sitio, no
		// la del pedido, y teclearla en cada factura invita a equivocarse.
		if ( '' === $saved['payment_form'] ) {
			$saved['payment_form'] = (string) FacturaMX_Settings::get( 'payment_form' );
		}

		return $saved;
	}

	/**
	 * Resuelve el pedido a partir de lo que pasa cada pantalla: HPOS entrega un
	 * WC_Order, el almacenamiento clásico un WP_Post.
	 *
	 * @param WP_Post|WC_Order $post_or_order Argumento del callback.
	 * @return WC_Order|false
	 */
	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}

		$id = is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0;

		return $id ? wc_get_order( $id ) : false;
	}

	/**
	 * Responde un error y termina. Un solo sitio para que el formato de la
	 * respuesta de error sea siempre el mismo.
	 *
	 * @param WP_Error $error  Error a responder.
	 * @param int      $status Código HTTP.
	 */
	private static function fail( $error, $status = 422 ) {
		wp_send_json_error(
			array(
				'ok'      => false,
				'code'    => $error->get_error_code(),
				'verdict' => $error->get_error_message(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}
}
