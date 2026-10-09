# SYSCON Commerce Platform

API de comercio en **PHP 8.4 / Laravel 13**, organizada en una aplicación modular con MySQL/MariaDB para hosting compartido de Hostinger. Sustituye los seis servicios Java; no requiere Maven, JVM, Node ni un frontend para funcionar.

Los módulos en `app/Modules` son Auth, Catalog, Orders, Payments, Suppliers e Invoices. Cada uno conserva las rutas públicas de la API anterior. Los módulos comparten una base de datos y coordinan pedidos, inventario y pagos mediante transacciones.

## Desarrollo local

Requisitos: PHP 8.4 con BCMath, DOM, cURL, Mbstring, OpenSSL, PDO MySQL/SQLite y ZIP; Composer 2.

```bash
composer install
php artisan commerce:configure --sqlite
php artisan migrate --seed
php artisan serve
```

La API queda en http://localhost:8000, Swagger en http://localhost:8000/swagger-ui.html y el documento OpenAPI en `/v3/api-docs`. Swagger carga sus recursos desde jsDelivr. `/up` es el endpoint de salud del proceso.

`commerce:configure` crea `.env` y genera APP_KEY, JWT_SECRET, SERVICE_TOKEN y ADMIN_PASSWORD **solo si están vacíos**. Las credenciales administrativas están en el archivo local `.env`, excluido de Git. El comando `--sqlite` configura una base SQLite para desarrollo; MySQL/MariaDB con InnoDB es la base prevista para despliegue y concurrencia.

Para trabajar con MySQL/MariaDB local, omite `--sqlite` y configura DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD antes de ejecutar las migraciones. El comando no inventa una contraseña de base de datos: usa la que corresponde a tu servidor o a hPanel.

## Despliegue en Hostinger compartido

El proyecto funciona directamente con PHP 8.4, MySQL/MariaDB, el servidor web del hosting y un cron de hPanel. Las colas usan `sync` y la sincronización programada se ejecuta dentro del proceso PHP.

Genera un paquete de producción con dependencias sin herramientas de desarrollo:

```bash
bash bin/build-hostinger.sh
```

En NixOS:

```bash
nix shell nixpkgs#php84 nixpkgs#php84Packages.composer -c bash bin/build-hostinger.sh
```

El resultado es `dist/syscon-hostinger.zip`, con `syscon/` (aplicación privada) y `public_html/` (archivos públicos). Excluye el .env local, bases SQLite, certificados y archivos subidos localmente. El paquete incluye `.env.example` con la configuración de producción; configura sus valores en el servidor.

Sigue la [guía de Hostinger](docs/deployment/HOSTINGER.md) para subirlo, configurar PHP/base de datos, ejecutar migraciones y crear el cron. CI también genera este paquete como artefacto descargable.

## API y seguridad

Respuestas: `{"success":true,"message":"...","data":...}`. Los errores mantienen el mismo envoltorio; la validación usa HTTP 422. UUID para entidades. Importes en PEN como **cadenas decimales de dos posiciones**, para conservar precisión. Este formato monetario es un cambio frente a los números JSON de Java.

- Auth: `POST /api/auth/register|login|refresh|logout`, `GET /api/auth/me`. JWT HS256 por 15 minutos, refresh por 30 días almacenado como SHA-256 y rotado bajo bloqueo. Logout revoca el refresh; el JWT existente expira a los 15 minutos. Registro público siempre CUSTOMER.
- Catalog: `GET /api/catalog/products[/{id}]`, `categories`, `brands`; ADMIN crea/actualiza productos por SKU con `POST products`.
- Orders: `GET|POST /api/orders`, `GET /api/orders/{id}`; ADMIN cambia estados con `PATCH /api/orders/{id}/status/{status}`.
- Payments: `POST /api/payments`, `GET /api/payments/{id}`; ADMIN confirma con `POST /api/payments/{id}/confirm` y `reference`.
- Suppliers: ADMIN usa `GET|POST /api/suppliers`, `POST /{id}/run`, `GET /jobs`, `GET /jobs/{id}/history`.
- Invoices: ADMIN usa `POST /api/invoices`, `GET /{id}`, `POST /{id}/submit`.

Los clientes acceden únicamente a sus pedidos y pagos. ADMIN tiene los permisos del sistema original. Las operaciones internas de catálogo y pedidos conservan `X-Service-Token`; en esta arquitectura los módulos llaman directamente a sus servicios PHP, sin HTTP entre procesos. Mantén JWT_SECRET y SERVICE_TOKEN distintos y con al menos 32 caracteres.

Ejemplo de flujo:

1. Registrar cliente y hacer login.
2. Con ADMIN, publicar un producto con sku, name, price, stock y active.
3. Con cliente, crear pedido: `{"items":[{"productId":"UUID","quantity":2}]}`.
4. Crear pago: `{"orderId":"UUID","provider":"MANUAL"}`.
5. ADMIN confirma el pago: `{"reference":"BANCO-001"}`.
6. Crear borrador de comprobante y, con credenciales de pruebas, enviarlo a SUNAT.

## Inventario y pagos

Crear un pedido reserva unidades mediante bloqueos de fila en orden estable. El stock público muestra unidades disponibles, descontando reservas. Confirmar pago consume la reserva y descuenta stock físico en la misma transacción; cancelar un pedido pendiente libera su reserva. Una importación o edición no puede reducir stock físico por debajo de las reservas existentes.

Estados: PAYMENT_PENDING → PAID → PROCESSING → SHIPPED → DELIVERED. CANCELLED solo desde PAYMENT_PENDING. PAID se asigna al confirmar pago, no mediante el cambio administrativo de estado. La creación de pago y su confirmación son idempotentes.

El adaptador operativo es MANUAL. Mercado Pago y Culqi continúan pendientes de integración con credenciales, webhooks verificables e idempotencia. Las reservas pendientes no tienen vencimiento automático; el administrador debe cancelarlas cuando corresponda.

## Proveedores

Configura SUPPLIER_ALLOWED_HOSTS con los hosts exactos de los feeds contractuales. Solo HTTPS, sin credenciales en URL ni redirecciones, con timeout, reintentos y límite de 5 MB. El intervalo mínimo es 15 minutos; el scheduler ejecuta `suppliers:sync` cada 15 minutos. En Hostinger configura un cron para `php artisan schedule:run`; el callback del scheduler llama a Artisan dentro del mismo proceso, sin depender de `proc_open`.

Formato UTF-8, separado por punto y coma:

```text
sku;name;price;stock;imageUrl;brand;category
SKU-1;"Producto; especial";10.15;5;https://proveedor.example/image.jpg;Marca;Categoría
```

Admite campos CSV entrecomillados. El feed completo se valida y aplica transaccionalmente: una fila inválida revierte toda la importación. Cada ejecución conserva resultado e historial. El stock del feed se interpreta como stock físico total, antes de descontar reservas; revisa que el contrato del proveedor tenga esa semántica.

La evaluación de [SEGO](docs/suppliers/SEGO.md) se conserva. No se configura scraping de su web pública.

## SUNAT

Se conservan borradores UBL 2.1, firma XMLDSig RSA-SHA256 con PKCS#12, envío SOAP sendBill y lectura/persistencia de ZIP CDR. Configura SUNAT_RUC, SUNAT_LEGAL_NAME, SUNAT_SOL_USER, SUNAT_SOL_PASSWORD, SUNAT_CERTIFICATE_PATH y SUNAT_CERTIFICATE_PASSWORD. En Hostinger, guarda el certificado en `syscon/certificates/`, fuera de `public_html`, y configura SUNAT_CERTIFICATE_PATH con su ruta absoluta.

Tipos: 01 factura, 03 boleta, 07 nota de crédito, 08 nota de débito. Las notas requieren un comprobante original ACCEPTED del mismo pedido. Estados: DRAFT, ACCEPTED, REJECTED, FAILED.

**La implementación conserva el carácter experimental del backend anterior: no está homologada.** Faltan validaciones tributarias completas, catálogos, cálculo/desglose de impuestos, referencias y motivos completos de notas y pruebas oficiales. La prueba criptográfica valida la firma, no la aceptación tributaria. Los envíos con timeout deben conciliarse con SUNAT antes de reintentar; una respuesta perdida no significa que SUNAT no recibió el documento.

## Migración desde Java

Se retiraron Java, Maven, Feign, las seis bases lógicas y el proxy entre servicios. El commit anterior conserva ese código en Git.

Esta migración prepara un **esquema nuevo**. No transforma automáticamente datos de una instalación Java existente. Antes de sustituir una instalación con datos, exporta las seis bases y prepara una importación que preserve UUID, contraseñas BCrypt, estados y referencias; luego concilia inventario antes de habilitar reservas. Los refresh tokens anteriores requieren iniciar sesión nuevamente. No borres los volúmenes anteriores.

## Validación

```bash
composer validate --strict
vendor/bin/pint --test
php artisan test
```

La suite cubre autenticación/rotación, autorización y propiedad, reservas/cancelaciones, pagos repetidos, rollback de importación CSV, borradores/envíos SUNAT y verificación criptográfica de firma. CI ejecuta las pruebas con SQLite y MariaDB; en MariaDB también verifica que dos procesos simultáneos no reserven la misma última unidad.

Referencias: [Laravel 13](https://laravel.com/docs/13.x), [Swagger UI](https://github.com/swagger-api/swagger-ui), [guías oficiales SUNAT](https://cpe.sunat.gob.pe/guias-y-manuales).
