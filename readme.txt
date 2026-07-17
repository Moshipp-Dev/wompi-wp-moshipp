=== Wompi Pagos — Nequi y Daviplata ===
Contributors: moshipp
Tags: wompi, nequi, daviplata, pagos, colombia, woocommerce
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Acepta pagos con Nequi (notificación push) y Daviplata en WooCommerce a través de Wompi Colombia, sin sacar al cliente de tu tienda.

== Description ==

Integración directa con el API de Wompi Colombia:

* **Nequi**: el cliente ingresa su celular en el checkout, recibe una notificación push en su app Nequi y aprueba el pago. La tienda confirma el resultado en segundos, sin redirecciones.
* **Daviplata**: el cliente ingresa su documento, recibe un código OTP por SMS y lo confirma en la página segura de Wompi (flujo hosted).
* Compatible con el **checkout clásico (shortcode)** y el **checkout por bloques**.
* Compatible con **HPOS** (High-Performance Order Storage).
* Confirmación de pagos vía **webhooks firmados** (SHA256) — la fuente de verdad, con polling como respaldo.
* Modo sandbox y producción con credenciales separadas.
* Log de depuración opcional (WooCommerce → Estado → Logs).

**Requisitos**: cuenta activa en Wompi (comercios.wompi.co), tienda en pesos colombianos (COP) y HTTPS.

**Limitaciones conocidas** (propias del API de Wompi):

* Los reembolsos de Nequi/Daviplata no se pueden hacer desde WordPress; se gestionan en el dashboard de Wompi.
* Solo moneda COP.

== Installation ==

1. Sube el plugin e instálalo. Requiere WooCommerce activo.
2. Ve a WooCommerce → Ajustes → Pagos y abre "Wompi — Nequi" o "Wompi — Daviplata" (las credenciales se comparten entre ambos).
3. Ingresa tus llaves de sandbox y/o producción de comercios.wompi.co.
4. Copia la "URL de eventos (webhook)" que muestra la pantalla de ajustes y configúrala en el dashboard de Wompi para cada ambiente.
5. Activa los métodos y prueba en modo sandbox: Nequi `3991111111` aprueba, `3992222222` declina; OTP Daviplata `574829` aprueba, `932015` declina.

== Changelog ==

= 0.1.0 =
* Versión inicial: gateways Nequi (push) y Daviplata (hosted), webhooks firmados, soporte Checkout Blocks y HPOS.
