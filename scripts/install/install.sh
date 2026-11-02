#!/usr/bin/env bash
# Instalador del TPV en el mini PC (Debian 13 trixie, con o sin escritorio).
#
#   sudo bash install.sh                      cliente real, imagen construida con este código
#   sudo bash install.sh --demo[=DATOS]       demo con datos de ejemplo (bar | pizzeria | pizzeria_mixta)
#   sudo bash install.sh --ghcr TOKEN         descarga la imagen de GHCR en vez de construirla
#   sudo bash install.sh --config FICHERO     valores desde un fichero (instalaciones automáticas)
#   sudo bash install.sh --dry-run            solo muestra lo que haría
#
# Sin preguntas: solo instala. La empresa, el NIF, el régimen, las impresoras,
# las copias, Tailscale y VERI*FACTU se configuran después en /admin
# (primer acceso: «Crear administrador» → «Puesta en marcha»).
# Se puede volver a lanzar sin perder datos ni cambiar claves; si se borró
# /opt/tpv, recupera las claves de /root/tpv-credenciales.txt.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"          # la carpeta copiada (código del proyecto)
CONFIG=""
DRY_RUN=0
MODE=""
DEMO_DATASET_ARG=""
GHCR_TOKEN_ARG=""
ROOT="${ROOT_PREFIX:-}"                     # pruebas: escribir los ficheros bajo otra raíz
TPV_DIR="${ROOT}/opt/tpv"
KIOSK_USER=kiosk
APP_UID=1000                                # usuario de la app dentro de la imagen

usage() { sed -n '2,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }
while [ $# -gt 0 ]; do
    case "$1" in
        --demo) MODE=demo; shift ;;
        --demo=*) MODE=demo; DEMO_DATASET_ARG="${1#*=}"; shift ;;
        --ghcr) GHCR_TOKEN_ARG="${2:-}"; shift 2 ;;
        --ghcr=*) GHCR_TOKEN_ARG="${1#*=}"; shift ;;
        --config) CONFIG="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Opción desconocida: $1 (ver --help)" >&2; exit 2 ;;
    esac
done

# --- utilidades -----------------------------------------------------------------------------
STEP=0
STEPS=9
step() { STEP=$((STEP + 1)); printf '\n\033[1;34m[%d/%d] %s\033[0m\n' "$STEP" "$STEPS" "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; NOTES+=("$*"); }
die() { printf '\n\033[31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
NOTES=()
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
# Copia un fichero del proyecto al sistema.
install_file() { put "$2" "$3" < "$1"; }
rand() { openssl rand -hex "$1" 2>/dev/null || head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
rand64() { openssl rand -base64 "$1" 2>/dev/null || head -c "$1" /dev/urandom | base64; }
envget() { grep -E "^$2=" "$1" 2>/dev/null | tail -1 | cut -d= -f2- || true; }
envset() { # envset <fichero> CLAVE valor — solo si no existe o está vacía (no cambia claves)
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=.+" "$file" 2>/dev/null; then return; fi
    if grep -qE "^${key}=" "$file"; then sed -i "s|^${key}=.*|${key}=${value//|/\\|}|" "$file"; else echo "${key}=${value}" >> "$file"; fi
}
envforce() { # envforce <fichero> CLAVE valor — siempre
    local file="$1" key="$2" value="$3"
    if grep -qE "^${key}=" "$file"; then sed -i "s|^${key}=.*|${key}=${value//|/\\|}|" "$file"; else echo "${key}=${value}" >> "$file"; fi
}
compose() { (cd "$TPV_DIR" && docker compose "$@"); }
# /root/tpv-credenciales.txt (600) con las claves del .env. Si el fichero anterior tenía
# otra APP_KEY, se guarda aparte (nunca se pierde una clave).
write_credentials() {
    if [ "$DRY_RUN" = 1 ] && [ -z "$ROOT" ]; then echo "  [dry-run] escribiría /root/tpv-credenciales.txt"; return; fi
    [ -f "$ENV" ] || return 0
    mkdir -p "$(dirname "$CRED_FILE")"
    local old_key k
    old_key="$(envget "$CRED_FILE" APP_KEY)"
    if [ -n "$old_key" ] && [ "$old_key" != "$(envget "$ENV" APP_KEY)" ]; then
        cp -p "$CRED_FILE" "$CRED_FILE.anterior-$(date +%Y%m%d%H%M%S)"
        warn "Las credenciales anteriores tenían otra APP_KEY: guardadas en $(basename "$CRED_FILE").anterior-* (en /root)."
    fi
    {
        echo "TPV · $(date '+%F %T') · instalación $MODE"
        echo "Caja:   http://${LAN_IP}/"
        echo "Admin:  http://${LAN_IP}/admin"
        [ "$MODE" = demo ] && echo "Usuarios de demo: ver README (Datos de demo)."
        echo
        echo "Guárdalo en el gestor de contraseñas (sin la APP_KEY no se leen los datos cifrados de las copias)."
        echo "Si se borra /opt/tpv, install.sh recupera estas claves de aquí."
        for k in $CRED_KEYS TPV_INSTALL_MODE; do
            echo "$k=$(envget "$ENV" "$k")"
        done
    } > "$CRED_FILE.tmp"
    chmod 600 "$CRED_FILE.tmp" && mv "$CRED_FILE.tmp" "$CRED_FILE"
}
artisan() { compose run --rm --no-deps -T app php artisan "$@"; }
has() { [ -e "${ROOT}$1" ]; }

# --- 0. Comprobaciones y valores ------------------------------------------------------------
[ "$(id -u)" = 0 ] || [ "$DRY_RUN" = 1 ] || die "Ejecútalo como root: sudo bash install.sh"
if [ -r "${ROOT}/etc/os-release" ]; then
    # shellcheck disable=SC1091
    . "${ROOT}/etc/os-release"
    [ "${ID:-}" = debian ] && [ "${VERSION_ID:-}" = 13 ] || warn "Pensado para Debian 13 trixie (esto es ${PRETTY_NAME:-desconocido})."
fi
CODENAME="${VERSION_CODENAME:-trixie}"
if [ -n "$CONFIG" ]; then
    [ -r "$CONFIG" ] || die "No se puede leer $CONFIG."
    # shellcheck disable=SC1090
    . "$CONFIG"
fi
MODE="${MODE:-${TPV_MODE:-cliente}}"
case "$MODE" in cliente|demo) ;; *) die "TPV_MODE debe ser cliente o demo." ;; esac
DEMO_DATASET="${DEMO_DATASET_ARG:-${DEMO_DATASET:-pizzeria_mixta}}"
case "$DEMO_DATASET" in bar|pizzeria|pizzeria_mixta) ;; *) die "Datos de demo: bar, pizzeria o pizzeria_mixta." ;; esac
GHCR_TOKEN="${GHCR_TOKEN_ARG:-${GHCR_TOKEN:-}}"
SOURCE=build
[ -n "$GHCR_TOKEN" ] && SOURCE=ghcr
TPV_VERSION="${TPV_VERSION:-$(tr -d '[:space:]' < "$REPO/VERSION" 2>/dev/null || echo latest)}"
if [ "$SOURCE" = build ]; then
    TPV_IMAGE="${TPV_IMAGE_LOCAL:-tpv-hosteleria}"
    [ -f "$REPO/docker/production/Dockerfile" ] || die "No está el código del proyecto junto al instalador ($REPO). Copia la carpeta entera o usa --ghcr TOKEN."
else
    TPV_IMAGE="${TPV_IMAGE:-ghcr.io/CAMBIAR/tpv-hosteleria}"
fi
# IP de la red local: la de la ruta por defecto (o la primera privada).
detect_ip() {
    local ip
    ip="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="src") {print $(i+1); exit}}')"
    [ -n "$ip" ] || ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^(10|172\.(1[6-9]|2[0-9]|3[01])|192\.168)\.' | head -1)"
    echo "${ip:-127.0.0.1}"
}
LAN_IP="${LAN_IP:-$(detect_ip)}"
LOCAL_NAME="${LOCAL_NAME:-Mi local}"

ENV="$TPV_DIR/.env"
CRED_FILE="${ROOT}/root/tpv-credenciales.txt"
CRED_KEYS="APP_KEY DB_PASSWORD REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET VERIFACTU_INSTALLATION_NUMBER"
# Se borró /opt/tpv (o solo el .env) pero quedan las credenciales: se recuperan las mismas
# claves. La base de datos vive en un volumen de Docker que NO se borra con la carpeta, y
# sin la APP_KEY ni la contraseña de antes no se podría usar.
RECOVER_KEYS=0
if [ ! -f "$ENV" ] && grep -qE '^APP_KEY=base64:.+' "$CRED_FILE" 2>/dev/null; then RECOVER_KEYS=1; fi

# Instalación anterior: el modo no cambia sin querer; una versión nueva pasa por tpv-update.
PREV_MODE="$(envget "$ENV" TPV_INSTALL_MODE)"
[ -n "$PREV_MODE" ] || [ "$RECOVER_KEYS" = 0 ] || PREV_MODE="$(envget "$CRED_FILE" TPV_INSTALL_MODE)"
PREV_VERSION="$(envget "$ENV" TPV_VERSION)"
WIPE_DEMO=0
if [ "$PREV_MODE" = demo ] && [ "$MODE" = cliente ]; then
    echo
    echo "Este equipo tiene una instalación de DEMO. Pasar a cliente real BORRA todos sus datos"
    echo "(pedidos, tickets de prueba, carta, usuarios) y la numeración empieza de cero."
    if [ "$DRY_RUN" = 1 ]; then
        echo "  [dry-run] pediría escribir BORRAR"
    elif [ -t 0 ]; then
        read -r -p "Escribe BORRAR para continuar: " answer
        [ "$answer" = BORRAR ] || die "Cancelado: no se ha tocado nada."
    else
        die "Hace falta confirmarlo escribiendo BORRAR: lanza el instalador desde una terminal."
    fi
    WIPE_DEMO=1
elif [ "$PREV_MODE" = cliente ] && [ "$MODE" = demo ]; then
    die "Este equipo es una instalación de cliente real: no se convierte en demo."
fi

# Versión nueva sobre una instalación con datos: copia y vuelta atrás automática (tpv-update).
UPGRADE=0
if [ -n "$PREV_VERSION" ] && [ "$PREV_VERSION" != "$TPV_VERSION" ] && [ "$WIPE_DEMO" = 0 ]; then UPGRADE=1; fi

# Escritorio: gestor de inicio de sesión y entorno (TPV_DESKTOP=si|no fuerza la detección).
detect_dm() {
    local dm
    for dm in gdm3 lightdm sddm; do
        if has "/usr/sbin/$dm" || has "/usr/bin/$dm"; then echo "$dm"; return 0; fi
    done
    return 0
}
detect_de() { # → «GNOME:gnome», «XFCE:xfce», «KDE:plasma» u «otro:<sesión>»
    local f name
    for f in "${ROOT}"/usr/share/wayland-sessions/*.desktop "${ROOT}"/usr/share/xsessions/*.desktop; do
        [ -e "$f" ] || continue
        name="$(basename "$f" .desktop)"
        case "$name" in
            gnome|gnome-wayland|gnome-xorg) echo "GNOME:$name"; return ;;
            xfce) echo "XFCE:xfce"; return ;;
            plasma|plasmawayland|plasmax11) echo "KDE:$name"; return ;;
        esac
    done
    for f in "${ROOT}"/usr/share/wayland-sessions/*.desktop "${ROOT}"/usr/share/xsessions/*.desktop; do
        [ -e "$f" ] && { echo "otro:$(basename "$f" .desktop)"; return 0; }
    done
    return 0
}
KIOSK_DM=""
KIOSK_DE=""
KIOSK_SESSION=""
case "${TPV_DESKTOP:-auto}" in
    no) ;;
    *) KIOSK_DM="$(detect_dm)"; de="$(detect_de)"; KIOSK_DE="${de%%:*}"; KIOSK_SESSION="${de#*:}" ;;
esac
if [ -n "$KIOSK_DM" ] || [ "${TPV_DESKTOP:-auto}" = si ]; then KIOSK_MODE=desktop; else KIOSK_MODE=console; fi

echo "TPV · instalación «$MODE»$([ "$MODE" = demo ] && echo " (datos: $DEMO_DATASET)") · IP $LAN_IP · imagen $([ "$SOURCE" = build ] && echo "construida aquí" || echo "de GHCR") $TPV_IMAGE:$TPV_VERSION"
echo "Kiosko: $([ "$KIOSK_MODE" = desktop ] && echo "escritorio ${KIOSK_DE:-?} con ${KIOSK_DM:-?}" || echo "sin escritorio (cage en tty1)")"

# --- 1. Sistema -----------------------------------------------------------------------------
step "Sistema: hora, actualizaciones de seguridad y disco"
run apt-get update -qq
run apt-get install -y -qq ca-certificates curl gnupg openssl ufw unattended-upgrades apt-listchanges iproute2 systemd-timesyncd util-linux
run timedatectl set-timezone Europe/Madrid
run timedatectl set-ntp true
run systemctl enable --now fstrim.timer
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
# Una caja no se suspende nunca.
run systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target
ok "Europe/Madrid con NTP, actualizaciones de seguridad, TRIM semanal, sin suspensión"

# --- 2. Cortafuegos -------------------------------------------------------------------------
step "Cortafuegos: SSH y el TPV (80)"
run ufw default deny incoming
run ufw default allow outgoing
run ufw allow OpenSSH
run ufw allow 80/tcp comment 'TPV'
run ufw allow in on tailscale0 comment 'Tailscale'
run ufw --force enable
ok "ufw activo"

# --- 3. Docker ------------------------------------------------------------------------------
step "Docker"
if command -v docker >/dev/null 2>&1; then
    ok "Ya instalado ($(docker --version 2>/dev/null))"
else
    run install -m 0755 -d /etc/apt/keyrings
    run curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
    run chmod a+r /etc/apt/keyrings/docker.asc
    put /etc/apt/sources.list.d/docker.sources 644 <<CONF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: ${CODENAME}
Components: stable
Architectures: amd64
Signed-By: /etc/apt/keyrings/docker.asc
CONF
    run apt-get update -qq
    run apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    run systemctl enable --now docker
    ok "Docker instalado y con arranque automático"
fi
put /etc/docker/daemon.json 644 <<'CONF'
{ "log-driver": "local", "log-opts": { "max-size": "10m", "max-file": "3" } }
CONF

# --- 4. Kiosko ------------------------------------------------------------------------------
step "Kiosko: $([ "$KIOSK_MODE" = desktop ] && echo "escritorio ${KIOSK_DE:-?}" || echo "cage en tty1")"
if ! id "$KIOSK_USER" >/dev/null 2>&1; then
    run useradd --create-home --shell /bin/bash --groups video,input,render,audio "$KIOSK_USER"
    run passwd --lock "$KIOSK_USER"     # sin contraseña: solo entra por inicio automático
fi
run apt-get install -y -qq chromium fonts-dejavu-core curl
install_file "$HERE/tpv-kiosk" /usr/local/bin/tpv-kiosk 755
install_file "$HERE/host/tpv-mantenimiento" /usr/local/sbin/tpv-mantenimiento 755
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
KIOSK_HOME="/home/$KIOSK_USER"
if [ "$KIOSK_MODE" = desktop ]; then
    # Con escritorio: no se instala cage ni se toca tty1.
    install_file "$HERE/host/tpv-kiosk-session" /usr/local/bin/tpv-kiosk-session 755
    case "$KIOSK_DM" in
        gdm3)
            if has /etc/gdm3/daemon.conf || [ -n "$ROOT" ]; then
                conf="${ROOT}/etc/gdm3/daemon.conf"
                if [ "$DRY_RUN" = 1 ] && [ -z "$ROOT" ]; then
                    echo "  [dry-run] GDM: inicio automático de $KIOSK_USER (/etc/gdm3/daemon.conf)"
                else
                    mkdir -p "$(dirname "$conf")"; [ -f "$conf" ] || printf '[daemon]\n' > "$conf"
                    grep -q '^\[daemon\]' "$conf" || printf '[daemon]\n' >> "$conf"
                    sed -i -E '/^#?[[:space:]]*(AutomaticLoginEnable|AutomaticLogin)[[:space:]]*=/d' "$conf"
                    sed -i "/^\[daemon\]/a AutomaticLoginEnable = true\nAutomaticLogin = $KIOSK_USER" "$conf"
                fi
            fi
            ok "GDM: inicio automático de $KIOSK_USER" ;;
        lightdm)
            run groupadd -f -r autologin
            run usermod -aG autologin "$KIOSK_USER"
            put /etc/lightdm/lightdm.conf.d/50-tpv-kiosk.conf 644 <<CONF
[Seat:*]
autologin-user=$KIOSK_USER
autologin-user-timeout=0
${KIOSK_SESSION:+autologin-session=$KIOSK_SESSION}
CONF
            ok "LightDM: inicio automático de $KIOSK_USER" ;;
        sddm)
            put /etc/sddm.conf.d/50-tpv-kiosk.conf 644 <<CONF
[Autologin]
User=$KIOSK_USER
${KIOSK_SESSION:+Session=$KIOSK_SESSION}
Relogin=true
CONF
            ok "SDDM: inicio automático de $KIOSK_USER" ;;
        *) warn "Escritorio sin gestor de inicio soportado (GDM, LightDM o SDDM): configura a mano el inicio automático del usuario $KIOSK_USER." ;;
    esac
    put "$KIOSK_HOME/.config/autostart/tpv-kiosk.desktop" 644 <<'CONF'
[Desktop Entry]
Type=Application
Name=TPV (kiosko)
Exec=/usr/local/bin/tpv-kiosk-session
X-GNOME-Autostart-enabled=true
X-GNOME-Autostart-Delay=2
NoDisplay=true
CONF
    # Avisos de actualizaciones y teclado en pantalla del escritorio: desactivados en la sesión del kiosko.
    for app in org.gnome.Software update-notifier gnome-software-service org.kde.discover.notifier onboard-autostart \
        caribou org.gnome.Evolution-alarm-notify xfce4-screensaver light-locker; do
        put "$KIOSK_HOME/.config/autostart/$app.desktop" 644 <<CONF
[Desktop Entry]
Type=Application
Name=$app
Hidden=true
CONF
    done
    case "$KIOSK_DE" in
        GNOME|XFCE|KDE) ok "Sesión blindada ($KIOSK_DE): sin bloqueo, salvapantallas, apagado de pantalla, notificaciones ni teclado en pantalla" ;;
        *) warn "Escritorio ${KIOSK_SESSION:-desconocido} no soportado para el blindaje: el kiosko arranca, pero revisa a mano bloqueo de pantalla, salvapantallas y notificaciones." ;;
    esac
else
    run apt-get install -y -qq cage wlr-randr
    put /etc/systemd/system/getty@tty1.service.d/autologin.conf 644 <<CONF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin $KIOSK_USER --noclear %I \$TERM
CONF
    put "$KIOSK_HOME/.bash_profile" 644 <<'CONF'
# En tty1 arranca el kiosko del TPV (si se cierra, vuelve a arrancar).
if [ "$(tty)" = /dev/tty1 ]; then exec /usr/local/bin/tpv-kiosk; fi
CONF
    ok "cage + Chromium en tty1 con inicio automático"
fi
put /etc/tpv/kiosk.env 644 <<CONF
# Lo usa tpv-mantenimiento.
KIOSK_MODE=$KIOSK_MODE
KIOSK_DM=$KIOSK_DM
KIOSK_DE=$KIOSK_DE
CONF
run chown -R "$KIOSK_USER:$KIOSK_USER" "$KIOSK_HOME"
ok "Mantenimiento: sudo tpv-mantenimiento on|off (Ctrl+Alt+F3 o SSH)"

# --- 5. Impresoras USB ----------------------------------------------------------------------
step "Impresoras USB: enlace fijo para cualquier impresora"
install_file "$HERE/host/impresora-usb-nombre" /usr/local/lib/tpv/impresora-usb-nombre 755
LP_GID="$(getent group lp | cut -d: -f3 || echo 7)"
put /etc/udev/rules.d/99-impresoras-tpv.rules 644 <<'CONF'
# TPV: cualquier impresora USB (usblp) → /dev/usb/impresora-<fabricante>-<modelo>[-<serie>]
# (el mismo nombre que muestra /admin → Impresoras → «Impresoras USB conectadas»).
SUBSYSTEM=="usbmisc", KERNEL=="lp*", GROUP="lp", MODE="0660", PROGRAM="/usr/local/lib/tpv/impresora-usb-nombre %p", SYMLINK+="usb/%c"
CONF
echo usblp | put /etc/modules-load.d/usblp.conf 644
run udevadm control --reload-rules
run udevadm trigger --subsystem-match=usbmisc
ok "Regla udev genérica: se dan de alta en /admin → Impresoras (Detectar)"

# --- 6. Tailscale ---------------------------------------------------------------------------
step "Tailscale (se une desde /admin)"
if ! command -v tailscale >/dev/null 2>&1; then
    run sh -c 'curl -fsSL https://tailscale.com/install.sh | sh'
fi
run install -d -m 700 -o "$APP_UID" -g "$APP_UID" "${ROOT}/opt/tpv/host/tailscale"
install_file "$HERE/host/tpv-tailscale-join" /usr/local/sbin/tpv-tailscale-join 755
for unit in tpv-tailscale.path tpv-tailscale.service tpv-tailscale-status.service tpv-tailscale-status.timer; do
    install_file "$HERE/systemd/$unit" "/etc/systemd/system/$unit" 644
done
run systemctl daemon-reload
run systemctl enable --now tpv-tailscale.path tpv-tailscale-status.timer
ok "Carpeta /opt/tpv/host/tailscale (700) y servicio que recoge la clave de /admin"

# --- 7. TPV ---------------------------------------------------------------------------------
step "TPV en /opt/tpv ($([ "$SOURCE" = build ] && echo "construyendo la imagen" || echo "descargando la imagen"))"
run mkdir -p "$TPV_DIR"
install_file "$REPO/docker-compose.prod.yml" /opt/tpv/compose.yaml 644
install_file "$REPO/scripts/host/tpv-update" /usr/local/bin/tpv-update 755
install_file "$REPO/scripts/host/tpv-restore" /usr/local/bin/tpv-restore 755
# Impresoras USB para el worker de impresión (solo usblp, major 180; en caliente).
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

if [ ! -f "$ENV" ]; then
    if [ "$DRY_RUN" = 1 ] && [ -z "$ROOT" ]; then echo "  [dry-run] crearía $ENV"; else cp "$REPO/docker/production/env.example" "$ENV"; chmod 600 "$ENV"; fi
fi
NEW_APP_KEY=0
if [ -f "$ENV" ]; then
    if [ "$RECOVER_KEYS" = 1 ]; then
        for k in $CRED_KEYS; do
            v="$(envget "$CRED_FILE" "$k")"
            [ -z "$v" ] || envforce "$ENV" "$k" "$v"
        done
        ok "Claves recuperadas de /root/tpv-credenciales.txt (las mismas de antes)"
    fi
    grep -qE '^APP_KEY=.+' "$ENV" || NEW_APP_KEY=1
    # Claves propias de esta instalación: solo se generan si faltan (relanzar no las cambia).
    envset "$ENV" APP_KEY "base64:$(rand64 32)"
    envset "$ENV" DB_PASSWORD "$(rand 24)"
    envset "$ENV" REVERB_APP_ID "$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
    envset "$ENV" REVERB_APP_KEY "$(rand 10)"
    envset "$ENV" REVERB_APP_SECRET "$(rand 20)"
    # Número de instalación del SIF: uno por equipo (se conserva al relanzar o restaurar aquí).
    envset "$ENV" VERIFACTU_INSTALLATION_NUMBER "$(date +%Y%m%d%H%M%S)-$(rand 4)"
    envforce "$ENV" TPV_INSTALL_MODE "$MODE"
    envforce "$ENV" APP_URL "http://${LAN_IP}"
    envforce "$ENV" APP_LAN_IP "$LAN_IP"
    envforce "$ENV" TPV_IMAGE "$TPV_IMAGE"
    [ "$UPGRADE" = 1 ] || envforce "$ENV" TPV_VERSION "$TPV_VERSION"   # al actualizar la cambia tpv-update
    chmod 600 "$ENV"
    ok ".env con claves propias (600), modo $MODE"
fi
# Ya, antes de construir o arrancar nada: si algo falla después, las claves están guardadas.
write_credentials

if [ "$DRY_RUN" = 1 ]; then
    if [ "$UPGRADE" = 1 ]; then
        echo "  [dry-run] imagen $TPV_VERSION y tpv-update de $PREV_VERSION a $TPV_VERSION (copia y vuelta atrás): omitido"
    else
        echo "  [dry-run] imagen, base de datos, migraciones y $([ "$MODE" = demo ] && echo "datos de demo ($DEMO_DATASET)" || echo "ajustes por rellenar"): omitido"
    fi
else
    if [ "$SOURCE" = build ]; then
        docker build -q -f "$REPO/docker/production/Dockerfile" \
            --build-arg "APP_VERSION=$TPV_VERSION" --build-arg "APP_COMMIT=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo local)" \
            -t "$TPV_IMAGE:$TPV_VERSION" "$REPO" >/dev/null
        ok "Imagen construida: $TPV_IMAGE:$TPV_VERSION"
    else
        printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "${GHCR_USER:-tpv}" --password-stdin >/dev/null
        compose pull -q
        ok "Imagen descargada: $TPV_IMAGE:$TPV_VERSION"
    fi
    if [ "$UPGRADE" = 1 ]; then
        # De $PREV_VERSION a $TPV_VERSION: copia cifrada, migraciones y vuelta atrás si algo falla.
        if [ "$SOURCE" = build ]; then /usr/local/bin/tpv-update --local "$TPV_VERSION"; else /usr/local/bin/tpv-update "$TPV_VERSION"; fi
        ok "Actualizado de $PREV_VERSION a $TPV_VERSION"
    fi
fi
if [ "$DRY_RUN" = 0 ] && [ "$UPGRADE" = 0 ]; then
    compose up -d pgsql
    until [ "$(docker inspect -f '{{.State.Health.Status}}' "$(compose ps -q pgsql)")" = healthy ]; do sleep 2; done
    # La base de datos puede venir de una instalación anterior (el volumen sobrevive a borrar
    # /opt/tpv) con otra contraseña: se pone la de este .env (dentro del contenedor, sin red).
    compose exec -T pgsql psql -q -v ON_ERROR_STOP=1 -U "$(envget "$ENV" DB_USERNAME)" -d postgres \
        -v pw="$(envget "$ENV" DB_PASSWORD)" >/dev/null <<'SQL'
ALTER USER CURRENT_USER PASSWORD :'pw';
SQL
    ok "Base de datos con la contraseña de este .env"
    if [ "$WIPE_DEMO" = 1 ]; then
        artisan migrate:fresh --force
        ok "Datos de demo borrados: numeración desde cero"
    else
        artisan migrate --force
    fi
    HAS_SETTINGS="$(artisan tinker --execute='echo App\Models\Setting::query()->exists() ? 1 : 0;' 2>/dev/null | tail -1)"
    if [ "$HAS_SETTINGS" = 1 ] && [ "$NEW_APP_KEY" = 1 ] && [ "$WIPE_DEMO" = 0 ]; then
        warn "La base de datos ya tenía datos y la APP_KEY es NUEVA: los datos cifrados (certificado, contraseñas de correo y copias) no se podrán leer. Si tienes la APP_KEY anterior, ponla en /opt/tpv/.env y en /root/tpv-credenciales.txt y relanza."
    fi
    if [ "$MODE" = demo ]; then
        if [ "$HAS_SETTINGS" != 1 ]; then
            compose run --rm --no-deps -T -e "DEMO_DATASET=$DEMO_DATASET" app php artisan db:seed --force
            ok "Datos de demo cargados: $DEMO_DATASET"
        else
            ok "Datos de demo ya cargados: sin cambios"
        fi
    else
        artisan tpv:setup --name="$LOCAL_NAME" --json >/dev/null
        ok "Ajustes preparados; el administrador se crea en /admin (primer acceso)"
    fi
    compose up -d
    ok "Servicios en marcha (arrancan solos con el equipo)"
fi

# Opcional (--config): apagar la pantalla de noche y actualizar solo.
if [ -n "${SCREEN_OFF:-}" ] && [ -n "${SCREEN_ON:-}" ]; then
    if [ "$KIOSK_MODE" = console ]; then
        put /etc/cron.d/tpv-pantalla 644 <<CONF
# Apagar y encender la pantalla del kiosko fuera del horario del local.
${SCREEN_OFF#*:} ${SCREEN_OFF%%:*} * * * $KIOSK_USER /usr/local/bin/tpv-kiosk --screen off
${SCREEN_ON#*:} ${SCREEN_ON%%:*} * * * $KIOSK_USER /usr/local/bin/tpv-kiosk --screen on
CONF
        ok "Pantalla: se apaga a las $SCREEN_OFF y se enciende a las $SCREEN_ON"
    else
        warn "SCREEN_OFF/SCREEN_ON solo funciona sin escritorio: con escritorio la pantalla queda siempre encendida."
    fi
else
    run rm -f "${ROOT}/etc/cron.d/tpv-pantalla"
fi
if [ -n "${AUTO_UPDATE_AT:-}" ] && [ "$SOURCE" = ghcr ]; then
    put /etc/cron.d/tpv-update 644 <<CONF
${AUTO_UPDATE_AT#*:} ${AUTO_UPDATE_AT%%:*} * * * root /usr/local/bin/tpv-update latest >> /var/log/tpv-update.log 2>&1
CONF
    ok "Actualización automática a las $AUTO_UPDATE_AT (solo si la caja está cerrada)"
else
    [ -n "${AUTO_UPDATE_AT:-}" ] && warn "AUTO_UPDATE_AT solo funciona con la imagen de GHCR (--ghcr)."
    run rm -f "${ROOT}/etc/cron.d/tpv-update"
fi

# --- 8. Credenciales ------------------------------------------------------------------------
step "Credenciales en /root/tpv-credenciales.txt (solo root)"
write_credentials
ok "Guardadas (600)"

# --- 9. Resumen -----------------------------------------------------------------------------
step "Listo"
echo "  Caja:          http://${LAN_IP}/"
echo "  Administración: http://${LAN_IP}/admin"
echo "  Credenciales:  /root/tpv-credenciales.txt (APP_KEY y claves: guárdalas en el gestor de contraseñas)"
[ "$MODE" = demo ] || echo "  Primer acceso:  abre /admin y crea el administrador y el encargado; después, «Puesta en marcha»."
echo "  Kiosko:        $([ "$KIOSK_MODE" = desktop ] && echo "escritorio ${KIOSK_DE:-?} (${KIOSK_DM:-sin gestor})" || echo "cage en tty1") · reinicia para que arranque"
if [ "${#NOTES[@]}" -gt 0 ]; then
    echo
    echo "  Avisos:"
    for n in "${NOTES[@]}"; do echo "   - $n"; done
fi
