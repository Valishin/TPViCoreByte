#!/usr/bin/env bash
# Dentro del contenedor Debian 13 con systemd como PID 1 y el tailscale real
# (scripts/test-systemd-units.sh): instala el servicio de Tailscale como install.sh y lo
# prueba con systemd de verdad. La clave es falsa (bien formada): el servidor de
# Tailscale la rechaza, lo que comprueba el camino completo sin unir ningún equipo.
set -u
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
ko() { FAIL=$((FAIL + 1)); printf '  \033[31m✗ %s\033[0m\n' "$1"; }
check() { if eval "$2"; then ok "$1"; else ko "$1"; fi; }
D=/opt/tpv/host/tailscale
drop() { setpriv --reuid=1000 --regid=1000 --clear-groups sh -c 'umask 077; printf %s "$3" > "$1/.t" && mv "$1/.t" "$1/$2"' sh "$D" "$1" "$2"; }
waitsvc() { for _ in $(seq 1 60); do sleep 0.5; [ "$(systemctl show -p ActiveState --value tpv-tailscale.service)" != activating ] && [ ! -e "$D/$1" ] && break; done; sleep 0.5; }
state() { sed -n 's/.*"state":"\([^"]*\)".*/\1/p' "$D/status.json"; }
KEY='{"authkey":"tskey-auth-kNoValidaCNTRL-abcdefghij1234567890","hostname":"tpv-prueba","ssh":false}'

until [ "$(systemctl is-system-running 2>/dev/null)" = running ] || [ "$(systemctl is-system-running 2>/dev/null)" = degraded ]; do sleep 1; done
install -m 755 /src/scripts/install/host/tpv-tailscale-join /usr/local/sbin/tpv-tailscale-join
for u in tpv-tailscale.path tpv-tailscale.service tpv-tailscale-status.service tpv-tailscale-status.timer; do install -m 644 "/src/scripts/install/systemd/$u" "/etc/systemd/system/$u"; done
install -d -m 755 /opt/tpv/host; install -d -m 700 -o 1000 -g 1000 "$D"
systemctl daemon-reload
check "systemd-analyze verify sin errores" 'systemd-analyze verify /etc/systemd/system/tpv-tailscale.path /etc/systemd/system/tpv-tailscale.service /etc/systemd/system/tpv-tailscale-status.service /etc/systemd/system/tpv-tailscale-status.timer'
systemctl enable --now tailscaled tpv-tailscale.path tpv-tailscale-status.timer >/dev/null 2>&1
check "tailscaled, .path y temporizador activos" '[ "$(systemctl is-active tailscaled tpv-tailscale.path tpv-tailscale-status.timer | sort -u)" = active ]'

( for _ in $(seq 1 100); do ps -eo comm=,args= | awk '$1 ~ /tailscale|tpv-tailscale/' >> /tmp/ps.log; sleep 0.1; done ) &
drop authkey "$KEY"; waitsvc authkey; wait
check "el .path lanza el servicio: error del servidor real en status.json" '[ "$(state)" = error ] && grep -q "invalid key" "$D/status.json"'
check "tailscale up --ssh=false --auth-key file:… (la clave no sale en ps)" 'grep -q -- "--ssh=false" /tmp/ps.log && grep -q "auth-key file:/run/tpv-tailscale/authkey" /tmp/ps.log && ! grep -q tskey-auth /tmp/ps.log'
check "clave borrada de la carpeta y /run/tpv-tailscale borrado por systemd" '[ ! -e "$D/authkey" ] && [ ! -e /run/tpv-tailscale ]'
check "status.json 600 del usuario de la app" '[ "$(stat -c "%a %u" "$D/status.json")" = "600 1000" ]'
drop ssh-request '{"ssh":true}'; waitsvc ssh-request
check "ssh-request true → RunSSH true (tailscale set real)" 'tailscale debug prefs | grep -q "\"RunSSH\": true"'
drop ssh-request '{"ssh":false}'; waitsvc ssh-request
check "ssh-request false → RunSSH false" 'tailscale debug prefs | grep -q "\"RunSSH\": false"'
rm -f "$D/status.json"; systemctl start tpv-tailscale-status.service
check "servicio del temporizador: estado desconectado" '[ "$(state)" = desconectado ]'
for i in 1 2 3 4 5 6; do drop authkey "basura-$i"; waitsvc authkey; done
check "6 claves corruptas seguidas: .path sigue activo, ninguna clave queda" '[ "$(systemctl is-active tpv-tailscale.path)" = active ] && [ ! -e "$D/authkey" ] && [ "$(state)" = error ]'
check "sin errores de seccomp ni permisos" '! journalctl -u tpv-tailscale.service -u tpv-tailscale-status.service --no-pager -o cat | grep -qiE "bad system call|permission denied"'
check "exposición (systemd-analyze security) ≤ 2.5" 'systemd-analyze security tpv-tailscale.service --no-pager 2>/dev/null | tail -1 | grep -Eq "level for tpv-tailscale.service: (0|1|2)\.[0-9]"'

printf '#!/bin/sh\n[ "$1" = up ] && sleep 15\nexec /usr/bin/tailscale "$@"\n' > /usr/local/bin/ts-lento; chmod 755 /usr/local/bin/ts-lento
mkdir -p /etc/systemd/system/tpv-tailscale.service.d
printf '[Service]\nEnvironment=TAILSCALE_BIN=/usr/local/bin/ts-lento\n' > /etc/systemd/system/tpv-tailscale.service.d/lento.conf
systemctl daemon-reload; drop authkey "$KEY"; sleep 4
check "durante: «conectando», copia 600 en /run/tpv-tailscale 700 de root" '[ "$(state)" = conectando ] && [ "$(stat -c "%a %U" /run/tpv-tailscale/authkey)" = "600 root" ] && [ "$(stat -c %a /run/tpv-tailscale)" = 700 ]'
systemctl kill -s KILL tpv-tailscale.service; sleep 2
check "SIGKILL a mitad: systemd borra la copia; la carpeta sin clave; .path activo" '[ ! -e /run/tpv-tailscale ] && [ ! -e "$D/authkey" ] && [ "$(systemctl is-active tpv-tailscale.path)" = active ]'
systemctl start tpv-tailscale-status.service
check "tras SIGKILL, el temporizador corrige «conectando»" '[ "$(state)" = desconectado ]'
rm -rf /etc/systemd/system/tpv-tailscale.service.d; systemctl daemon-reload

echo; echo "Resultado: $PASS correctas, $FAIL fallidas"
[ "$FAIL" = 0 ]
