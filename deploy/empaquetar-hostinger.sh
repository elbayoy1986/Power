#!/usr/bin/env bash
# Arma el paquete listo para subir a Hostinger en dist/:
#   dist/subir-a-public_html.zip  -> se sube y se extrae dentro de public_html
#   dist/instalar.sql             -> se importa en phpMyAdmin (estructura + datos de demostración)
#   dist/power-pos-hostinger/     -> la misma carpeta sin comprimir, más la guía
# Uso: bash deploy/empaquetar-hostinger.sh
set -euo pipefail

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plantillas="$raiz/deploy/hostinger"
dist="$raiz/dist"
paquete="$dist/power-pos-hostinger"
web="$paquete/subir-a-public_html"
bd="$paquete/base_de_datos"
version="$(git -C "$raiz" rev-parse --short HEAD 2>/dev/null || echo 'sin-git')"

rm -rf "$dist"
mkdir -p "$web" "$bd"

# Código de la aplicación (sin pruebas, documentación ni configuración local)
cp -R "$raiz/public" "$raiz/src" "$raiz/views" "$web/"
mkdir -p "$web/config"
cp "$raiz/config/config.example.php" "$web/config/"
cp "$plantillas/config.php" "$web/config/config.php"
cp "$plantillas/.htaccess" "$web/.htaccess"
for carpeta in src views config; do
    cp "$plantillas/denegar.htaccess" "$web/$carpeta/.htaccess"
done

# SQL para phpMyAdmin: Hostinger no permite CREATE DATABASE ni USE, la base se elige en phpMyAdmin
sin_base() { grep -vE '^(CREATE DATABASE|USE) ' "$1"; }
{
    echo "-- Power POS $version · instalación nueva para Hostinger (phpMyAdmin > su base > Importar)"
    echo "-- Incluye la estructura y los datos de demostración. Usuario: admin@power.hn / Power2026!"
    echo
    sin_base "$raiz/database/schema.sql"
    echo
    sin_base "$raiz/database/seed.sql"
} > "$bd/instalar.sql"
for m in "$raiz"/database/migraciones/*.sql; do
    sin_base "$m" > "$bd/actualizar_$(basename "$m")"
done

cp "$raiz/docs/HOSTINGER.md" "$paquete/LEEME-HOSTINGER.md"
echo "Power POS · versión $version · generado $(date '+%Y-%m-%d %H:%M')" > "$paquete/VERSION.txt"

# Validación rápida de sintaxis PHP si hay php disponible
if command -v php >/dev/null; then
    find "$web" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
fi

(cd "$web" && zip -qr -X "$dist/subir-a-public_html.zip" .)
cp "$bd/instalar.sql" "$dist/instalar.sql"
(cd "$dist" && zip -qr -X "power-pos-hostinger.zip" "power-pos-hostinger")

echo "Listo ($version):"
ls -lh "$dist"
