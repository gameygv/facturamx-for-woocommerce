=== FacturaMX for WooCommerce ===
Contributors: gameygv
Tags: woocommerce, invoicing, cfdi, mexico, sat
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-service CFDI 4.0 invoicing for Mexican WooCommerce stores. Customers invoice their own orders; you never touch the SAT portal.

== Description ==

In Mexico, a customer who needs a tax receipt (CFDI) for an online purchase
usually has to email the store, wait, and hope somebody types their tax details
correctly. This plugin turns that into a page on your own site.

The customer opens the invoicing page, types the order number and the exact
amount they paid, fills in their tax data, and receives a stamped CFDI 4.0. The
store owner does nothing.

= What it does =

* **A public invoicing page.** Add the `[facturamx_portal]` shortcode to any
  page. No customer account is required: the order number plus the exact amount
  paid is what proves the order is theirs.
* **An admin invoicing box.** Every order gets a FacturaMX metabox so you can
  issue the CFDI yourself when a customer asks by phone or email, or send the
  order to FacturaMX as a draft quotation and turn it into an invoice from the
  FacturaMX panel.
* **The invoice reaches the customer by email.** When the customer leaves an
  email address, FacturaMX sends the CFDI with its PDF and XML attached.
* **Tax data is validated before anything is issued.** RFC, postal code, tax
  regime and CFDI use are checked against the SAT catalogs first, so the
  customer fixes a typo in a form instead of finding out through a rejected
  stamp.
* **SAT keys per product.** The store has a global product key, unit key and VAT
  rate; any product can override them from its own FacturaMX tab. A shipping
  line and a bag of coffee do not share a ClaveProdServ.
* **Totals must reconcile.** If the sum of the invoice lines does not match what
  the customer actually paid, the plugin refuses to issue and explains the
  difference. A CFDI that disagrees with the payment is worse than no CFDI.
* **The PDF and the XML are served from your site.** Download links go through
  your own site, so your API token is never exposed to the browser.
* **Guided setup.** Nothing is issued until the connection has been tested with
  the current token and every product has its own SAT key. The settings screen
  lists what is missing, product by product, so the first CFDI is not a
  placeholder.

= What it does not do =

* It does not stamp anything by itself. Stamping is done by FacturaMX, an
  external service — see *External services* below.
* It does not replace your accountant, and it does not decide which products are
  VAT-exempt. You declare that.

= Requirements =

* WooCommerce 8.0 or newer.
* PHP 8.0 or newer.
* An account at FacturaMX (https://facturamx.top) with a public API token, and
  a valid SAT digital seal certificate (CSD) uploaded there.

= Pricing =

The plugin is free. Issuing a CFDI is done by FacturaMX and consumes one
"stamp" (timbre) from your FacturaMX account: new accounts get 3 free stamps,
valid for 30 days, and further stamps are sold in packages at
https://facturamx.top. The plugin never
issues anything on its own: only when a customer or an administrator submits the
invoicing form.

= En español =

**FacturaMX for WooCommerce** permite que tus clientes generen su propia factura
electrónica (CFDI 4.0) desde tu tienda, sin escribirte y sin que tú entres al
portal del SAT.

* **Página de facturación** con la etiqueta `[facturamx_portal]`: el cliente
  escribe su número de pedido y el monto que pagó, llena sus datos fiscales y
  descarga su factura en PDF y XML. También le llega por correo.
* **Facturar desde el pedido** en el administrador de WooCommerce, o mandarlo a
  FacturaMX como cotización para convertirlo en factura desde el panel.
* **Valida RFC, código postal, régimen y uso del CFDI** contra los catálogos del
  SAT antes de timbrar.
* **Un pedido no se factura dos veces**, aunque ya se haya facturado desde el
  panel de FacturaMX.
* **El CFDI cuadra con lo que pagó el cliente**; si no cuadra, no se emite.

El plugin es gratuito. Para timbrar necesitas una cuenta en
[FacturaMX](https://facturamx.top), con tu CSD cargado: al registrarte recibes
3 timbres gratis, válidos por 30 días. Guía de instalación en español:
https://facturamx.top/plugin-woocommerce

== External services ==

This plugin connects to FacturaMX (https://facturamx.top), a third-party CFDI
stamping service, to issue the tax receipts (CFDI 4.0) your customers request.
An account and an API token are required; the plugin does nothing until you
enter one in its settings.

Data is sent in these situations, and only then:

1. When the invoicing form loads the SAT catalogs it needs (tax regimes and
   CFDI uses), the plugin calls `GET /api/public/catalogs`. No customer data is
   sent in this request.
2. When a customer or an administrator submits the invoicing form, the plugin
   calls `POST /api/public/invoice` sending:
   * the customer's tax data: legal name, RFC (Mexican tax ID), tax regime,
     postal code and, if provided, email address;
   * the CFDI use, the payment method and the payment form;
   * the order's line items: description, quantity, unit price, VAT rate, the
     SAT product and unit keys, and the SKU when the product has one;
   * the order identifier, as an external reference, so that two simultaneous
     submissions cannot produce two invoices for the same order.
   If an email address is provided, FacturaMX uses it to email the invoice
   (PDF and XML) to the customer.
3. When somebody downloads an already issued invoice, the plugin calls
   `GET /api/public/invoice/{id}/{format}` to fetch its PDF or XML. Only the
   identifier of that invoice is sent.
4. When a customer looks up an order on the invoicing page and the order has no
   invoice in the store yet, the plugin calls
   `GET /api/public/invoice?external_id={order}` to check whether the store
   already invoiced it from the FacturaMX panel. Only the order identifier is
   sent.
5. When an administrator clicks "Enviar a FacturaMX como cotización" (send to
   FacturaMX as a quotation) on an order,
   the plugin calls `POST /api/public/quotation` with the same data as in point 2
   (as far as it has been filled in). Nothing is stamped.

Nothing is sent when a visitor merely browses your store, and nothing is sent
about orders that nobody asks to invoice.

Service provided by FacturaMX:

* Terms of service: https://facturamx.top/terminos
* Privacy policy: https://facturamx.top/privacidad

== Installation ==

1. Install and activate the plugin. WooCommerce must be active first.
2. In your FacturaMX account, open **Empresas → your company → API para
   facturación externa** and generate a token. It starts with `fmx_live_` and is
   shown only once.
3. Go to **WooCommerce → FacturaMX**, paste the token and save.
4. Click **Probar conexión** (Test connection). The plugin will not issue any
   CFDI until the current URL and token have passed this test.
5. Set the defaults for your store: SAT unit key, unit name, VAT rate, payment
   form and series. The invoicing window (30 days by default) controls for how
   long after the purchase a customer may still self-invoice.
6. Give every product its own SAT product key (ClaveProdServ) in its
   **FacturaMX** tab. The default `01010101` means "not in the catalog": the
   plugin refuses to issue a CFDI with it, and the settings screen lists the
   products that still need one.
7. Create a page for your customers and add the `[facturamx_portal]` shortcode
   to it. Link that page from your footer or your order emails.

== Frequently Asked Questions ==

= Do my customers need an account on my site? =

No. The invoicing page asks for the order number and the exact amount paid.
Only somebody holding the receipt knows both.

= What happens if a customer types the wrong amount? =

The page answers exactly as it would for an order that does not exist. That is
deliberate: otherwise the form would tell a stranger which order numbers are
real.

= Can the same order be invoiced twice? =

No. Once an order has a CFDI, the plugin shows the existing one instead of
issuing another. This also covers invoices issued outside the plugin: if the
order was invoiced from the FacturaMX panel (for example, from a quotation), the
invoicing page finds that invoice and offers it for download.

= Which orders can be invoiced? =

Paid orders, in MXN, not refunded, and still inside the invoicing window you
configured. The SAT expects the CFDI to be issued within the same month as the
purchase, so windows longer than 30 days are rarely useful.

= My store does not charge VAT. Can I still issue CFDIs? =

Yes. Mexican law considers advertised prices to already include VAT, so when
WooCommerce did not calculate tax on a line, the plugin extracts the configured
rate from the price instead of adding it on top. If a product is legally exempt,
set its VAT rate to 0 in its FacturaMX tab — that is a declaration, not a
default.

= The plugin refuses to issue a CFDI. Why? =

Look at **WooCommerce → FacturaMX → Antes del primer timbre**. Either the
connection has not been tested with the current token (click **Probar
conexión** again after changing it), or some product in the order still has the
placeholder key `01010101`. When a customer is blocked for this reason they see
a polite message, and the order gets a note explaining exactly what is missing.

= Is the plugin free? =

Yes. Each issued CFDI consumes one stamp from your FacturaMX account; see
*Pricing* above.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. The plugin declares compatibility with custom order tables and uses the
WooCommerce CRUD API throughout.

= In which language is the interface? =

Spanish. The people who issue CFDIs invoice in Mexico. A POT file ships with the
plugin so it can be translated.

== Screenshots ==

1. The public invoicing page: the customer enters the order number and the
   amount paid.
2. The tax data form, with the SAT catalogs already loaded.
3. The issued CFDI, with its PDF and XML download links.
4. The FacturaMX metabox on the order screen.
5. The plugin settings, under WooCommerce.
6. The FacturaMX tab on a product, where its SAT keys are set.

== Changelog ==

= 0.1.0 =
* First release.
* Public self-service invoicing page via the `[facturamx_portal]` shortcode.
* Invoicing metabox on the order screen.
* SAT product key, unit key, unit name and VAT rate, globally and per product.
* CFDI 4.0 issuing through the FacturaMX public API, with PDF and XML downloads
  proxied through the site.
* Guided setup: no CFDI is issued until the connection is tested with the
  current token and every product has its own SAT key.
* "Enviar a FacturaMX como cotización" (send as a quotation) button on the order
  screen.
* Orders invoiced from the FacturaMX panel are detected and offered for download.
* Store-domain email addresses (e.g. created by a chat bot) are not suggested as
  the customer's email.

== Upgrade Notice ==

= 0.1.0 =
First release.
