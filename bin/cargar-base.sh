#!/bin/sh
# Uso: bin/cargar-base.sh <export.json> <url-api-origen> <url-api-destino>
# Reemplaza la base del .env de este proyecto por una exportación de bin/db-export.php:
# recrea el esquema e importa los datos. Respaldar antes con mysqldump.
set -e
[ $# -eq 3 ] || { echo "Uso: $0 <export.json> <url-api-origen> <url-api-destino>"; exit 1; }
EXPORT=$(realpath "$1")
cd "$(dirname "$0")/.."
env_value() { grep "^$1=" .env | cut -d= -f2- | tr -d '"'; }
DSN="mysql:host=$(env_value DB_HOST);port=$(env_value DB_PORT);dbname=$(env_value DB_DATABASE);charset=utf8mb4"
php artisan migrate:fresh --force
php bin/db-import.php "$EXPORT" "$DSN" "$(env_value DB_USERNAME)" "$(env_value DB_PASSWORD)" "$2" "$3"
php artisan config:cache
echo "Listo. Recarga PHP-FPM para aplicar la configuración."
