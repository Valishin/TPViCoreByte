#!/usr/bin/env bash
# Paquete del instalador para el USB del mini PC:
#   dist/tpv-instalador-<versión>/  (install.sh, tpv-install.conf.example, compose.yaml,
#   env.example, tpv-update, tpv-restore, tpv-kiosk y la documentación)
#   scripts/make-install-bundle.sh --with-image   añade la imagen (dist/tpv-<versión>.tar.gz)
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$(tr -d '[:space:]' < VERSION)"
OUT="dist/tpv-instalador-${VERSION}"
rm -rf "$OUT" && mkdir -p "$OUT/docs"
cp scripts/install/install.sh scripts/install/tpv-kiosk scripts/install/tpv-install.conf.example "$OUT/"
cp docker-compose.prod.yml "$OUT/compose.yaml"
cp docker/production/env.example "$OUT/env.example"
cp scripts/host/tpv-update scripts/host/tpv-restore "$OUT/"
cp docs/instalacion-minipc.md docs/checklist-instalacion.md docs/impresoras-usb.md docs/sai-nut.md "$OUT/docs/" 2>/dev/null || true
if [ "${1:-}" = "--with-image" ]; then
    [ -f "dist/tpv-${VERSION}.tar.gz" ] || scripts/build-image.sh --tar
    cp "dist/tpv-${VERSION}.tar.gz" "$OUT/"
fi
chmod +x "$OUT"/install.sh "$OUT"/tpv-kiosk "$OUT"/tpv-update "$OUT"/tpv-restore
tar -C dist -czf "dist/tpv-instalador-${VERSION}.tar.gz" "tpv-instalador-${VERSION}"
echo "→ dist/tpv-instalador-${VERSION}.tar.gz"
