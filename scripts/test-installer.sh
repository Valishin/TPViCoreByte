#!/usr/bin/env bash
# Pruebas del instalador (--dry-run) en un contenedor debian:13 limpio, con y sin
# escritorio simulado, y de los scripts del equipo (impresoras USB, Tailscale).
#   scripts/test-installer.sh
set -euo pipefail
cd "$(dirname "$0")/.."
docker run --rm -v "$PWD":/src:ro debian:13 bash /src/tests/installer/run.sh
