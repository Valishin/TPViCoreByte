#!/usr/bin/env bash
# Instalador del TPV en un mini PC con Debian 12 recién instalado (sin escritorio).
#
#   sudo ./install.sh --config tpv-install.conf
#   sudo ./install.sh --dry-run --config tpv-install.conf     # solo muestra lo que haría
#
# Idempotente: se puede volver a lanzar (no regenera claves ni contraseñas que
# ya existan). Ver docs/instalacion-minipc.md.
#
# Pasos: sistema (zona horaria, NTP, actualizaciones de seguridad, ufw) →
# Docker → kiosko (usuario kiosk, cage + Chromium) → impresoras USB (udev) →
# Tailscale → TPV (/opt/tpv: .env, imagen, migraciones, admin) → resumen.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
CONFIG="${HERE}/tpv-install.conf"
DRY_RUN=0
ROOT="${ROOT_PREFIX:-}"             # para pruebas: escribir los ficheros bajo otra raíz
TPV_DIR="${ROOT}/opt/tpv"
KIOSK_USER=kiosk

while [ $# -gt 0 ]; do
    case "$1" in
        --config) CONFIG="$2"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
done

# --- utilidades -------------------------------------------------------------
say() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
# Ejecuta (o muestra en --dry-run) un comando que cambia el sistema.
run() { if [ "$DRY_RUN" = 1 ]; then printf '  [dry-run] %s\n' "$*"; else "$@"; fi; }
# Escribe un fichero (siempre bajo $ROOT; en --dry-run sin ROOT, solo lo muestra).
put() { # put <ruta> <modo> (contenido por stdin)
    local path="${ROOT}$1" mode="$2" content
    content="$(cat)"
    if [ "$DRY_RUN" = 1 ] && [ -z "$ROOT" ]; then printf '  [dry-run] escribiría %s\n' "$1"; return; fi
    mkdir -p "$(dirname "$path")"
    printf '%s\n' "$content" > "$path.tmp" && chmod "$mode" "$path.tmp" && mv "$path.tmp" "$path"
}
ask() { # ask VAR "pregunta" [defecto]
    local var="$1" prompt="$2" def="${3:-}" value
    [ -n "${!var:-}" ] && return
    if [ ! -t 0 ]; then printf -v "$var" '%s' "$def"; return; fi
    read -r -p "  $prompt${def:+ [$def]}: " value
    printf -v "$var" '%s' "${value:-$def}"
}
rand() { openssl rand -hex "$1"; }
# "Pizzería L'Àncora" → "pizzeria-l-ancora" (sin depender del locale para transliterar).
slug() {
    echo "$1" | LC_ALL=C.UTF-8 sed 'y/áàäâÁÀÄÂéèëêÉÈËÊíìïîÍÌÏÎóòöôÓÒÖÔúùüûÚÙÜÛñÑçÇ/aaaaaaaaeeeeeeeeiiiiiiiioooooooouuuuuuuunncc/' \
        | tr '[:upper:]' '[:lower:]' | tr -cs 'a-z0-9' '-' | sed 's/^-//;s/-$//' | cut -c1-30
}
envset() { # envset <fichero> CLAVE valor — solo si no existe o está vacía (idempotente)
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=.+" "$file" 2>/dev/null; then return; fi
    if grep -qE "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value//|/\\|}|" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}
compose() { (cd "$TPV_DIR" && docker compose "$@"); }

# --- 0. Comprobaciones y datos ------------------------------------------------
[ "$(id -u)" = 0 ] || [ "$DRY_RUN" = 1 ] || { echo "Ejecútalo como root (sudo)." >&2; exit 1; }
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    [ "${ID:-}" = debian ] && [ "${VERSION_ID:-}" = 12 ] || warn "Pensado para Debian 12 (esto es ${PRETTY_NAME:-desconocido})."
fi
# shellcheck disable=SC1090
[ -f "$CONFIG" ] && . "$CONFIG"

say "Datos de la instalación"
ask LOCAL_NAME "Nombre del local" "Mi local"
ask LAN_IP "IP fija del mini PC (reservada en el router)" "$(hostname -I 2>/dev/null | awk '{print $1}')"
ask ADMIN_EMAIL "Email del administrador" ""
ask ORDER_TYPES "Tipos de pedido (mesa,barra,para_llevar,domicilio)" "mesa,barra"
ask TPV_IMAGE "Imagen" "ghcr.io/CAMBIAR/tpv-hosteleria"
ask TPV_VERSION "Versión" "1.0.0"
[ -n "${ADMIN_EMAIL:-}" ] || { echo "Falta ADMIN_EMAIL." >&2; exit 1; }
SLUG="$(slug "$LOCAL_NAME")"
ok "Local: $LOCAL_NAME · IP $LAN_IP · admin $ADMIN_EMAIL · $TPV_IMAGE:$TPV_VERSION"

# --- 1. Sistema -------------------------------------------------------------------
say "Sistema: zona horaria, NTP, actualizaciones de seguridad"
run apt-get update -qq
run apt-get install -y -qq ca-certificates curl gnupg openssl ufw unattended-upgrades apt-listchanges iproute2
run timedatectl set-timezone Europe/Madrid
run timedatectl set-ntp true
put /etc/apt/apt.conf.d/20auto-upgrades 644 <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF
# Solo seguridad de Debian; sin reinicios automáticos (el TPV se reinicia a mano, fuera de horario).
put /etc/apt/apt.conf.d/51tpv-unattended 644 <<'CONF'
Unattended-Upgrade::Origins-Pattern { "origin=Debian,codename=${distro_codename}-security,label=Debian-Security"; };
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
CONF
ok "Europe/Madrid, NTP y unattended-upgrades (solo seguridad)"

say "Cortafuegos (ufw): SSH y el TPV (80)"
run ufw default deny incoming
run ufw default allow outgoing
run ufw allow OpenSSH
run ufw allow 80/tcp comment 'TPV'
run ufw --force enable
ok "ufw activo"

# --- 2. Docker -----------------------------------------------------------------------
say "Docker"
if command -v docker >/dev/null 2>&1; then
    ok "Docker ya instalado ($(docker --version 2>/dev/null))"
else
    run install -m 0755 -d /etc/apt/keyrings
    run sh -c 'curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg'
    put /etc/apt/sources.list.d/docker.list 644 <<CONF
deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable
CONF
    run apt-get update -qq
    run apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
    run systemctl enable --now docker
    ok "Docker instalado"
fi
# Logs de Docker con rotación también para lo que no use el compose.
put /etc/docker/daemon.json 644 <<'CONF'
{ "log-driver": "local", "log-opts": { "max-size": "10m", "max-file": "3" } }
CONF

# --- 3. Kiosko ---------------------------------------------------------------------
say "Kiosko: usuario $KIOSK_USER con autologin, cage + Chromium"
run apt-get install -y -qq cage chromium fonts-dejavu-core wlr-randr
if ! id "$KIOSK_USER" >/dev/null 2>&1; then
    run useradd --create-home --shell /bin/bash --groups video,input,render,audio "$KIOSK_USER"
    run passwd --lock "$KIOSK_USER"    # sin contraseña: solo entra por autologin en tty1
fi
put /etc/systemd/system/getty@tty1.service.d/autologin.conf 644 <<CONF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $KIOSK_USER --noclear %I \$TERM
CONF
put "/home/$KIOSK_USER/.bash_profile" 644 <<'CONF'
# En tty1 arranca el kiosko del TPV (si se cierra, vuelve a arrancar).
if [ "$(tty)" = /dev/tty1 ]; then exec /usr/local/bin/tpv-kiosk; fi
CONF
put /usr/local/bin/tpv-kiosk 755 < "$HERE/tpv-kiosk"
# Chromium: sin traducción, contraseñas, primer arranque ni restaurar sesión.
put /etc/chromium/policies/managed/tpv.json 644 <<'CONF'
{
  "TranslateEnabled": false,
  "PasswordManagerEnabled": false,
  "AutofillAddressEnabled": false,
  "AutofillCreditCardEnabled": false,
  "RestoreOnStartup": 4,
  "RestoreOnStartupURLs": ["http://localhost/?kiosk=1"],
  "BrowserSignin": 0,
  "SyncDisabled": true,
  "DefaultNotificationsSetting": 2,
  "DefaultGeolocationSetting": 2,
  "MetricsReportingEnabled": false,
  "PromotionalTabsEnabled": false
}
CONF
run chown -R "$KIOSK_USER:$KIOSK_USER" "/home/$KIOSK_USER"
# Pantalla: no se apaga nunca (cage no tiene apagado por inactividad); opcionalmente de noche.
if [ -n "${SCREEN_OFF:-}" ] && [ -n "${SCREEN_ON:-}" ]; then
    put /etc/cron.d/tpv-pantalla 644 <<CONF
# Apagar y encender la pantalla del kiosko fuera del horario del local.
${SCREEN_OFF#*:} ${SCREEN_OFF%%:*} * * * $KIOSK_USER /usr/local/bin/tpv-kiosk --screen off
${SCREEN_ON#*:} ${SCREEN_ON%%:*} * * * $KIOSK_USER /usr/local/bin/tpv-kiosk --screen on
CONF
    ok "Pantalla: se apaga a las $SCREEN_OFF y se enciende a las $SCREEN_ON"
else
    run rm -f "${ROOT}/etc/cron.d/tpv-pantalla"
    ok "Pantalla: siempre encendida"
fi

# --- 4. Impresoras USB ----------------------------------------------------------------
say "Impresoras USB"
LP_GID="$(getent group lp | cut -d: -f3 || echo 7)"
RULES=""
while read -r line; do
    line="${line//[[:space:]]/}"
    [ -z "$line" ] && continue
    IFS=: read -r name vendor product serial <<<"$line"
    name="$(slug "$name")"
    RULES+="# ${name}: /dev/usb/impresora-${name}"$'\n'
    match="ATTRS{idVendor}==\"${vendor}\", ATTRS{idProduct}==\"${product}\""
    [ -n "${serial:-}" ] && match+=", ATTRS{serial}==\"${serial}\""
    RULES+="SUBSYSTEM==\"usbmisc\", KERNEL==\"lp*\", ${match}, SYMLINK+=\"usb/impresora-${name}\", GROUP=\"lp\", MODE=\"0660\""$'\n'
    ok "/dev/usb/impresora-${name} (${vendor}:${product}${serial:+ serie ${serial}})"
done <<<"${PRINTERS_USB:-}"
if [ -n "$RULES" ]; then
    put /etc/udev/rules.d/99-impresoras-tpv.rules 644 <<<"$RULES"
    run udevadm control --reload-rules
    run udevadm trigger --subsystem-match=usbmisc
    echo usblp | put /etc/modules-load.d/usblp.conf 644
else
    ok "Ninguna (impresoras de red)"
fi
# El contenedor de impresión ve /dev/usb (enchufar/desenchufar en caliente) y solo puede usar usblp (major 180).
put /opt/tpv/compose.override.yaml 644 <<CONF
# Generado por install.sh: impresoras USB para el worker de impresión.
services:
    printing:
        device_cgroup_rules: ['c 180:* rmw']
        volumes:
            - '/dev/usb:/dev/usb'
        group_add:
            - '${LP_GID}'
CONF
run mkdir -p "${ROOT}/dev/usb" 2>/dev/null || true

# --- 5. Tailscale ------------------------------------------------------------------------
say "Soporte remoto: Tailscale"
if ! command -v tailscale >/dev/null 2>&1; then
    run sh -c 'curl -fsSL https://tailscale.com/install.sh | sh'
fi
run ufw allow in on tailscale0 comment 'Tailscale'
if [ -n "${TAILSCALE_AUTHKEY:-}" ]; then
    run tailscale up --ssh --hostname "tpv-${SLUG}" --authkey "$TAILSCALE_AUTHKEY"
    ok "Unido a Tailscale como tpv-${SLUG}"
else
    warn "Sin TAILSCALE_AUTHKEY: luego, 'sudo tailscale up --ssh --hostname tpv-${SLUG}' y abre el enlace."
fi

# --- 6. TPV -------------------------------------------------------------------------------
say "TPV en /opt/tpv"
run mkdir -p "$TPV_DIR"
put /opt/tpv/compose.yaml 644 < "$HERE/compose.yaml"
put /usr/local/bin/tpv-update 755 < "$HERE/tpv-update"
put /usr/local/bin/tpv-restore 755 < "$HERE/tpv-restore"

ENV="$TPV_DIR/.env"
if [ ! -f "$ENV" ]; then
    if [ "$DRY_RUN" = 1 ] && [ -z "$ROOT" ]; then echo "  [dry-run] crearía $ENV"; else cp "$HERE/env.example" "$ENV"; chmod 600 "$ENV"; fi
fi
if [ -f "$ENV" ]; then
    # Solo se rellena lo que esté vacío: volver a ejecutar no cambia claves ni contraseñas.
    envset "$ENV" APP_KEY "base64:$(openssl rand -base64 32)"
    envset "$ENV" DB_PASSWORD "$(rand 24)"
    envset "$ENV" REVERB_APP_ID "$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
    envset "$ENV" REVERB_APP_KEY "$(rand 10)"
    envset "$ENV" REVERB_APP_SECRET "$(rand 20)"
    sed -i -e "s|^APP_NAME=.*|APP_NAME=\"${LOCAL_NAME//|/\\|}\"|" -e "s|^APP_URL=.*|APP_URL=http://${LAN_IP}|" \
        -e "s|^APP_LAN_IP=.*|APP_LAN_IP=${LAN_IP}|" -e "s|^TPV_IMAGE=.*|TPV_IMAGE=${TPV_IMAGE}|" "$ENV"
    grep -qE '^TPV_VERSION=.+' "$ENV" || sed -i "s|^TPV_VERSION=.*|TPV_VERSION=${TPV_VERSION}|" "$ENV"
    [ -n "${BACKUP_AGE_RECIPIENT:-}" ] && sed -i "s|^BACKUP_AGE_RECIPIENT=.*|BACKUP_AGE_RECIPIENT=${BACKUP_AGE_RECIPIENT}|" "$ENV"
    [ -n "${BACKUP_REMOTE:-}" ] && sed -i "s|^BACKUP_REMOTE=.*|BACKUP_REMOTE=${BACKUP_REMOTE}|" "$ENV"
    ok ".env con claves propias de esta instalación (600)"
fi

if [ "$DRY_RUN" = 1 ]; then
    echo "  [dry-run] imagen, migraciones y admin: omitido"
else
    if [ -n "${IMAGE_TAR:-}" ]; then
        gunzip -c "$IMAGE_TAR" | docker load
    else
        [ -n "${GHCR_TOKEN:-}" ] && echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null
        compose pull -q
    fi
    compose up -d pgsql
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(compose ps -q pgsql)")" = healthy ]; do sleep 2; done
    compose run --rm --no-deps -T app php artisan migrate --force
    compose up -d
    SETUP="$(compose run --rm --no-deps -T app php artisan tpv:setup --name="$LOCAL_NAME" --admin-email="$ADMIN_EMAIL" --types="$ORDER_TYPES" --json | tail -1)"
    PASSWORD="$(echo "$SETUP" | sed -nE 's/.*"admin_password":"([^"]+)".*/\1/p')"
    PIN="$(echo "$SETUP" | sed -nE 's/.*"admin_pin":"([^"]+)".*/\1/p')"
    if [ -n "$PASSWORD" ]; then
        put /root/tpv-credenciales.txt 600 <<CRED
TPV ${LOCAL_NAME} — $(date '+%F %T')
/admin: http://${LAN_IP}/admin   ${ADMIN_EMAIL} / ${PASSWORD}
PIN del TPV del admin: ${PIN}
CRED
    fi
fi

# Actualización automática (opcional; por defecto, a mano con tpv-update).
if [ -n "${AUTO_UPDATE_AT:-}" ]; then
    put /etc/cron.d/tpv-update 644 <<CONF
${AUTO_UPDATE_AT#*:} ${AUTO_UPDATE_AT%%:*} * * * root /usr/local/bin/tpv-update latest >> /var/log/tpv-update.log 2>&1
CONF
    ok "Actualización automática a las $AUTO_UPDATE_AT (solo si la caja está cerrada)"
else
    run rm -f "${ROOT}/etc/cron.d/tpv-update"
fi

# --- 7. Resumen ----------------------------------------------------------------------------
say "Listo"
cat <<SUMMARY
  TPV:       http://${LAN_IP}/        (la pantalla táctil lo abre sola al reiniciar)
  Admin:     http://${LAN_IP}/admin   ${ADMIN_EMAIL}  (contraseña y PIN en /root/tpv-credenciales.txt)
  Móviles:   misma wifi → http://${LAN_IP}
  Remoto:    Tailscale → http://tpv-${SLUG}   ·   ssh por Tailscale
  Copias:    ${BACKUP_REMOTE:-solo locales (configura BACKUP_REMOTE)} · clave pública ${BACKUP_AGE_RECIPIENT:-SIN CONFIGURAR}

  Siguientes pasos:
   1. Reinicia (sudo reboot) y comprueba que arranca el kiosko.
   2. /admin → Ajustes del local: datos fiscales reales (NIF, razón social, dirección).
   3. /admin → Impresoras: red (IP:9100) o USB (/dev/usb/impresora-<nombre>) e "Imprimir prueba".
   4. /admin → Usuarios: camareros y encargados con su PIN.
   5. Sigue docs/checklist-instalacion.md.
SUMMARY
