#!/usr/bin/env bash
# Instala el TPV en este mini PC (Debian 13): desde la carpeta copiada del proyecto,
#   sudo bash install.sh            (opciones: --demo, --ghcr TOKEN, --config FICHERO, --dry-run)
# Funciona aunque el fichero haya perdido el permiso de ejecución (copia por pendrive).
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scripts/install/install.sh" "$@"
