# Integración con n8n

SYSCON avisa a [n8n](https://n8n.io) cuando pasa algo importante en la tienda. n8n recibe el aviso
en un nodo **Webhook** y desde ahí se arma la automatización sin tocar el código: mandar un mensaje
de Telegram/WhatsApp al vendedor, anotar la venta en Google Sheets, enviar un correo al área de
atención al cliente, etc.

```
SYSCON (Laravel)                                n8n
┌─────────────────────────┐  POST JSON   ┌──────────────────────┐
│ pedido / pago / factura │ ───────────▶ │ Webhook  → Switch →  │ → Telegram, Sheets,
│ / libro de reclamaciones│ X-Syscon-    │ (Header Auth)        │   Gmail, Slack…
└─────────────────────────┘ Token        └──────────────────────┘
          │  si n8n no responde
          ▼
  tabla integration_events  ──▶  reintento por el scheduler (integrations:send, cada minuto)
```

## Eventos

| Evento | Cuándo | Datos principales (`data`) |
|---|---|---|
| `order.created` | Se crea un pedido (web o venta en tienda) | `orderId`, `status`, `channel`, `total`, `customerEmail`, `contactName`, `contactPhone`, `deliveryMethod`, `items[]`, `url` |
| `order.paid` | El pedido pasa a **PAID** (Mercado Pago, pago manual o caja) | igual que `order.created` |
| `invoice.accepted` | SUNAT acepta una boleta, factura o nota | `invoiceId`, `orderId`, `type` (01/03/07/08), `serialNumber`, `customerName`, `customerDocument`, `total` |
| `complaint.created` | Alguien registra una hoja en el Libro de Reclamaciones | `complaintId`, `code`, `type`, `consumerName`, `email`, `phone`, `itemDescription`, `detail`, `dueOn` |
| `test.ping` | `php artisan integrations:test` | `message` |

Cuerpo de ejemplo:

```json
{
  "id": "6f1c…",
  "event": "order.paid",
  "occurredAt": "2026-11-19T15:04:05-05:00",
  "source": "SYSCON Commerce",
  "data": {
    "orderId": "9b2e…", "status": "PAID", "channel": "WEB", "total": "199.80", "currency": "PEN",
    "customerEmail": "cliente@correo.com", "contactName": "Ana Quispe", "contactPhone": "987654321",
    "deliveryMethod": "PICKUP", "billingType": "03",
    "items": [{ "sku": "RT-AX55", "name": "Router Wi-Fi 6", "quantity": 2, "lineTotal": "199.80" }],
    "url": "https://tienda.example.com/pedido/9b2e…"
  }
}
```

Cabeceras: `X-Syscon-Token` (secreto compartido), `X-Syscon-Event` (nombre del evento) y
`X-Syscon-Delivery` (id único del evento: sirve para descartar duplicados en n8n).

## Cómo funciona por dentro

- `app/Modules/Integrations/N8n.php`. `emit()` guarda el evento en `integration_events` **dentro de
  la misma transacción** que el pedido, el pago o la factura, así nunca se avisa de algo que
  terminó revirtiéndose. El POST se hace justo después del commit.
- Si n8n está caído o responde con un error, **la venta no se ve afectada**: el evento queda
  `FAILED` y el scheduler (`integrations:send`) lo reintenta a los 1, 4, 9, 16 y 25 minutos.
- Cada recurso genera su evento una sola vez (índice único `event` + `subject`). Si Mercado Pago
  avisa dos veces del mismo pago, n8n recibe un solo `order.paid`.
- Con `N8N_WEBHOOK_URL` vacío la integración queda apagada y no se guarda nada.
- No se envían datos de tarjetas ni tokens de pago, solo lo necesario para avisar.

## Puesta en marcha

### 1. Tener un n8n

- **n8n Cloud** (lo más simple para una demo): crear la cuenta y usar la URL que da.
- **Autoalojado** con Docker en un servidor con al menos 1 GB de RAM libre:
  `docker run -d --name n8n -p 5678:5678 -v n8n_data:/home/node/.n8n n8nio/n8n`, publicado detrás
  de HTTPS (Caddy/nginx). El token viaja en una cabecera, así que **hay que usar HTTPS**.

### 2. Crear el flujo en n8n

1. *Workflows → Import from file* → [`n8n-workflow-ejemplo.json`](n8n-workflow-ejemplo.json).
2. En el nodo **SYSCON Webhook** crear la credencial **Header Auth** con nombre `X-Syscon-Token` y
   como valor un token largo y aleatorio (por ejemplo, `openssl rand -hex 32`).
3. Reemplazar los nodos *Mensaje …* por la acción real (Telegram, Gmail, Google Sheets…). El texto
   ya viene armado en el campo `mensaje`.
4. **Activar** el flujo y copiar la **Production URL** del webhook (`https://…/webhook/syscon`).

### 3. Configurar SYSCON

En el `.env` de la plataforma:

```dotenv
N8N_WEBHOOK_URL=https://tu-n8n.example.com/webhook/syscon
N8N_WEBHOOK_TOKEN=el-mismo-token-de-la-credencial
N8N_TIMEOUT=5
```

Luego `php artisan config:cache`, recargar PHP-FPM y probar con:

```bash
php artisan integrations:test     # "n8n recibió el evento de prueba."
```

En n8n debe aparecer una ejecución con `event = test.ping`. Si falla, el comando muestra el motivo
(HTTP 403 = el token no coincide; 404 = el flujo no está activo o la URL es la de *test*).

## Revisar lo enviado

```sql
SELECT event, status, attempts, last_error, created_at FROM integration_events ORDER BY created_at DESC LIMIT 20;
```

`SENT` = n8n lo recibió. `FAILED` con `next_attempt_at` = se reintentará. `FAILED` sin
`next_attempt_at` = agotó los 6 intentos: revisar `last_error` y, una vez corregido, ponerlo en
`PENDING` con `next_attempt_at = NOW()` para que el scheduler lo vuelva a enviar.

## Límites de esta versión básica

- Se envía a **un solo** webhook. Para varios destinos, separarlos en n8n con el nodo Switch.
- El primer envío ocurre dentro de la misma petición: si n8n tarda, la respuesta al cliente
  puede demorar hasta `N8N_TIMEOUT` segundos (después se corta y queda para el reintento).
- Va de SYSCON hacia n8n. Que n8n actúe dentro de SYSCON (por ejemplo, cambiar el estado de un
  pedido) se haría llamando a la API existente con un usuario de servicio.
