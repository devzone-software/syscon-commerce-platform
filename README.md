# SYSCON Commerce Platform

Backend Java 21 / Spring Boot 3.5 con seis servicios independientes y PostgreSQL. El repositorio oficial estaba vacío al iniciar este trabajo.

## Propuesta técnica y estado inicial

- Repositorio: `main` sin commits, sin Maven, documentación ni código previo.
- Monorepo Maven para compartir contratos HTTP y seguridad transversal sin compartir entidades de negocio.
- Un proceso y una base lógica PostgreSQL por servicio. REST y OpenFeign para la primera etapa; los eventos RabbitMQ quedan como evolución futura.
- Capas `domain`, `application`, `infrastructure` y `adapters` en cada servicio.
- JWT HS256 de 15 minutos; refresh token aleatorio de 30 días, almacenado como SHA-256 y rotado en cada uso. Los servicios internos usan un token separado en red privada.
- Integraciones de pago implementadas con proveedor `MANUAL` y confirmación de administrador. Los adaptadores Mercado Pago y Culqi requieren cuentas, credenciales y webhooks para completarse.
- Proveedores: importación de feed CSV oficial por HTTPS con lista de hosts permitidos, límite de frecuencia, reintentos e historial. No se rastrean páginas HTML arbitrarias.
- SUNAT: generación de XML UBL 2.1, firma XMLDSig con PKCS#12 externo, envío SOAP `sendBill`, almacenamiento de CDR. **No está homologado**: faltan validaciones tributarias completas, catálogos SUNAT, referencias de notas y pruebas con certificados/casos oficiales. No usar para emisión real hasta completar homologación.

## Servicios

| Servicio | Puerto local | Funciones |
| --- | ---: | --- |
| Auth | 8081 | Registro, login, refresh, logout, usuarios/roles/permisos |
| Catalog | 8082 | Productos, categorías, marcas, inventario, importación interna |
| Supplier | 8083 | Proveedores, feed CSV, trabajos e historial |
| Orders | 8084 | Pedidos, detalles y transiciones |
| Payment | 8085 | Pago manual y confirmación administrativa |
| Invoice | 8086 | Borradores, XML, firma, envío y CDR SUNAT |

Cada servicio publica `/v3/api-docs` y `/swagger-ui.html`. Las respuestas siguen `{ "success": true, "message": "...", "data": ... }`.

## Ejecución local

1. Copiar `.env.example` a `.env` y reemplazar todas las contraseñas y tokens. `JWT_SECRET` debe tener al menos 32 bytes aleatorios; `SERVICE_TOKEN` debe ser distinto.
2. Ejecutar `docker compose --env-file .env up --build -d`.
3. Usar los endpoints `/api/...` a través de Nginx en `http://localhost`.

El usuario administrador se crea al arrancar Auth con `ADMIN_EMAIL` y `ADMIN_PASSWORD` (mínimo 16 caracteres). Registros públicos reciben rol `CUSTOMER`. No se exponen puertos PostgreSQL ni Redis.

Para desarrollo sin contenedores: crear las seis bases con `infra/postgres/init.sql`, exportar `DB_URL`, `DB_USER`, `DB_PASSWORD`, `JWT_SECRET` y `SERVICE_TOKEN`, y ejecutar cada módulo con Maven. `mvn verify` compila y ejecuta pruebas.

## Flujo básico

1. `POST /api/auth/register`, luego `POST /api/auth/login`.
2. Admin publica catálogo con `POST /api/catalog/products`.
3. Cliente crea `POST /api/orders` con `items: [{"productId":"UUID","quantity":1}]`.
4. Cliente crea `POST /api/payments` con `orderId` y `provider: "MANUAL"`.
5. Admin confirma `POST /api/payments/{id}/confirm` con `reference`; el pedido pasa a `PAID`.
6. Admin crea `POST /api/invoices` y envía `POST /api/invoices/{id}/submit` solo tras configurar credenciales SUNAT.

## Proveedores

Configurar `SUPPLIER_ALLOWED_HOSTS=proveedor.example,otro.example`. El feed debe ser UTF-8, separado por punto y coma y tener cabecera exacta `sku;name;price;stock;imageUrl;brand;category`. Alta: `POST /api/suppliers`; ejecución manual: `POST /api/suppliers/{id}/run`. El importador actual no admite campos CSV entrecomillados con punto y coma. Debe adaptarse al formato contractual de cada proveedor antes de usarlo en producción.

## SUNAT

Colocar el archivo PKCS#12 fuera del código y definir `SUNAT_CERTIFICATE_PATH=/run/secrets/sunat/certificado.p12`, `SUNAT_CERTIFICATE_PASSWORD`, `SUNAT_RUC`, `SUNAT_SOL_USER`, `SUNAT_SOL_PASSWORD` y `SUNAT_ENDPOINT`. El `SUNAT_ENDPOINT` de ejemplo corresponde a beta. La tabla `invoice` guarda estado y error; `xml_document` guarda XML original y firmado; `cdr_response` guarda el ZIP CDR codificado en Base64 y respuesta. Los estados son `DRAFT`, `ACCEPTED`, `REJECTED`, `FAILED`.

## Operación y límites actuales

- Hibernate `ddl-auto: update` facilita el primer arranque, pero antes de producción debe sustituirse por migraciones Flyway versionadas.
- El stock se verifica al crear pedido, pero aún no existe reserva atómica ni liberación por cancelación. No aceptar ventas concurrentes reales hasta implementar esta coordinación.
- La confirmación de pago manual exige rol `ADMIN`; los webhooks de pasarelas necesitan verificación criptográfica e idempotencia propias.
- El importador de proveedores se ejecuta manualmente o por sondeo cada 15 minutos; falta observabilidad avanzada y ejecución distribuida segura para varias réplicas.
- El reverse proxy incluido escucha HTTP para desarrollo. En VPS se debe terminar TLS con un certificado válido y bloquear acceso directo a servicios. No exponer este Compose directamente a Internet sin configurar HTTPS, copias de seguridad y política de secretos.
- Para HTTPS en VPS, colocar `fullchain.pem` y `privkey.pem` en `infra/tls/` (directorio ignorado por Git) y ejecutar `docker compose -f compose.yml -f compose.prod.yml --env-file .env up --build -d`. El proxy redirige HTTP a HTTPS. Configurar renovación externa de certificados y reiniciar Nginx después de renovarlos.
- RabbitMQ, outbox, cobertura de integración para todos los servicios y auditoría de negocio persistida son trabajo pendiente antes de la operación empresarial. Actualmente hay pruebas unitarias y una prueba de catálogo con PostgreSQL/Testcontainers; cada petición API registra actor, operación y estado en logs.

En un VPS Ubuntu/Hostinger: instalar Docker Engine y Compose, apuntar el dominio al VPS, abrir solo los puertos 80/443 en el firewall y mantener PostgreSQL/Redis sin puertos públicos. Guardar `.env` con permisos restringidos y respaldar periódicamente el volumen `pgdata`. El JWT HS256 y el token interno son secretos compartidos entre procesos; para varias organizaciones/equipos conviene pasar a firma asimétrica, rotación de claves y credenciales internas por servicio.

## Fuentes de versiones

Spring Boot 3.5.16 fue publicado por [Spring](https://spring.io/blog/2026/06/25/spring-boot-3-5-16-available-now/). La [matriz de Spring Cloud](https://spring.io/projects/spring-cloud/) indica compatibilidad de la serie 2025.0 con Boot 3.5. Para OpenAPI se usa la línea [springdoc 2.8](https://springdoc.org/v2/). Para completar y homologar comprobantes, contrastar cada XML con las [guías oficiales SUNAT UBL 2.1](https://cpe.sunat.gob.pe/guias-y-manuales) y probarlo en el [servicio beta SUNAT](https://cpe.sunat.gob.pe/noticias/servicio-beta-para-realizar-pruebas-ubl-21).
