# Despliegue en un servidor propio (AWS EC2)

Plataforma y tienda en la misma instancia, sin Docker, desde checkouts de git. Para un hosting compartido sigue la [guía de Hostinger](HOSTINGER.md); las variables de operación están en [OPERACION.md](OPERACION.md). Los datos concretos de cada instalación (IP, dominios, accesos) no se guardan en el repositorio.

## Servidor

- Ubuntu LTS con PHP 8.4+ (CLI y FPM, extensiones de la guía de Hostinger), MySQL 8, Caddy 2, Node 22 y Composer 2.
- Una IP fija (Elastic IP en AWS) para que los dominios y la URL del webhook no cambien al reiniciar.
- Security Group: SSH (22) solo desde las IP de administración; 80 y 443 abiertos.
- Dos dominios o subdominios con HTTPS: la tienda y la API. Caddy obtiene los certificados solo.

```caddy
tienda.example.com {
    root * /var/www/web/public
    encode gzip
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}

api.example.com {
    root * /var/www/platform/public
    encode gzip
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

PHP-FPM corre como `www-data`; los proyectos pertenecen al usuario de despliegue con grupo `www-data`. El scheduler va en un archivo de `/etc/cron.d/`:

```cron
* * * * * www-data cd /var/www/platform && php artisan schedule:run >> /dev/null 2>&1
```

Ejecuta `orders:expire` (5 min), `notifications:send` (1 min), `invoices:reconcile` (10 min) y `suppliers:sync` (15 min).

En el `.env` de la plataforma: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`/`WEB_URL` con HTTPS, MySQL y **`PAYMENT_SANDBOX=false`** (en un sitio público la pasarela simulada permitiría marcar pedidos como pagados). En la tienda, `APP_URL` y `PLATFORM_API_URL`. Tras cambiar un `.env`: `php artisan config:cache` y recargar PHP-FPM. Si cambian los dominios, actualizar también la URL del webhook en Mercado Pago y las imágenes (`php artisan catalog:demo-images --force`).

## Actualizar el código

```bash
cd /var/www/platform
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache

cd /var/www/web
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund && npm run build
php artisan config:cache
```

Luego recargar PHP-FPM. Las ilustraciones de productos están en git (`public/images/products`).

## Copiar una base SQLite de desarrollo a MySQL

Se copian los datos, no el archivo, y la base de destino se **reemplaza**. La exportación contiene correos y hashes de contraseñas: guárdala fuera del repositorio y bórrala al terminar.

1. Respaldar el destino con `mysqldump --single-transaction`.
2. Exportar en desarrollo (omite `migrations`, `cache`, `refresh_tokens` y sesiones):
   ```bash
   php bin/db-export.php database/database.sqlite ../syscon-export.json
   ```
3. Copiarla al servidor (`scp`) y cargarla. El script lee la conexión del `.env`, recrea el esquema (`migrate:fresh`), importa en una transacción y cambia la URL de la API de desarrollo por la de producción en los datos (imágenes):
   ```bash
   bin/cargar-base.sh ~/syscon-export.json http://127.0.0.1:8000 https://api.example.com
   ```
4. Recargar PHP-FPM. Las cuentas pasan a ser las de desarrollo, con sus contraseñas.

## Webhook de Mercado Pago

1. En el panel de desarrolladores, con la cuenta dueña del Access Token: aplicación → **Webhooks** → URL `{APP_URL}/api/payments/webhooks/mercadopago`, evento **Pagos**. Con el token productivo de un vendedor de prueba, configurarla en modo productivo.
2. Mostrar la **clave secreta** (ícono del ojo) antes de copiarla: copiada oculta solo trae puntos `•`.
3. Guardarla sin que quede en el historial ni en pantalla, y recargar PHP-FPM:
   ```bash
   ssh -t usuario@servidor /var/www/platform/bin/clave-webhook.sh
   ```
   En CMD de Windows no encierres el comando remoto entre comillas simples. El script respalda el `.env` (`.env.bak-*`, ignorado por git) y rechaza un Access Token o la clave enmascarada.
4. **Simular notificación** debe responder 200. Una firma inválida responde 401; los avisos IPN sin firma (`topic=merchant_order`…) responden 200 sin aplicar nada. Cada aviso queda en `storage/logs/laravel.log` como `api.request` con su código.

### Pago de prueba

En una ventana de incógnito, pagar con Mercado Pago entrando en el checkout con el **comprador de prueba** (panel → Cuentas de prueba).

| Medio (MP Perú) | Resultado |
| --- | --- |
| Mastercard `5031 7557 3453 0604`, `11/30`, CVV `123`, titular `APRO` | Aprobado |
| Dinero en cuenta del comprador de prueba | Aprobado |
| Visa `4009 1753 3280 6176` | `cc_rejected_other_reason` siempre, con o sin `binary_mode`: limitación del entorno de prueba |

Con el pago aprobado, el webhook marca el pedido `PAID` aunque el cliente no vuelva a la tienda.

## Verificar

```bash
curl -s https://api.example.com/api/payments/methods    # gateways: ["MERCADOPAGO"] con token
curl -s https://api.example.com/api/catalog/products
systemctl is-active caddy mysql
```

Sin dependencias de desarrollo no hay `tinker`: revisa los datos con `mysql` o con los endpoints.
