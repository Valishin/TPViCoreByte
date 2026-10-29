#!/usr/bin/env bash
# Construye la imagen de producción para el mini PC (linux/amd64) desde el Mac.
#
#   scripts/build-image.sh                 # carga la imagen en el Docker local
#   scripts/build-image.sh --push          # la sube a GHCR (docker login ghcr.io antes)
#   scripts/build-image.sh --tar           # dist/tpv-<versión>.tar para copiarla por Tailscale
#
# TPV_IMAGE: repositorio de la imagen (por defecto ghcr.io/<usuario>/tpv-hosteleria).
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="$(tr -d '[:space:]' < VERSION)"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
DIRTY="$(git diff --quiet 2>/dev/null || echo '-dirty')"
IMAGE="${TPV_IMAGE:-ghcr.io/CAMBIAR/tpv-hosteleria}"
# Repositorio del código: GHCR enlaza el paquete a él (hereda su visibilidad y acceso).
SOURCE="${TPV_SOURCE:-https://github.com/CAMBIAR/tpv-hosteleria}"
MODE="${1:---load}"

echo "→ ${IMAGE}:${VERSION} (commit ${COMMIT}${DIRTY}) para linux/amd64"

args=(buildx build --platform linux/amd64 -f docker/production/Dockerfile
    --build-arg "APP_VERSION=${VERSION}" --build-arg "APP_COMMIT=${COMMIT}${DIRTY}"
    --label "org.opencontainers.image.source=${SOURCE}"
    -t "${IMAGE}:${VERSION}" -t "${IMAGE}:latest")

case "$MODE" in
    --push) docker "${args[@]}" --push . ;;
    --tar)
        mkdir -p dist
        docker "${args[@]}" --load .
        docker save "${IMAGE}:${VERSION}" | gzip > "dist/tpv-${VERSION}.tar.gz"
        echo "→ dist/tpv-${VERSION}.tar.gz (en el mini PC: tpv-update --tar tpv-${VERSION}.tar.gz)"
        ;;
    *) docker "${args[@]}" --load . ;;
esac
