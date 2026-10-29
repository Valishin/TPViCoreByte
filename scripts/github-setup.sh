#!/usr/bin/env bash
# Repositorio privado en GitHub y nombre real de la imagen en GHCR.
#
#   scripts/github-setup.sh <usuario-de-github> [nombre-del-repo]
#
# 1. Cambia ghcr.io/CAMBIAR/… por ghcr.io/<usuario>/… (en minúsculas, como exige
#    GHCR) en el compose, la plantilla del .env, el instalador y build-image.sh,
#    y hace commit.
# 2. Con GitHub CLI (gh auth login hecho): crea el repositorio PRIVADO y sube
#    todas las ramas y etiquetas con su historial. Sin gh, dice qué hacer.
set -euo pipefail
cd "$(dirname "$0")/.."

USER_NAME="${1:-}"; REPO="${2:-tpv-hosteleria}"
[[ "$USER_NAME" =~ ^[A-Za-z0-9-]+$ ]] || { echo "Uso: scripts/github-setup.sh <usuario-de-github> [repo]" >&2; exit 2; }
OWNER="$(echo "$USER_NAME" | tr '[:upper:]' '[:lower:]')"

files=(docker-compose.prod.yml docker/production/env.example scripts/install/install.sh
       scripts/install/tpv-install.conf.example scripts/build-image.sh)
sed -i.bak -e "s|ghcr.io/CAMBIAR/tpv-hosteleria|ghcr.io/${OWNER}/${REPO}|g" \
    -e "s|https://github.com/CAMBIAR/tpv-hosteleria|https://github.com/${USER_NAME}/${REPO}|g" "${files[@]}"
for f in "${files[@]}"; do rm -f "$f.bak"; done
if ! git diff --quiet -- "${files[@]}"; then
    git add "${files[@]}"
    git commit -q -m "Imagen en ghcr.io/${OWNER}/${REPO}"
    echo "✓ TPV_IMAGE=ghcr.io/${OWNER}/${REPO} (commit hecho)"
fi

if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    if gh repo view "${USER_NAME}/${REPO}" >/dev/null 2>&1; then
        git remote get-url origin >/dev/null 2>&1 || git remote add origin "https://github.com/${USER_NAME}/${REPO}.git"
    else
        gh repo create "${USER_NAME}/${REPO}" --private --source . --remote origin \
            --description "TPV de hostelería local-first (Laravel, Filament, Vue)"
    fi
    git push -u origin --all
    git push origin --tags
    echo "✓ https://github.com/${USER_NAME}/${REPO} (privado)"
else
    cat <<MSG
Sin GitHub CLI: crea el repositorio vacío y PRIVADO en https://github.com/new
(nombre ${REPO}, sin README ni .gitignore) y después:

  git remote add origin https://github.com/${USER_NAME}/${REPO}.git
  git push -u origin --all && git push origin --tags
MSG
fi
