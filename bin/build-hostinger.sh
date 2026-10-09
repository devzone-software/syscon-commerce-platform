#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
destination="${1:-$project_root/dist/syscon-hostinger.zip}"
php_bin="${PHP_BIN:-php}"
composer_bin="${COMPOSER_BIN:-composer}"

command -v "$php_bin" >/dev/null
command -v "$composer_bin" >/dev/null
"$php_bin" -r 'if (PHP_VERSION_ID < 80400 || !extension_loaded("zip")) { fwrite(STDERR, "Se requiere PHP 8.4 y ZIP.\n"); exit(1); }'

stage="$(mktemp -d)"
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/export/syscon" "$stage/export/public_html" "$(dirname -- "$destination")"

# Only application files enter the package; development secrets stay on this machine.
for path in app bootstrap config database docs public routes artisan composer.json composer.lock; do
    cp -R -- "$project_root/$path" "$stage/export/syscon/"
done
cp -- "$project_root/.env.hostinger.example" "$stage/export/syscon/.env.example"
find "$stage/export/syscon/database" -maxdepth 1 -type f -name '*.sqlite*' -delete
find "$stage/export/syscon/bootstrap/cache" -maxdepth 1 -type f -name '*.php' -delete
# No public storage symlink or local uploads should enter a new deployment.
rm -rf -- "$stage/export/syscon/public/storage"
mkdir -p "$stage/export/syscon/storage/"{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs}

(
    cd -- "$stage/export/syscon"
    "$composer_bin" install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
    "$php_bin" artisan package:discover --ansi
)
cp -R -- "$stage/export/syscon/public/." "$stage/export/public_html/"
cp -- "$project_root/deploy/hostinger/index.php" "$stage/export/public_html/index.php"
"$php_bin" "$project_root/bin/zip-hostinger.php" "$stage/export" "$destination"
printf 'Paquete creado: %s\n' "$destination"
