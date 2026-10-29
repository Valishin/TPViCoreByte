# Impresoras USB en el mini PC (Debian)

El TPV imprime en impresoras térmicas ESC/POS por **red** (IP y puerto 9100) o
por **USB**. Por USB, la app escribe directamente en el dispositivo del
kernel (`/dev/usb/lp0`, `/dev/usb/lp1`…), que crea el driver **usblp**.

El problema de `/dev/usb/lp0` es que **el número puede cambiar** al reiniciar
o al reenchufar (la de cocina pasa a ser `lp0` y la de tickets `lp1`). Por eso
se crea con **udev** un enlace fijo con un nombre propio, p. ej.
`/dev/impresora-ticket`, que es el que se configura en /admin → Impresoras.

> En desarrollo, en el Mac, **no se pueden pasar dispositivos USB a Docker
> Desktop**. Allí se prueba en modo simulado (`PRINTER_MODE=simulated`).

---

## 1. Comprobar que el kernel la reconoce (driver usblp)

Enchufa la impresora y:

```bash
lsusb
```

Busca una línea como:

```
Bus 001 Device 005: ID 04b8:0202 Seiko Epson Corp. Receipt Printer M129C
```

`04b8` es el **vendor ID** y `0202` el **product ID**.

Comprueba que la usa el driver `usblp` y que existe el dispositivo:

```bash
lsusb -t                      # en su línea debe aparecer "Driver=usblp"
ls -l /dev/usb/lp*            # crw-rw---- 1 root lp 180, 0 … /dev/usb/lp0
sudo dmesg | grep -i usblp    # usblp 1-1:1.0: usblp0: USB Bidirectional printer dev …
```

Si no aparece `usblp`:

```bash
sudo modprobe usblp
echo usblp | sudo tee /etc/modules-load.d/usblp.conf   # cargarlo siempre al arrancar
```

> Algunas impresoras traen el módulo bloqueado por un paquete de CUPS
> (`/etc/modprobe.d/*blacklist*` con `blacklist usblp`). El TPV no usa CUPS:
> quita esa línea o no instales CUPS en el mini PC.

Prueba rápida desde el host (debe salir papel):

```bash
echo -e "Prueba TPV\n\n\n\x1dV\x00" | sudo tee /dev/usb/lp0 > /dev/null
```

## 2. Si tienes dos impresoras del mismo modelo: número de serie

Con dos impresoras idénticas, vendor y product coinciden. Distínguelas por el
número de serie:

```bash
udevadm info -a -n /dev/usb/lp0 | grep -E 'ATTRS\{(idVendor|idProduct|serial)\}' | head -3
```

Si no tienen número de serie, usa el puerto físico (`KERNELS=="1-1.2"`, lo
verás en la misma salida) y no cambies la impresora de puerto USB.

## 3. Regla udev con enlace fijo

Crea `/etc/udev/rules.d/99-impresoras-tpv.rules` (ajusta los IDs):

```udev
# Impresora de tickets (Epson TM-T20): /dev/impresora-ticket
SUBSYSTEM=="usbmisc", KERNEL=="lp*", ATTRS{idVendor}=="04b8", ATTRS{idProduct}=="0202", \
    SYMLINK+="impresora-ticket", GROUP="lp", MODE="0660"

# Impresora de cocina (otro modelo): /dev/impresora-cocina
SUBSYSTEM=="usbmisc", KERNEL=="lp*", ATTRS{idVendor}=="0416", ATTRS{idProduct}=="5011", \
    SYMLINK+="impresora-cocina", GROUP="lp", MODE="0660"

# Dos iguales: añade el número de serie a cada regla
#   ATTRS{serial}=="J2BF123456"
```

Aplica y comprueba:

```bash
sudo udevadm control --reload-rules
sudo udevadm trigger
ls -l /dev/impresora-*        # lrwxrwxrwx … /dev/impresora-ticket -> usb/lp0
```

Desenchufa y vuelve a enchufar: el enlace debe reaparecer.

## 4. Permisos

El dispositivo pertenece al grupo **`lp`** con permisos `0660`. Quien escriba
en él debe estar en ese grupo:

- **En el host** (pruebas a mano): `sudo usermod -aG lp $USER` y vuelve a
  entrar en la sesión.
- **En Docker**: los contenedores que imprimen (`printing` y `app`) llevan
  `group_add` con el **GID** del grupo `lp` del host (7 en Debian). Compruébalo:

  ```bash
  stat -c '%g %G' /dev/impresora-ticket    # 7 lp
  getent group lp                          # lp:x:7:
  ```

Si el TPV muestra *"Impresora no conectada o sin permiso de escritura en
/dev/impresora-ticket"*, casi siempre es esto (o que el contenedor se creó
antes de enchufar la impresora: `docker compose up -d --force-recreate printing app`).

## 5. Docker

Ver `docker-compose.prod.yml`. Los contenedores `printing` y `app` montan los
dispositivos:

```yaml
devices:
    - '/dev/impresora-ticket:/dev/impresora-ticket'
group_add:
    - '7'
```

Con `devices:` la impresora **tiene que estar enchufada al arrancar** el
contenedor. Si quieres poder enchufarla y desenchufarla en caliente, usa la
alternativa comentada en el compose (`device_cgroup_rules: ['c 180:* rmw']`
y montar `/dev/usb` y el enlace como volúmenes).

## 6. Configurarla en el TPV

En **/admin → Configuración → Impresoras**:

- **Conexión:** USB
- **Dispositivo USB:** `/dev/impresora-ticket`
- **Ancho del papel:** 80 mm (48 caracteres) o 58 mm (32)
- **Página de códigos:** 16 (WPC1252) para acentos, ñ y €

Y en `.env`: `PRINTER_MODE=real` (impresión real; cada impresora usa su
conexión, red o USB). Después: `docker compose restart printing`.

Pulsa **"Imprimir prueba"** en la fila de la impresora: sale la página de
prueba o un aviso con el error exacto.

## Problemas típicos

| Aviso en el TPV | Qué mirar |
|---|---|
| Impresora no conectada: no existe el dispositivo … | Cable, alimentación, `ls -l /dev/impresora-*`, regla udev, contenedor creado antes de enchufar (`--force-recreate`). |
| … sin permiso de escritura … | Grupo `lp` y `group_add` con el GID correcto. |
| Impresora USB sin respuesta en … | Impresora sin papel, con la tapa abierta o en error: se corta a los `PRINTER_TIMEOUT` segundos (3) y queda como envío fallido con *Reintentar* / *Enviar a otra*. |
| Caracteres raros (Ã±, â‚¬) | Página de códigos de la impresora (prueba 16, 19 o vacío). |
| Imprime bien en el host pero no en Docker | El contenedor no tiene el dispositivo: `docker compose exec printing ls -l /dev/impresora-ticket`. |
