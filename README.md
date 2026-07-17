# Wompi Pagos — Nequi, Daviplata y PSE para WooCommerce

Plugin de WordPress/WooCommerce para aceptar pagos en Colombia con **Nequi (notificación push)**, **Daviplata (OTP)** y **PSE (débito bancario)** a través del API de [Wompi](https://wompi.co), sin sacar al cliente de la tienda.

Desarrollado por [Moshipp](https://moshipp.com/desarrollo-web).

## Características

- **Nequi push**: el cliente ingresa su celular en el checkout, aprueba en su app y la tienda confirma en segundos — sin redirecciones.
- **Daviplata (flujo hosted)**: el cliente ingresa su documento, confirma el código OTP en la página segura de Wompi y regresa a la tienda.
- **PSE**: el cliente elige su banco (lista en vivo del API), autoriza en el portal bancario y regresa a la tienda.
- Compatible con el **checkout clásico (shortcode)** y el **checkout por bloques** de WooCommerce.
- Compatible con **HPOS** (High-Performance Order Storage).
- **Webhooks firmados** (checksum SHA-256) como fuente de verdad, con polling de respaldo en la página de gracias.
- Panel **"Pago Wompi"** en cada orden, columna de estado en el listado de pedidos y **comisión/neto estimados** en los totales.
- **Reconciliación automática** cada 15 minutos de órdenes pendientes (cubre webhooks perdidos).
- **Actualizaciones automáticas** desde GitHub Releases.
- Email opcional al cliente con instrucciones cuando el pago queda pendiente.
- Pantalla de ajustes con verificación de credenciales en vivo contra el API y URL de webhook con copiado a un clic.
- Credenciales compartidas entre ambos métodos (se configuran una sola vez), modo sandbox/producción y log de depuración opcional.
- Checkbox de aceptación del reglamento y la autorización de tratamiento de datos de Wompi (requisito regulatorio colombiano), con tokens de aceptación frescos por transacción.

## Requisitos

- WordPress ≥ 6.6, WooCommerce ≥ 9.0, PHP ≥ 8.0.
- Tienda en pesos colombianos (**COP**) y HTTPS.
- Cuenta activa en [comercios.wompi.co](https://comercios.wompi.co) con las 4 llaves de cada ambiente (pública, privada, integridad y eventos).

## Instalación y configuración

1. Descarga el repositorio como zip (o clónalo en `wp-content/plugins/`) y activa el plugin. Requiere WooCommerce activo.
2. Ve a **WooCommerce → Ajustes → Pagos → Wompi — Nequi** (o Daviplata; las credenciales se comparten).
3. Ingresa tus llaves de sandbox y/o producción, y usa **"Verificar conexión con Wompi"** para confirmar que son válidas.
4. Copia la **URL de eventos (webhook)** que muestra la pantalla de ajustes y regístrala en el dashboard de Wompi — una vez por ambiente.
5. Activa los métodos y prueba en modo sandbox.

### Datos de prueba (sandbox)

| Método | Dato | Resultado |
|---|---|---|
| Nequi | `3991111111` | Aprueba |
| Nequi | `3992222222` | Declina |
| Daviplata OTP | `574829` | Aprueba |
| Daviplata OTP | `932015` | Declina |
| Daviplata OTP | `186743` | Sin fondos |
| Daviplata OTP | `999999` | Error |

## Limitaciones conocidas (propias del API de Wompi)

- Los reembolsos de Nequi/Daviplata no se pueden ejecutar por API; se gestionan en el dashboard de Wompi.
- Solo moneda COP.
- Wompi no reporta la comisión real por API: el valor mostrado en las órdenes es una **estimación** según la tarifa configurada.

## Atribución

La marca de [Moshipp](https://moshipp.com/desarrollo-web) es parte funcional del plugin: su integridad se verifica antes de mostrar los métodos de pago y antes de crear cada transacción. Si se elimina o modifica, los métodos de pago se desactivan.

## Licencia

GPLv2 o posterior.
