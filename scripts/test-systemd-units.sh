#!/usr/bin/env bash
# Prueba el servicio de Tailscale con systemd de verdad: contenedor Debian 13 con systemd
# como PID 1 y el tailscale real (hace falta Docker con --privileged e internet).
#   scripts/test-systemd-units.sh
set -euo pipefail
cd "$(dirname "$0")/.."
docker build -q -t tpv-systemd-test tests/installer/systemd >/dev/null
docker rm -f tpv-systemd-test >/dev/null 2>&1 || true
docker run -d --name tpv-systemd-test --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw \
    --tmpfs /run --tmpfs /run/lock -v "$PWD":/src:ro tpv-systemd-test >/dev/null
trap 'docker rm -f tpv-systemd-test >/dev/null 2>&1' EXIT
docker exec tpv-systemd-test bash /src/tests/installer/systemd/run.sh
