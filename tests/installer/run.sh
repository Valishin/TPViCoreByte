#!/usr/bin/env bash
# Pruebas del instalador en un contenedor debian:13 (scripts/test-installer.sh):
# --dry-run con ROOT_PREFIX (los ficheros se escriben bajo una raíz de prueba y los
# comandos solo se muestran), sin escritorio y con GNOME, XFCE, KDE y uno no soportado.
# Siempre sin preguntas: stdin cerrado y un tiempo máximo.
set -u
SRC="${SRC:-/src}"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
ko() { FAIL=$((FAIL + 1)); printf '  \033[31m✗ %s\033[0m\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else ko "$1"; fi; }

# install <raíz> [opciones…] → salida en $OUT, código en $CODE
install() {
    local root="$1"; shift
    OUT="$(ROOT_PREFIX="$root" timeout 120 bash "$SRC/install.sh" --dry-run "$@" </dev/null 2>&1)"
    CODE=$?
}
fake_desktop() { # fake_desktop <raíz> <gestor> <sesión> [wayland|x]
    mkdir -p "$1/usr/sbin" "$1/usr/share/${4:-x}sessions" "$1/etc/gdm3"
    touch "$1/usr/sbin/$2"
    printf '[Desktop Entry]\nName=%s\n' "$3" > "$1/usr/share/${4:-x}sessions/$3.desktop"
    [ "$2" = gdm3 ] && printf '[daemon]\n#AutomaticLoginEnable = true\n' > "$1/etc/gdm3/daemon.conf"
    return 0
}

echo "▸ Sin escritorio"
R=/tmp/r-consola; rm -rf "$R"
install "$R"
check "termina sin preguntas (código 0)" '[ "$CODE" = 0 ]'
check "kiosko con cage en tty1" 'grep -q "cage en tty1" <<<"$OUT"'
check "inicio automático en tty1" '[ -f "$R/etc/systemd/system/getty@tty1.service.d/autologin.conf" ]'
check "sin autostart de escritorio" '[ ! -e "$R/home/kiosk/.config/autostart/tpv-kiosk.desktop" ]'
check "kiosk.env en modo consola" 'grep -q "KIOSK_MODE=console" "$R/etc/tpv/kiosk.env"'
check ".env en modo cliente con claves" 'grep -q "^TPV_INSTALL_MODE=cliente" "$R/opt/tpv/.env" && grep -qE "^APP_KEY=base64:.{40,}" "$R/opt/tpv/.env"'
check ".env solo de root (600)" '[ "$(stat -c %a "$R/opt/tpv/.env")" = 600 ]'
check "credenciales 600 con APP_KEY" '[ "$(stat -c %a "$R/root/tpv-credenciales.txt")" = 600 ] && grep -q "^APP_KEY=base64:" "$R/root/tpv-credenciales.txt"'
check "la consola no muestra claves" '! grep -qE "APP_KEY=|DB_PASSWORD=|REVERB_APP_SECRET=" <<<"$OUT"'
check "regla udev genérica" 'grep -q "impresora-usb-nombre %p" "$R/etc/udev/rules.d/99-impresoras-tpv.rules"'
check "servicio de Tailscale" '[ -f "$R/etc/systemd/system/tpv-tailscale.path" ] && grep -q "install -d -m 700 -o 1000" <<<"$OUT"'
check "no pide datos de empresa ni certificado" '! grep -qiE "NIF|certificado|régimen" <<<"$OUT"'
key1="$(grep ^APP_KEY= "$R/opt/tpv/.env")"
install "$R"
check "relanzar no cambia las claves" '[ "$(grep ^APP_KEY= "$R/opt/tpv/.env")" = "$key1" ]'
check "sin código de configuración" '! grep -qi "código de configuración" <<<"$OUT" && ! grep -qi "código" "$R/root/tpv-credenciales.txt" && grep -q "crea el administrador" <<<"$OUT"'

echo "▸ Reinstalar tras borrar /opt/tpv (la base de datos sigue en el volumen de Docker)"
db1="$(grep ^DB_PASSWORD= "$R/opt/tpv/.env")"
rm -rf "$R/opt/tpv"
install "$R"
check "recupera las mismas claves de las credenciales" '[ "$CODE" = 0 ] && grep -q "Claves recuperadas" <<<"$OUT" && [ "$(grep ^APP_KEY= "$R/opt/tpv/.env")" = "$key1" ] && [ "$(grep ^DB_PASSWORD= "$R/opt/tpv/.env")" = "$db1" ]'
check "las credenciales siguen con esa APP_KEY" 'grep -qxF "$key1" "$R/root/tpv-credenciales.txt" && [ -z "$(ls "$R"/root/tpv-credenciales.txt.anterior-* 2>/dev/null)" ]'
check "pone la contraseña del .env en la base de datos" 'grep -q "ALTER USER CURRENT_USER PASSWORD" "$SRC/scripts/install/install.sh"'
check "credenciales guardadas antes de construir o arrancar nada" '[ "$(grep -n "^write_credentials$" "$SRC/scripts/install/install.sh" | head -1 | cut -d: -f1)" -lt "$(grep -n "docker build" "$SRC/scripts/install/install.sh" | head -1 | cut -d: -f1)" ]'
sed -i 's/^APP_KEY=.*/APP_KEY=base64:otra-clave-de-antes/' "$R/root/tpv-credenciales.txt"
install "$R"
check "otra APP_KEY en las credenciales: se guarda aparte, no se pierde" 'grep -q "otra-clave-de-antes" "$R"/root/tpv-credenciales.txt.anterior-* && grep -qxF "$key1" "$R/root/tpv-credenciales.txt" && grep -q "tenían otra APP_KEY" <<<"$OUT"'
rm -f "$R"/root/tpv-credenciales.txt.anterior-*
R2=/tmp/r-sin-nada; rm -rf "$R2"
install "$R2"
check "sin .env ni credenciales: claves nuevas" '[ "$CODE" = 0 ] && ! grep -q "Claves recuperadas" <<<"$OUT" && grep -qE "^APP_KEY=base64:.{40,}" "$R2/opt/tpv/.env" && [ "$(grep ^APP_KEY= "$R2/opt/tpv/.env")" != "$key1" ] && grep -qE "^DB_PASSWORD=.{20,}" "$R2/root/tpv-credenciales.txt"'

echo "▸ Versión nueva y --config"
sed -i 's/^TPV_VERSION=.*/TPV_VERSION=0.9.0/' "$R/opt/tpv/.env"
install "$R"
check "versión nueva: pasa por tpv-update (copia y vuelta atrás)" 'grep -q "tpv-update de 0.9.0 a" <<<"$OUT" && grep -q "^TPV_VERSION=0.9.0" "$R/opt/tpv/.env"'
printf 'SCREEN_OFF="02:30"\nSCREEN_ON="09:00"\nAUTO_UPDATE_AT="04:30"\nLAN_IP="192.168.1.60"\n' > /tmp/tpv.conf
install /tmp/r-conf --config /tmp/tpv.conf
check "--config: IP, horario de pantalla y aviso de actualización solo con GHCR" 'grep -q "http://192.168.1.60/admin" <<<"$OUT" && grep -q "^30 02 \* \* \* kiosk" /tmp/r-conf/etc/cron.d/tpv-pantalla && grep -q "AUTO_UPDATE_AT solo funciona con la imagen de GHCR" <<<"$OUT"'

echo "▸ GNOME con GDM"
R=/tmp/r-gnome; rm -rf "$R"; fake_desktop "$R" gdm3 gnome wayland-
install "$R"
check "termina (código 0)" '[ "$CODE" = 0 ]'
check "detecta GNOME con gdm3" 'grep -q "escritorio GNOME con gdm3" <<<"$OUT"'
check "inicio automático en GDM" 'grep -q "^AutomaticLoginEnable = true" "$R/etc/gdm3/daemon.conf" && grep -q "^AutomaticLogin = kiosk" "$R/etc/gdm3/daemon.conf"'
check "una sola AutomaticLoginEnable" '[ "$(grep -c AutomaticLoginEnable "$R/etc/gdm3/daemon.conf")" = 1 ]'
check "autostart del kiosko" 'grep -q "Exec=/usr/local/bin/tpv-kiosk-session" "$R/home/kiosk/.config/autostart/tpv-kiosk.desktop"'
check "sin avisos de actualizaciones ni teclado del escritorio" 'grep -q "Hidden=true" "$R/home/kiosk/.config/autostart/org.gnome.Software.desktop" && grep -q "Hidden=true" "$R/home/kiosk/.config/autostart/onboard-autostart.desktop"'
check "no instala cage ni toca tty1" '! grep -q "install -y -qq cage" <<<"$OUT" && [ ! -e "$R/etc/systemd/system/getty@tty1.service.d/autologin.conf" ]'
check "sin suspensión" 'grep -q "systemctl mask sleep.target" <<<"$OUT"'
check "kiosk.env de escritorio" 'grep -q "KIOSK_MODE=desktop" "$R/etc/tpv/kiosk.env" && grep -q "KIOSK_DM=gdm3" "$R/etc/tpv/kiosk.env"'
install "$R"
check "relanzar no duplica la configuración de GDM" '[ "$(grep -c AutomaticLoginEnable "$R/etc/gdm3/daemon.conf")" = 1 ]'

echo "▸ XFCE con LightDM"
R=/tmp/r-xfce; rm -rf "$R"; fake_desktop "$R" lightdm xfce
install "$R"
check "inicio automático en LightDM con sesión xfce" 'grep -q "autologin-user=kiosk" "$R/etc/lightdm/lightdm.conf.d/50-tpv-kiosk.conf" && grep -q "autologin-session=xfce" "$R/etc/lightdm/lightdm.conf.d/50-tpv-kiosk.conf"'
check "grupo autologin" 'grep -q "usermod -aG autologin kiosk" <<<"$OUT"'

echo "▸ KDE con SDDM"
R=/tmp/r-kde; rm -rf "$R"; fake_desktop "$R" sddm plasma wayland-
install "$R"
check "inicio automático en SDDM" 'grep -q "User=kiosk" "$R/etc/sddm.conf.d/50-tpv-kiosk.conf" && grep -q "Session=plasma" "$R/etc/sddm.conf.d/50-tpv-kiosk.conf"'

echo "▸ Escritorio no soportado (MATE con LightDM)"
R=/tmp/r-mate; rm -rf "$R"; fake_desktop "$R" lightdm mate
install "$R"
check "no falla" '[ "$CODE" = 0 ]'
check "lo indica en el resumen" 'grep -q "Escritorio mate no soportado" <<<"$OUT"'

echo "▸ Demo y cambio de tipo"
R=/tmp/r-demo; rm -rf "$R"
install "$R" --demo=bar
check "demo con datos bar" '[ "$CODE" = 0 ] && grep -q "instalación «demo» (datos: bar)" <<<"$OUT" && grep -q "^TPV_INSTALL_MODE=demo" "$R/opt/tpv/.env"'
install "$R"
check "demo → cliente pide BORRAR" 'grep -q "pediría escribir BORRAR" <<<"$OUT"'
rm -rf "$R"; install "$R" --demo=bar; rm -rf "$R/opt/tpv"
install "$R"
check "…también si se borró /opt/tpv (el modo sale de las credenciales)" 'grep -q "pediría escribir BORRAR" <<<"$OUT"'
R=/tmp/r-consola
install "$R" --demo
check "cliente → demo no se permite" '[ "$CODE" != 0 ] && grep -q "no se convierte en demo" <<<"$OUT"'
install /tmp/r-ghcr --ghcr ghp_prueba
check "--ghcr descarga la imagen" 'grep -q "de GHCR" <<<"$OUT"'

echo "▸ Nombre de las impresoras USB (igual que /admin)"
S=/tmp/sys; rm -rf "$S"; mkdir -p "$S/devices/usb1/1-1/1-1:1.0/usbmisc/lp0"
printf '04B8\n' > "$S/devices/usb1/1-1/idVendor"; printf '0202\n' > "$S/devices/usb1/1-1/idProduct"; printf 'X5E8-012345\n' > "$S/devices/usb1/1-1/serial"
check "impresora-04b8-0202-x5e8012345" '[ "$(SYSFS=$S sh "$SRC/scripts/install/host/impresora-usb-nombre" /devices/usb1/1-1/1-1:1.0/usbmisc/lp0)" = impresora-04b8-0202-x5e8012345 ]'
rm "$S/devices/usb1/1-1/serial"
check "sin número de serie" '[ "$(SYSFS=$S sh "$SRC/scripts/install/host/impresora-usb-nombre" /devices/usb1/1-1/1-1:1.0/usbmisc/lp0)" = impresora-04b8-0202 ]'

echo "▸ Servicio de Tailscale (tailscale simulado, carpeta del usuario 1000)"
id -u tpvapp >/dev/null 2>&1 || useradd -u 1000 -M tpvapp
T=/tmp/ts; rm -rf "$T"; mkdir -p "$T/dir" "$T/bin" "$T/run"
chown 1000:1000 "$T/dir"; chmod 700 "$T/dir"
# tailscale simulado: anota con qué argumentos se le llama y qué clave lee del fichero.
cat > "$T/bin/tailscale" <<'TS'
#!/bin/sh
case "$1" in
    up)
        echo "$*" > /tmp/ts/argv
        for a in "$@"; do case "$a" in file:*) cat "${a#file:}" > /tmp/ts/key-leida ;; esac; done
        [ "$FAKE_TS" = lento ] && sleep 10
        [ "$FAKE_TS" = ok ] && exit 0
        echo "backend error: invalid key: tskey-auth-kAbc123CNTRL-xyz987 rejected" >&2; exit 1 ;;
    status) [ "$FAKE_TS" = ok ] && echo "100.64.1.2   tpv-bella   alex@   linux   -" && exit 0; echo "Logged out."; exit 1 ;;
    set) echo "$*" > /tmp/ts/set-argv; case "$*" in *--ssh=true*) touch /tmp/ts/ssh-on ;; *) rm -f /tmp/ts/ssh-on ;; esac ;;
    debug) if [ -e /tmp/ts/ssh-on ]; then echo '{ "RunSSH": true }'; else echo '{ "RunSSH": false }'; fi ;;
esac
TS
chmod +x "$T/bin/tailscale"
join() { # join <modo tailscale>
    rm -f "$T/argv" "$T/key-leida"
    FAKE_TS="$1" TAILSCALE_BIN="$T/bin/tailscale" TPV_TAILSCALE_DIR="$T/dir" TPV_APP_UID=1000 RUNTIME_DIRECTORY="$T/run" TPV_TAILSCALE_TIMEOUT=2 \
        bash "$SRC/scripts/install/host/tpv-tailscale-join"
}
key() { printf '%s' "$1" > "$T/dir/authkey"; chown 1000:1000 "$T/dir/authkey"; }
state() { sed -n 's/.*"state":"\([^"]*\)".*/\1/p' "$T/dir/status.json"; }

key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella"}'; join ok
check "conectado: borra la clave y deja el estado" '[ ! -e "$T/dir/authkey" ] && [ "$(state)" = conectado ] && grep -q "100.64.1.2" "$T/dir/status.json"'
check "status.json 600 y del usuario de la app" '[ "$(stat -c "%a %u" "$T/dir/status.json")" = "600 1000" ]'
check "la clave NO va en la línea de órdenes (ps)" '! grep -q tskey "$T/argv" && grep -q -- "--auth-key file:" "$T/argv"'
check "SSH de Tailscale desactivado por defecto (--ssh=false)" 'grep -q -- "--ssh=false" "$T/argv" && grep -q "\"ssh\":\"false\"" "$T/dir/status.json"'
check "tailscale lee la clave del fichero" '[ "$(cat "$T/key-leida")" = tskey-auth-kAbc123CNTRL-xyz987 ]'
check "la copia de la clave se borra al terminar" '[ ! -e "$T/run/authkey" ]'

key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella"}'; join mal
check "si falla: borra la clave y deja el error" '[ ! -e "$T/dir/authkey" ] && [ "$(state)" = error ] && grep -q "invalid key" "$T/dir/status.json"'
check "el error no repite la clave" '! grep -q tskey-auth "$T/dir/status.json" && grep -q "\[clave\]" "$T/dir/status.json" && [ ! -e "$T/run/authkey" ]'

key '{"authkey":"tskey-auth-kAbc12'; join ok
check "clave cortada: error y no se llama a tailscale" '[ "$(state)" = error ] && grep -q "llegó cortada" "$T/dir/status.json" && [ ! -e "$T/argv" ] && [ ! -e "$T/dir/authkey" ]'
key 'basura $(touch /tmp/pwned) `id`'; join ok
check "contenido corrupto: error, sin ejecutar nada" '[ "$(state)" = error ] && [ ! -e /tmp/pwned ] && [ ! -e "$T/argv" ]'
key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"x; rm -rf /"}'; join ok
check "nombre de equipo raro → tpv" 'grep -q -- "--hostname tpv " "$T/argv"'

setpriv --reuid=1000 --regid=1000 --clear-groups mkfifo "$T/dir/authkey"
timeout 30 bash -c "$(declare -f join); T=$T SRC=$SRC; join ok"; code=$?
check "una tubería en vez de fichero no cuelga el servicio" '[ "$code" != 124 ] && [ ! -e "$T/dir/authkey" ] && [ "$(state)" = error ]'

echo "secreto-de-root" > /tmp/solo-root; chmod 600 /tmp/solo-root
setpriv --reuid=1000 --regid=1000 --clear-groups ln -s /tmp/solo-root "$T/dir/authkey"
join ok
check "un enlace a un fichero de root no se lee" '! grep -q secreto "$T/dir/status.json" && [ "$(state)" = error ] && [ ! -e "$T/argv" ]'
rm -f "$T/dir/status.json"
setpriv --reuid=1000 --regid=1000 --clear-groups ln -s /tmp/solo-root "$T/dir/status.json"
key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella"}'; join ok
check "un enlace en status.json no hace escribir a root fuera de la carpeta" '[ "$(cat /tmp/solo-root)" = secreto-de-root ] && [ ! -L "$T/dir/status.json" ] && [ "$(state)" = conectado ]'

key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella"}'; join lento
check "tailscale colgado: se corta y lo explica" '[ "$(state)" = error ] && grep -q "no ha respondido" "$T/dir/status.json" && [ ! -e "$T/run/authkey" ]'

FAKE_TS=ok TAILSCALE_BIN="$T/bin/tailscale" TPV_TAILSCALE_DIR="$T/dir" TPV_APP_UID=1000 bash "$SRC/scripts/install/host/tpv-tailscale-join" --status
check "--status refresca el estado" '[ "$(state)" = conectado ]'

key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella","ssh":true}'; join ok
check "SSH solo si /admin lo pide (--ssh=true)" 'grep -q -- "--ssh=true" "$T/argv"'
key '{"authkey":"tskey-auth-kAbc123CNTRL-xyz987","hostname":"tpv-bella","ssh":"sí"}'; join ok
check "cualquier otro valor de ssh → desactivado" 'grep -q -- "--ssh=false" "$T/argv"'
printf '{"ssh":true}' > "$T/dir/ssh-request"; chown 1000:1000 "$T/dir/ssh-request"; join ok
check "ssh-request: tailscale set --ssh=true, se borra y el estado lo refleja" 'grep -q -- "--ssh=true" "$T/set-argv" && [ ! -e "$T/dir/ssh-request" ] && grep -q "\"ssh\":\"true\"" "$T/dir/status.json"'
printf '{"ssh":false}' > "$T/dir/ssh-request"; chown 1000:1000 "$T/dir/ssh-request"; join ok
check "ssh-request: quitar el SSH" 'grep -q -- "--ssh=false" "$T/set-argv" && grep -q "\"ssh\":\"false\"" "$T/dir/status.json"'
printf 'basura' > "$T/dir/ssh-request"; chown 1000:1000 "$T/dir/ssh-request"; rm -f "$T/set-argv"; join ok
check "ssh-request corrupto: no cambia nada" '[ ! -e "$T/set-argv" ] && [ ! -e "$T/dir/ssh-request" ]'

echo
echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
