# Despliegue en Hostinger compartido

Este despliegue usa PHP 8.4, MySQL/MariaDB con InnoDB y un cron. No requiere acceso root ni procesos permanentes. El paquete se prepara localmente; todavía hay que subirlo y configurar la cuenta real.

## 1. Preparar hPanel

En **Configuración PHP**, selecciona PHP 8.4 para el dominio. Comprueba BCMath, cURL, DOM/XML, Fileinfo, Mbstring, OpenSSL, PDO MySQL y ZIP. Laravel y sus dependencias requieren estas extensiones tanto en la web como en CLI.

En **Bases de datos MySQL**, crea una base y un usuario con acceso a ella. Conserva el host, nombre completo de base, usuario y contraseña que muestra hPanel; los nombres pueden incluir un prefijo de cuenta.

En **SSL**, activa el certificado del dominio y la redirección HTTPS. La APP_URL de producción debe usar https.

Hostinger permite gestionar [PHP](https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/) y [extensiones](https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options-in-hostinger/) desde hPanel. Su [documentación de bases de datos](https://www.hostinger.com/support/which-databases-and-data-tools-are-supported-at-hostinger/) detalla qué motores ofrece cada modalidad.

## 2. Construir y subir el paquete

En la máquina de desarrollo:

```bash
bash bin/build-hostinger.sh
```

El script instala las versiones bloqueadas de Composer en un directorio temporal, sin dependencias de desarrollo. No modifica el vendor local y no copia el .env, certificados, bases SQLite ni datos locales. No genera cachés de configuración con rutas de la máquina de desarrollo.

Sube `dist/syscon-hostinger.zip` mediante el administrador de archivos o SFTP. Extrae sus dos directorios dentro del directorio del dominio, como **hermanos**:

```text
/home/u123456789/domains/tu-dominio.com/
├── syscon/
│   ├── app/
│   ├── artisan
│   ├── bootstrap/
│   ├── vendor/
│   ├── storage/
│   └── .env.example
└── public_html/
    ├── .htaccess
    ├── index.php
    └── swagger-ui.html
```

El index.php preparado para Hostinger carga `../syscon/bootstrap/app.php`. No necesita cambiar la raíz pública del dominio. Solo el contenido de public_html se sirve por HTTP; la aplicación y su .env quedan fuera de esa carpeta.

En una actualización, conserva el .env de producción, storage y certificates de syscon. El ZIP incluye directorios storage vacíos; no sustituyas ni borres datos existentes al extraer. Revisa cualquier archivo de otra aplicación antes de reemplazar public_html.

## 3. Configurar .env

Copia syscon/.env.example a syscon/.env y configura:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u123456789_syscon
DB_USERNAME=u123456789_syscon
DB_PASSWORD="contraseña-de-la-base-creada-en-hPanel"
CACHE_STORE=database
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
ADMIN_EMAIL=admin@tu-dominio.com
```

El host y los nombres del ejemplo deben sustituirse por los de tu cuenta. Pon entre comillas contraseñas con espacios o caracteres especiales de dotenv.

## 4. Inicializar por SSH

Usa **Acceso SSH** de hPanel. Comprueba la versión de PHP CLI con `php -v`; seleccionar PHP en el dominio no basta para asumir que el comando CLI usa la misma versión. Si es necesario, sustituye `php` en todos los comandos por la ruta del ejecutable PHP 8.4 de tu cuenta.

```bash
cd /home/u123456789/domains/tu-dominio.com/syscon
php artisan commerce:configure
php artisan migrate --seed --force
php artisan config:cache
php artisan route:cache
```

El primer comando genera solo los secretos vacíos y la contraseña inicial de ADMIN; no modifica DB_PASSWORD. Lee la contraseña administrativa en el .env privado. Las migraciones y el seeder se ejecutan por CLI, nunca desde una ruta HTTP pública.

El ZIP ya incluye vendor y no necesita Composer en el hosting. Si despliegas desde Git en lugar del paquete, Hostinger [ofrece Composer 2 en los planes compatibles](https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger/). En cuentas que restringen la ejecución de scripts de Composer, usa:

```bash
composer2 install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
php artisan package:discover
```

Si tu plan no dispone de SSH, usa las herramientas CLI permitidas por ese plan para inicializar; un cron temporal de hPanel puede ejecutar los comandos anteriores uno a uno. Espera y verifica cada resultado antes del siguiente, luego elimina esos crons temporales. No crees un instalador público para ejecutar migraciones. No inicialices la base mediante un import SQL generado para otro motor.

storage y bootstrap/cache deben ser escribibles por el PHP de la cuenta. El .env debe mantener permisos privados. No uses permisos 777.

## 5. Crear cron de proveedores

En **Cron Jobs**, elige Custom y programa cada minuto:

```text
/ruta/al/php-8.4 /home/u123456789/domains/tu-dominio.com/syscon/artisan schedule:run
```

Sustituye la ruta de PHP y la del proyecto por las reales. Si tu plan solo permite intervalos mayores, usa un intervalo que incluya los minutos 0, 15, 30 y 45, por ejemplo cada cinco minutos.

El scheduler ejecuta suppliers:sync cada quince minutos, con exclusión de solapamiento mediante la caché de base de datos. El callback se ejecuta en el mismo proceso PHP, sin lanzar subprocessos. No hay worker de cola: QUEUE_CONNECTION=sync.

Las frecuencias dependen de las restricciones y recursos de tu cuenta. Revisa la salida del cron desde hPanel; las instrucciones oficiales están en [Cron Jobs](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/).

## 6. Verificar el despliegue

- /up responde 200 y / muestra el nombre de la aplicación.
- /api/catalog/products devuelve el envoltorio JSON.
- /swagger-ui.html y /v3/api-docs muestran la documentación.
- Login de ADMIN funciona; un cliente no puede editar catálogo ni confirmar pagos.
- Un pedido reserva stock y su confirmación de pago lo descuenta una sola vez.
- syscon/.env y vendor no tienen una ubicación dentro de public_html.

Una base nueva inicia sin productos. El /up comprueba el proceso PHP, no la disponibilidad de MySQL; prueba también catálogo o login.

## Actualizaciones y certificados SUNAT

Antes de una actualización, respalda MySQL y storage. Sustituye el código y vendor conservando .env, storage y certificados. Desde syscon:

```bash
php artisan config:clear
php artisan route:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

Guarda el PKCS#12 en una carpeta privada, como `syscon/certificates/`, y configura SUNAT_CERTIFICATE_PATH con su ruta absoluta. El paquete no contiene certificados ni credenciales. SUNAT mantiene su estado experimental sin homologación; la infraestructura de hosting no cambia ese límite.
