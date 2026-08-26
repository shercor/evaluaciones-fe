#!/usr/bin/env bash
#
# Cambia de cliente ficticio: mueve la base de datos y el tenant de Evaluación
# 360 que usa el backend.
#
# Cada cliente es una base entera y separada —el sistema atiende a una empresa
# por instalación— y sus valores viven en `backend/.env.clientes/<nombre>.env`,
# que no va al repositorio porque lleva el token del tenant.
#
#   ./docker/cliente.sh              # dice cuál está puesto y cuáles hay
#   ./docker/cliente.sh flippy       # cambia a Flippy
#
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV="$RAIZ/backend/.env"
CLIENTES="$RAIZ/backend/.env.clientes"

actual() {
  grep -E '^DB_DATABASE=' "$ENV" | head -1 | cut -d= -f2-
}

disponibles() {
  find "$CLIENTES" -maxdepth 1 -name '*.env' -exec basename {} .env \; 2>/dev/null | sort
}

if [[ $# -eq 0 ]]; then
  echo "Base en uso: $(actual)"
  echo
  echo "Clientes definidos:"
  disponibles | sed 's/^/  · /'
  echo
  echo "Para cambiar:  ./docker/cliente.sh <nombre>"
  exit 0
fi

CLIENTE="$1"
ARCHIVO="$CLIENTES/$CLIENTE.env"

if [[ ! -f "$ARCHIVO" ]]; then
  echo "No existe $ARCHIVO." >&2
  echo "Clientes definidos: $(disponibles | tr '\n' ' ')" >&2
  exit 1
fi

# Copia de seguridad antes de tocar nada: este archivo tiene la clave de la
# aplicación y perderlo invalida todas las sesiones y los enlaces firmados.
cp "$ENV" "$ENV.bak"

while IFS= read -r linea; do
  [[ "$linea" =~ ^[[:space:]]*# ]] && continue
  [[ "$linea" =~ ^[[:space:]]*$ ]] && continue

  clave="${linea%%=*}"
  valor="${linea#*=}"

  if grep -qE "^${clave}=" "$ENV"; then
    # El separador es | y no / porque los valores traen URLs.
    sed -i "s|^${clave}=.*|${clave}=${valor}|" "$ENV"
  else
    printf '%s=%s\n' "$clave" "$valor" >> "$ENV"
  fi
done < "$ARCHIVO"

echo "Cliente: $CLIENTE"
grep -E '^(DB_DATABASE|E360_TENANT_CODENAME|E360_TENANT_TOKEN)=' "$ENV" | sed 's/^/  /'

# El worker de colas lee la configuración **una sola vez**, al arrancar: sin
# reiniciarlo seguiría mandando los correos de la empresa anterior. php-fpm no
# hace falta, que relee el .env en cada petición.
echo
echo "Reiniciando el worker de colas…"
docker compose --project-directory "$RAIZ" restart queue >/dev/null 2>&1 || true

echo
echo "Listo. Dos cosas que van a pasar:"
echo "  · Vas a tener que entrar de nuevo: las sesiones viven en la base."
echo "  · Si la base es nueva, corré las migraciones y la siembra:"
echo "      docker compose exec php php artisan migrate --seed --force"
echo "      docker compose exec php php artisan db:seed --class=EmptyCompanySeeder --force"
