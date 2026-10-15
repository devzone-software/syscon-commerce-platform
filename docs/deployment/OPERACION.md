# Operación de ventas y facturación

Complementa la [guía de Hostinger](HOSTINGER.md). Revisado el 14 de octubre de 2026.

## Configuración (.env)

| Variable | Uso | Producción |
| --- | --- | --- |
| `IGV_RATE` | Tasa con la que se separa base e IGV de precios con IGV incluido. Cada pedido guarda la suya. | `0.18` |
| `SUNAT_RUC`, `SUNAT_LEGAL_NAME`, `SUNAT_ADDRESS`, `SUNAT_ESTABLISHMENT_CODE` | Identidad del emisor en el XML y en el PDF. | RUC, razón social y domicilio fiscal reales; `0000` salvo anexos. |
| `SUNAT_SOL_USER`, `SUNAT_SOL_PASSWORD` | Usuario secundario SOL con perfil de facturación electrónica. | Reales; nunca `MODDATOS`. |
| `SUNAT_CERTIFICATE_PATH`, `SUNAT_CERTIFICATE_PASSWORD` | Certificado digital PKCS#12 para firmar. | Certificado emitido por una entidad acreditada, fuera de `public_html`. |
| `SUNAT_ENDPOINT` | Servicio de envío. Si contiene "beta", todo lo aceptado se imprime como documento de prueba. | Endpoint de producción de SUNAT (o del OSE). |
| `SUNAT_CONSULT_ENDPOINT` | Consulta del CDR (getStatusCdr) para conciliar sin reenviar. | Servicio de consulta de producción. |
| `MAIL_*`, `WEB_URL` | Correos al cliente y enlace a "Mi pedido". | SMTP del dominio. |
| `RUC_LOOKUP_DRIVER`, `RUC_LOOKUP_TOKEN` | Consulta de RUC: `migo` (proveedor real), `fake` (datos de prueba) o `none`. Con `migo` sin token queda desactivada. | `migo` con el token de api.migo.pe/tokens; nunca `fake`. |
| `PAYMENT_SANDBOX`, `PAYMENT_SANDBOX_SECRET` | Pasarela simulada. | `false`: nunca habilitarla. |
| `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET` | Mercado Pago Checkout Pro. Sin token no se ofrece. Webhook: `{APP_URL}/api/payments/webhooks/mercadopago` (evento "Pagos"). | Credenciales de producción `APP_USR-…` y la clave secreta de Webhooks; `APP_URL` con HTTPS. |
| `ORDER_RESERVATION_HOURS`, `MANUAL_PAYMENT_INSTRUCTIONS`, `DELIVERY_FEE`, `PICKUP_*` | Reserva de stock, instrucciones de pago manual y entrega. | Según la operación. |

Para el entorno de prueba, `php artisan sunat:test-certificate` genera un certificado autofirmado e imprime las variables del servicio beta (RUC 20000000001, usuario MODDATOS).

### Series

Las series se administran en Comprobantes → Series. Hay una serie activa por tipo y letra (F para facturas y sus notas, B para boletas y sus notas). Si una serie se usó fuera del sistema, regístrala con "Último número ya usado" para continuar después de ese número.

## Tareas programadas

Un solo cron cada minuto (`php artisan schedule:run`) ejecuta:

| Tarea | Frecuencia | Qué hace |
| --- | --- | --- |
| `notifications:send` | cada minuto | Envía correos pendientes y reintenta fallidos (hasta 5 intentos). |
| `orders:expire` | cada 5 min | Vence pedidos impagos y libera su stock. |
| `invoices:reconcile` | cada 10 min | Concilia comprobantes sin respuesta de SUNAT y consulta tickets de anulaciones. |
| `suppliers:sync` | cada 15 min | Sincroniza los feeds de proveedores. |

## Respaldo

Respalda juntos, y siempre antes de cada actualización:

1. **Base de datos MySQL.** Usa la copia automática de hPanel (Archivos → Copias de seguridad) o, por SSH:
   ```bash
   mysqldump --single-transaction --routines --no-tablespaces -u USUARIO -p BASE | gzip > syscon-$(date +%F).sql.gz
   ```
   `--single-transaction` toma una copia consistente sin bloquear las ventas.
2. **`syscon/.env`** y **`syscon/certificates/`**. Contienen secretos, así que guárdalos cifrados y fuera del servidor.
3. **`syscon/storage/app`**, si se suben archivos.

Los XML firmados, los CDR, el historial de envíos y las anulaciones están en la base de datos, así que el respaldo de MySQL los incluye.

## Recuperación

1. Pon el sitio en mantenimiento con `php artisan down`.
2. Restaura la base: `gunzip < syscon-AAAA-MM-DD.sql.gz | mysql -u USUARIO -p BASE`. Luego restaura `.env` y `certificates/`.
3. Ejecuta `php artisan migrate --force`. Si la copia es de una versión anterior, aplica las migraciones nuevas.
4. **Revisa la numeración antes de emitir.** Una copia anterior no conoce los comprobantes emitidos después de ella. Si SUNAT ya recibió, por ejemplo, el B001-130 y la base restaurada termina en B001-120, el siguiente número repetiría uno ya enviado.
   - Compara el último número de cada serie con lo que SUNAT tiene registrado. Puedes usar la consulta de comprobantes de SUNAT o los PDF y correos enviados.
   - Si hay diferencia, registra una serie nueva en Comprobantes → Series con "Último número ya usado" y desactiva la anterior.
5. Ejecuta `php artisan invoices:reconcile`. Luego revisa en Reportes → Ventas y conciliación los pedidos "sin comprobante aceptado".
6. Ejecuta `php artisan up` y verifica login, catálogo, un pedido de prueba y el PDF de un comprobante.

Comprueba la recuperación al menos una vez en una base de prueba: restaura una copia, aplica migraciones y abre un comprobante.

## Pasos de prueba de extremo a extremo

1. **Web.** Compra con boleta (DNI) y otra con factura ("Consultar RUC" en modo prueba). Paga con pago manual, con la pasarela de prueba o con Mercado Pago (Access Token de una cuenta vendedora de prueba, comprador de prueba y tarjetas de prueba de Mercado Pago).
2. **Backoffice:**
   - confirma el pago;
   - emite el comprobante;
   - envíalo (en beta queda aceptado);
   - descarga el PDF, el XML y el CDR.
3. **Cliente.** Ve sus comprobantes en "Mi pedido".
4. **Venta directa.** Abre la caja, cobra, emite el comprobante y cierra la caja con arqueo.
5. **Correcciones:**
   - nota de crédito por ítem;
   - anulación por comunicación de baja o resumen diario dentro de los 7 días;
   - reemisión del comprobante del pedido.
6. **Errores esperados.** Documento inválido, factura sin RUC, falta de stock, reintento con la misma clave, pedido ajeno y confirmación repetida no deben crear datos duplicados.
