# Contratos de la API de ventas

Pedidos, pagos, comprobantes, avisos y reportes de `/api`, tal como los consume la web
(`syscon-commerce-web`) a través de su BFF `/web-api`. Revisado el 14 de octubre de 2026; las
pruebas de `tests/Feature/CommerceTest.php` cubren estos contratos.

## Convenciones

- **Respuesta:** siempre `{ "success": bool, "message": string, "data": ... }`.
- **Errores:** el mismo sobre con `success: false`. En 422 de validación `data` trae `{ campo: [mensajes] }`.
  | Código | Significado |
  | --- | --- |
  | 401 | Sin token o token vencido; la web renueva con el refresh token. |
  | 403 | Sin el permiso o el recurso es de otro cliente. |
  | 404 | No existe. |
  | 409 | Estado incompatible (pedido no pagado, comprobante ya emitido, transición inválida). |
  | 422 | Datos inválidos o reglas de negocio (documento, montos de notas). |
  | 429 | Límite de peticiones. |
  | 503 | Configuración faltante, con mensaje explícito (p. ej. credenciales SUNAT). |

  Los errores 500 no muestran detalles.
- **Importes:** cadenas decimales con 2 decimales (`"159.70"`); la tasa de IGV con 4 (`"0.1800"`). Los calcula siempre el servidor; el cliente nunca envía precios ni totales.
- **Fechas:** ISO 8601. Los reportes usan días calendario de Lima.
- **Autenticación:** `Authorization: Bearer <JWT>` (HS256, 15 min). Las autoridades del token son `ROLE_<rol>` más los permisos:
  - `ADMIN` tiene `catalog:write`, `orders:manage`, `payments:confirm`, `suppliers:manage`, `invoices:submit` y `reports:view`;
  - `CUSTOMER` no tiene permisos de personal.

  La web decide los accesos por permiso, no por rol.

## Pedidos

**Estados:** `PAYMENT_PENDING` → `PAID` → `PROCESSING` → `SHIPPED` → `DELIVERED`. Desde `PAYMENT_PENDING` también son posibles:
- `CANCELLED`, por acción del personal;
- `EXPIRED`, cuando vence la reserva (`orders:expire`).

Ambos liberan la reserva de stock. Pagar la consume.

| Método y ruta | Acceso | Notas |
| --- | --- | --- |
| `GET /orders/delivery-options` | Público | `{ pickup: { enabled, cost, address, hours }, delivery: { enabled, cost, areas } }` |
| `POST /orders` | Cliente | Cuerpo descrito abajo. |
| `GET /orders`, `GET /orders/{id}` | Dueño u `orders:manage` | |
| `PATCH /orders/{id}/status/{status}` | `orders:manage` | `PROCESSING`, `SHIPPED`, `DELIVERED` o `CANCELLED`. |
| `GET /admin/orders` | `orders:manage` | `?status=A,B&q&from&to&page&perPage` → `{ items, page, perPage, total, counts }`. |
| `GET /admin/orders/{id}` | `orders:manage` | Pedido + `customer`, `payments`, `invoices`, `notifications`, `events`. |

Cuerpo de `POST /orders`:
- `items`: lista de `{ productId, quantity }`.
- `billing`:
  - `type`: `03` boleta o `01` factura;
  - `documentType`: `1` DNI, `4` CE, `6` RUC o `7` pasaporte;
  - `documentNumber`, `name` y `address` opcional.
- `delivery`:
  - `method`: `PICKUP` o `DELIVERY`;
  - `contactName`, `contactPhone`, y `address`, `district` y `reference` cuando es envío.
- `idempotencyKey`: UUID opcional. Repetirla devuelve el mismo pedido; usarla con otro contenido da 422.

Pedido (`OrderView`):
- `id`, `customerId`, `status`, `createdAt`;
- `subtotal` (solo productos) y `total` (con envío);
- `taxes: { rate, taxableAmount, taxAmount }`;
- `billing` y `delivery`, copia del momento de compra;
- `expiresAt`, solo mientras está impago;
- `details[]: { productId, sku, name, quantity, unitPrice, lineTotal, taxableAmount, taxAmount }`.

Los precios incluyen IGV. En cada línea, la base es el importe entre (1 + tasa) redondeado a céntimos, y el IGV es la diferencia.

## Pagos

| Método y ruta | Acceso | Notas |
| --- | --- | --- |
| `POST /payments` | Dueño del pedido | `{ orderId, provider }` con `MANUAL`, `SANDBOX` o `MERCADOPAGO` (las pasarelas solo si están habilitadas en `GET /payments/methods`). Manual: si ya existe una solicitud, la devuelve. Pasarela: reutiliza el intento pendiente y devuelve `paymentUrl`; si Mercado Pago no responde, 502. |
| `GET /payments/{id}` | Dueño o `payments:confirm` | Incluye `instructions` mientras está `PENDING`. |
| `POST /payments/{id}/confirm` | `payments:confirm` | `{ reference }`. En una transacción: el pago pasa a `APPROVED` y el pedido a `PAID`. Es idempotente. |
| `GET /admin/payments` | `payments:confirm` | `?status=PENDING\|APPROVED\|CANCELLED\|REJECTED\|REFUND_REQUIRED&q&page&perPage`. |
| `POST /payments/webhooks/mercadopago` | Firma `x-signature` | Notificación de Mercado Pago; el pago se consulta en su API antes de aplicarlo. Responde 503 si Mercado Pago no responde (para que reintente). |

Estados del pago: `PENDING`, `APPROVED`, `CANCELLED` (se cancela al cancelarse o vencer el pedido), `REJECTED` (pasarela) o `REFUND_REQUIRED` (la pasarela cobró un pedido ya cancelado o vencido: hay que devolver el dinero).

## Comprobantes

Tipos: `01` factura, `03` boleta, `07` nota de crédito, `08` nota de débito. Estados: `DRAFT`, `FAILED`, `REJECTED` y `ACCEPTED`.

| Método y ruta | Acceso | Notas |
| --- | --- | --- |
| `POST /invoices` | `invoices:submit` | Ver abajo. Si el pedido ya tiene su comprobante, lo devuelve sin consumir otro número. |
| `PATCH /invoices/{id}` | `invoices:submit` | Corrige el cliente; solo en `DRAFT`. |
| `POST /invoices/{id}/submit` | `invoices:submit` | Firma y envía. 503 si faltan credenciales. |
| `GET /invoices/{id}` | `invoices:submit` | `InvoiceView` con `lines`, `taxableAmount`, `taxAmount`, `total`, `noteCode`, `noteReason`, `test` y `hasCdr`. |
| `GET /invoices/{id}/{pdf\|xml\|cdr}` | `invoices:submit` | El PDF de un borrador, rechazo o prueba lleva la advertencia «sin valor tributario». |
| `GET\|POST /invoices/series`, `PATCH /invoices/series/{id}` | `invoices:submit` | Series y correlativo; `startAfter` continúa una numeración externa. |
| `GET /orders/{id}/invoices` | Dueño u `orders:manage` | Solo comprobantes `ACCEPTED`. |
| `GET /orders/{id}/invoices/{invoice}/{pdf\|xml\|cdr}` | Dueño u `orders:manage` | Solo comprobantes `ACCEPTED` del pedido. |

Cuerpo de `POST /invoices`:
- `orderId` y `type`;
- `series` opcional;
- datos del cliente opcionales; si faltan, se toman del pedido;
- en las notas, además:
  - `referencedSerialNumber`: comprobante `ACCEPTED` del mismo pedido;
  - `noteCode` y `noteReason`;
  - `items[]: { productId, quantity }` para la devolución por ítem (crédito `07`);
  - `amount` con IGV para la disminución en el valor (crédito `09`) y para las notas de débito.

Motivos de nota soportados:
- **Crédito** (catálogo 09): `01`, `02` y `06` revierten el comprobante completo; `07` devuelve ítems; `09` disminuye el valor por un importe. Las notas de crédito nunca superan el total del comprobante.
- **Débito** (catálogo 10): `01` intereses, `02` aumento en el valor, `03` penalidades.

Una anulación aceptada (`01` o `02`) permite emitir otro comprobante para el pedido.

## Correos al cliente

Avisos: `ORDER_CREATED`, `PAYMENT_CONFIRMED` e `INVOICE_AVAILABLE` (con el PDF adjunto). Cada uno se registra una sola vez por pedido, tipo y comprobante, y se envía tras el commit.

- **Estados:** `PENDING`, `SENDING`, `SENT` y `FAILED`.
- **Reintentos:** `nextAttemptAt` indica el próximo; vale null cuando ya no hay más.
- **Reenvío:** `POST /admin/notifications/{id}/resend` (`orders:manage`). No repite la operación del pedido.

## Reportes

| Método y ruta | Acceso | Notas |
| --- | --- | --- |
| `GET /admin/reports/summary` | `reports:view` | `?from&to` en fechas de Lima; por defecto los últimos 30 días, máximo 366. Contenido descrito abajo. |
| `GET /admin/reports/sales` | `reports:view` | `?from&to&page&perPage&mismatched=1&format=csv`. Una fila por pedido vendido, con `issues` (`PAYMENT_MISMATCH`, `NOT_INVOICED`, `INVOICED_MISMATCH`). |

Contenido de `summary`:
- `sales`: totales, IGV, envíos, ticket promedio y `byDay`;
- `releasedOrders`: pedidos cancelados y vencidos;
- `pendingPayments` y `reservations`: fotografía del momento;
- `invoices`: comprobantes por tipo y estado;
- `reconciliation`: pedidos pagados frente a pagos aprobados frente a lo facturado neto.

Una venta es un pedido con pago confirmado en el período. Lo facturado neto es la suma de los comprobantes aceptados, más las notas de débito, menos las notas de crédito.

## Interno

`GET /orders/internal/{id}`, `POST /orders/internal/{id}/paid` y `POST /catalog/internal/products` requieren `X-Service-Token`. En producción hay que restringirlos por red.
