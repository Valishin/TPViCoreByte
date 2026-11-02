#!/usr/bin/env bash
# Carpeta del instalador para el pendrive: el código del proyecto tal cual está en git
# (sin vendor ni node_modules: la imagen se construye en el mini PC).
#   dist/tpv-<versión>.tar.gz  →  en el mini PC: tar xzf …; cd tpv-<versión>; sudo bash install.sh
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$(tr -d '[:space:]' < VERSION)"
mkdir -p dist
git archive --format=tar.gz --prefix="tpv-${VERSION}/" -o "dist/tpv-${VERSION}.tar.gz" HEAD
echo "→ dist/tpv-${VERSION}.tar.gz"
