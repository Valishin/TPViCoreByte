# Impresoras USB en el mini PC (Debian)

El TPV imprime en impresoras térmicas ESC/POS por **red** (IP y puerto 9100) o
por **USB**. Por USB, la app escribe directamente en el dispositivo del
kernel (`/dev/usb/lp0`, `/dev/usb/lp1`…), que crea el driver **usblp**.

El problema de `/dev/usb/lp0` es que **el número puede cambiar** al reiniciar
o al reenchufar (la de cocina pasa a ser `lp0` y la de tickets `lp1`). Por eso
se crea con **udev** un enlace fijo dentro de `/dev/usb/`, p. ej.
`/dev/usb/impresora-04b8-0202-x5e8012345`, que es el que se elige en /admin →
Impresoras. **El instalador (`install.sh`) instala la regla genérica** para
cualquier impresora USB; lo de abajo es para entenderlo o comprobarlo a mano.

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

`install.sh` instala una regla genérica para **cualquier** impresora USB (driver
usblp): no hace falta apuntar IDs ni volver a lanzar el instalador al cambiar de
impresora.

```
# /etc/udev/rules.d/99-impresoras-tpv.rules
SUBSYSTEM=="usbmisc", KERNEL=="lp*", GROUP="lp", MODE="0660", PROGRAM="/usr/local/lib/tpv/impresora-usb-nombre %p", SYMLINK+="usb/%c"
```

El enlace es `/dev/usb/impresora-<fabricante>-<modelo>[-<serie>]`, p. ej.
`/dev/usb/impresora-04b8-0202-x5e8012345` (Epson TM-T20III con su número de
serie). Con el número de serie, **dos impresoras del mismo modelo** tienen
enlaces distintos; sin él (algunas no lo tienen), solo fabricante y modelo.
/admin calcula el mismo nombre leyendo `/sys`: en **Impresoras → Nueva → USB →
«Impresoras USB conectadas»** aparecen las enchufadas para elegirlas.

Comprobar: `ls -l /dev/usb/` → `impresora-04b8-0202-x5e8012345 -> lp0`.

## 4. Permisos

El dispositivo pertenece al grupo **`lp`** con permisos `0660`. Quien escriba
en él debe estar en ese grupo:

- **En el host** (pruebas a mano): `sudo usermod -aG lp $USER` y vuelve a
  entrar en la sesión.
- **En Docker**: el contenedor que imprime (`printing`) lleva
  `group_add` con el **GID** del grupo `lp` del host (7 en Debian). Compruébalo:

  ```bash
  stat -c '%g %G' /dev/usb/lp0             # 7 lp
  getent group lp                          # lp:x:7:
  ```

Si el TPV muestra *"Impresora no conectada o sin permiso de escritura en
/dev/usb/impresora-04b8-0202"*, casi siempre es esto.

## 5. Docker

Solo el contenedor `printing` (el worker de la cola de impresión) toca las
impresoras; las peticiones web nunca escriben en ellas. `install.sh` genera
`/opt/tpv/compose.override.yaml`:

```yaml
services:
    printing:
        device_cgroup_rules: ['c 180:* rmw']   # solo usblp (major 180)
        volumes:
            - '/dev/usb:/dev/usb'              # incluye los enlaces impresora-*
        group_add:
            - '7'                              # grupo lp del host
```

Al montar el directorio `/dev/usb` (y no cada dispositivo), **se puede enchufar
y desenchufar la impresora en caliente** sin recrear el contenedor: el enlace
aparece y desaparece dentro. El enlace es relativo (`impresora-ticket -> lp0`),
así que funciona igual dentro del contenedor.

## 6. Configurarla en el TPV

En **/admin → Configuración → Impresoras**:

- **Conexión:** USB
- **Dispositivo USB:** elígelo en «Impresoras USB conectadas» (rellena `/dev/usb/impresora-…`)
- **Ancho del papel:** 80 mm (48 caracteres) o 58 mm (32)
- **Página de códigos:** 16 (WPC1252) para acentos, ñ y €

En producción `PRINTER_MODE=real` ya viene en el `.env` (cada impresora usa su
conexión, red o USB).

Pulsa **"Imprimir prueba"** en la fila de la impresora: se manda a la cola
`printing` y /admin espera el resultado como mucho 5 s (`PRINTER_WEB_WAIT`).
Sale la página de prueba, un aviso con el error exacto o "sin respuesta
todavía" si el worker `printing` está parado o la impresora no contesta.

La escritura en el dispositivo es **no bloqueante** (`stream_select` con
plazo), así que una impresora atascada nunca deja colgado al worker ni a una
petición web, con o sin pcntl.

## Problemas típicos

| Aviso en el TPV | Qué mirar |
|---|---|
| Impresora no conectada: no existe el dispositivo … | Cable, alimentación, `ls -l /dev/usb/`, regla udev (`udevadm test`), módulo `usblp`. |
| … sin permiso de escritura … | Grupo `lp` y `group_add` con el GID correcto. |
| Impresora USB sin respuesta en … | Impresora sin papel, con la tapa abierta o en error: se corta si pasa `PRINTER_USB_IDLE_TIMEOUT` segundos (3) **sin aceptar nada** o si el ticket supera `PRINTER_USB_MAX_SECONDS` (15), y queda como envío fallido con *Reintentar* / *Enviar a otra*. |
| … a mitad del ticket | Ya había salido una parte: **no se reintenta solo** (evita tickets duplicados). Mira qué ha salido antes de pulsar *Reintentar*. |
| Sale el ticket sin el final o sin cortar | Corregido: antes se cerraba el dispositivo con el último trozo aún en camino y el driver `usblp` lo descartaba. Ahora se espera a que lo termine de enviar. |
| Caracteres raros (Ã±, â‚¬) | Página de códigos de la impresora (prueba 16, 19 o vacío). |
| Imprime bien en el host pero no en Docker | `docker compose exec printing ls -l /dev/usb/` y que exista `compose.override.yaml`. |
