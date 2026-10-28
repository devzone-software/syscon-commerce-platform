#!/bin/sh
# Guarda la clave secreta del webhook de Mercado Pago en el .env de la plataforma sin mostrarla.
set -e
ENV="$(cd "$(dirname "$0")/.." && pwd)/.env"
printf 'Pega la clave secreta del webhook y pulsa Enter (no se verá): '
stty -echo; read -r SECRET; stty echo; echo
[ -n "$SECRET" ] || { echo "No se ingresó ninguna clave; no se cambió nada."; exit 1; }
case "$SECRET" in
  APP_USR-*|TEST-*) echo "Eso es un Access Token, no la clave del webhook; no se cambió nada."; exit 1 ;;
esac
if ! printf '%s' "$SECRET" | grep -Eq '^[A-Za-z0-9]{32,128}$'; then
  echo "La clave solo debe tener letras y números (¿se copió oculta, con puntos •?); no se cambió nada."
  exit 1
fi
cp "$ENV" "$ENV.bak-$(date +%F-%H%M)"
grep -v '^MERCADOPAGO_WEBHOOK_SECRET=' "$ENV" > "$ENV.tmp"
printf 'MERCADOPAGO_WEBHOOK_SECRET=%s\n' "$SECRET" >> "$ENV.tmp"
cat "$ENV.tmp" > "$ENV" && rm "$ENV.tmp"
cd "$(dirname "$ENV")" && php artisan config:cache
echo "Recarga PHP-FPM para aplicar la clave."
echo "Clave guardada (${#SECRET} caracteres). Respaldo: $(ls -t $ENV.bak-* | head -1)"
