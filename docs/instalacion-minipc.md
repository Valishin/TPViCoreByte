# Instalación en el mini PC (producción)

Del USB de Debian al TPV funcionando en la pantalla táctil. Para cada cliente,
usa además [checklist-instalacion.md](checklist-instalacion.md).

**Equipo previsto:** mini PC Intel N100/N150, 8-16 GB, **disco SSD** (SATA o
NVMe; no eMMC ni HDD: la base de datos escribe a menudo), pantalla táctil
(HDMI + USB), impresoras de red y/o USB, SAI opcional ([sai-nut.md](sai-nut.md)).

**El día de la instalación:** copiar la carpeta del proyecto al mini PC,
`sudo bash install.sh`, esperar, y abrir `/admin`. El instalador no pregunta
nada: la empresa, el NIF, el régimen, las impresoras, las copias, Tailscale y
VERI\*FACTU se configuran después en /admin con el asistente **Puesta en marcha**.

**Paquetes de Debian 13 que usa:** chromium, ufw, unattended-upgrades,
systemd-timesyncd; cage y wlr-randr solo sin escritorio; nut (SAI); Docker CE
del repositorio de Docker; Tailscale de su repositorio. age, rclone y ssh-keyscan
van dentro de la imagen del TPV.

**Qué se instala:** Debian 13 (trixie), **con o sin escritorio** · Docker · el
TPV en contenedores (`/opt/tpv`) · kiosko (usuario `kiosk`: Chromium a pantalla
completa; sin escritorio con cage en tty1, con escritorio con inicio de sesión
automático) · Tailscale (se une desde /admin) · copias de seguridad cifradas.

---

## 0. Antes de ir al local (en tu Mac)

1. **Carpeta del proyecto** en un pendrive: `scripts/make-install-bundle.sh` →
   `dist/tpv-<versión>.tar.gz` (el código tal cual está en git; la imagen se
   construye en el mini PC). También vale copiar la carpeta del repositorio.
2. (Opcional) **Imagen en GHCR** si prefieres descargarla en vez de construirla:
   `TPV_IMAGE=ghcr.io/<usuario>/tpv-hosteleria scripts/build-image.sh --push` y un
   **token de solo lectura** para el mini PC (ver [Tokens de GitHub](#tokens-de-github-y-ghcr)).
3. **Claves de las copias (age)** — una vez, en tu Mac (ver [§9](#9-copias-de-seguridad)).
4. (Opcional) **Clave de Tailscale** de un solo uso: admin.tailscale.com → Settings → Keys
   → *Generate auth key* (reusable: no; ephemeral: no; con *tag* `tag:tpv`).
5. El **certificado electrónico** del cliente (`.p12`/`.pfx`) si ya lo tiene: se
   sube en /admin cuando quieras (no hace falta para instalar).
6. **USB de instalación de Debian 13** (`debian-13.x.x-amd64-netinst.iso` de
   debian.org, grabada con balenaEtcher o `dd`).

### Tokens de GitHub y GHCR

GHCR (el registro de imágenes de GitHub) solo acepta **tokens clásicos**
(*Personal access tokens (classic)*); los *fine-grained* no sirven para
paquetes. Se crean en GitHub → Settings → Developer settings → Personal
access tokens → **Tokens (classic)** → *Generate new token (classic)*.

| Para qué | Dónde | Permisos (scopes) | Caducidad |
|---|---|---|---|
| **Subir la imagen** desde el Mac | tu Mac (`docker login`) | `write:packages` (incluye `read:packages`) | 90 días, y renovar |
| **Descargar la imagen** en el mini PC | `sudo bash install.sh --ghcr TOKEN` | **solo `read:packages`** | sin caducidad o 1 año; **uno por cliente** |

```bash
# En el Mac, una vez (y al renovar el token):
echo 'ghp_…write…' | docker login ghcr.io -u <usuario> --password-stdin
scripts/build-image.sh --push
```

- El token del mini PC queda en `/root/.docker/config.json` (solo root). Con uno
  por cliente, si un equipo se pierde o se roba revocas solo ese.
- Un token clásico da acceso de lectura a **todos** tus paquetes. Si quieres
  aislarlo del todo: crea una cuenta de GitHub aparte («tpv-descargas»), dale
  acceso de lectura solo al paquete `tpv-hosteleria` (Package settings →
  Manage access) y crea el token `read:packages` en esa cuenta.
- La imagen va etiquetada con la URL del repositorio
  (`org.opencontainers.image.source`): GHCR la enlaza al repositorio privado y
  hereda su visibilidad (privada).

## 1. BIOS / UEFI del mini PC

Entra con `Supr` o `F2` al encender:

- **Restore on AC Power Loss / After Power Failure → Power On** ("encender al
  recibir corriente"): tras un corte de luz, arranca solo. Imprescindible.
- **Arranque**: primero el disco interno (después de instalar).
- **Suspensión / ErP / Deep sleep**: desactivados.
- Fecha y hora correctas (luego las mantiene NTP).
- (Opcional) Wake-on-LAN activado para el soporte remoto.

## 2. Instalar Debian 13

Arranca desde el USB (`F7`/`F11`/`F12` según la marca) → *Install* (no la
gráfica):

- Idioma español, país España, teclado español.
- **Nombre de equipo**: `tpv-<local>` (p. ej. `tpv-bella-napoli`).
- **Usuario root**: pon contraseña (guárdala en tu gestor de contraseñas).
- Usuario normal: `soporte`.
- Disco: *guiado, todo el disco* (una sola partición).
- **Selección de programas**: **servidor SSH** y **utilidades estándar**. El
  escritorio es opcional: sin él, el kiosko va con cage en tty1; si el equipo
  ya tiene escritorio (GNOME, XFCE o KDE) se queda como está y el kiosko arranca
  con inicio de sesión automático del usuario `kiosk`.
- GRUB en el disco interno. Reinicia y quita el USB.
- Comprueba que el disco es un SSD: `lsblk -d -o NAME,ROTA,TRAN,SIZE` → `ROTA 0`
  y `TRAN sata` o `nvme` (un `mmcblk0` es eMMC: no sirve). El instalador activa
  `fstrim.timer` (TRIM semanal).

## 3. Red: IP fija reservada en el router

El TPV tiene que estar siempre en la misma IP (los móviles y las impresoras
la usan):

1. En el mini PC: `ip -br addr` → apunta la IP y la **MAC** de la interfaz
   cableada (`enp1s0` o parecida). Usa **cable**, no wifi.
2. En el router del local: *DHCP → Reserva de IP / Static lease* → asocia esa MAC
   a una IP fuera del rango habitual, p. ej. **192.168.1.10**. Reinicia la
   conexión (`sudo systemctl restart networking`) y comprueba que la tiene.
3. Reserva también las IP de las **impresoras de red** (o fíjalas en su menú) y
   apúntalas.

> El TPV no necesita abrir puertos en el router: el soporte remoto va por
> Tailscale.

## 4. Lanzar el instalador

Copia la carpeta al mini PC (pendrive o `scp dist/tpv-1.0.0.tar.gz soporte@192.168.1.10:`) y:

```bash
ssh soporte@192.168.1.10          # o en el propio equipo
tar xzf tpv-1.0.0.tar.gz && cd tpv-1.0.0
sudo bash install.sh              # funciona aunque haya perdido el permiso de ejecución
```

**Sin preguntas.** Por defecto: cliente real, imagen construida con el código
copiado, IP detectada y kiosko detectado. Opciones:

| Opción | Para qué |
|---|---|
| `--demo[=bar\|pizzeria\|pizzeria_mixta]` | demo con datos de ejemplo (por defecto `pizzeria_mixta`); VERI\*FACTU apagado |
| `--ghcr TOKEN` | descargar la imagen de GHCR en vez de construirla |
| `--config FICHERO` | valores de un fichero (instalaciones automáticas; ver `scripts/install/tpv-install.conf.example`: IP, nombre provisional, horario de pantalla, actualización automática…) |
| `--dry-run` | solo enseña lo que haría |

Qué hace (se puede volver a lanzar sin perder datos ni cambiar claves):

1. **Sistema**: Europe/Madrid con NTP, actualizaciones de seguridad automáticas
   (sin reinicios), TRIM semanal, sin suspensión; cortafuegos **ufw** (SSH, 80 y Tailscale).
2. **Docker CE** con arranque automático y logs rotados.
3. **Kiosko** (usuario `kiosk` sin contraseña ni sudo; Chromium en
   `http://localhost/?kiosk=1` sin barras, traducción, «restaurar páginas» ni
   pellizcar; si se cierra o se cuelga, vuelve a abrirse):
   - **Sin escritorio**: cage + Chromium en tty1 con autologin.
   - **Con escritorio** (detecta GDM, LightDM o SDDM y GNOME, XFCE o KDE): no
     instala cage ni toca tty1; inicio de sesión automático de `kiosk`, Chromium
     desde `~/.config/autostart` y sesión **blindada**: sin bloqueo de pantalla,
     salvapantallas, apagado de pantalla, suspensión, notificaciones, avisos de
     actualizaciones ni teclado en pantalla del escritorio (el TPV tiene el suyo).
     Un escritorio que no sabe blindar lo dice en el resumen (no falla).
4. **Impresoras USB**: regla udev para cualquier impresora →
   `/dev/usb/impresora-<fabricante>-<modelo>[-<serie>]` (se eligen en /admin).
5. **Tailscale** instalado (sin unir) y el servicio que recoge la clave desde /admin.
6. **TPV** en `/opt/tpv`: `.env` con claves nuevas (APP_KEY, base de datos,
   Reverb, número de instalación de VERI\*FACTU), imagen, migraciones y ajustes
   por rellenar (o los datos de demo). Una **versión nueva** sobre una instalación
   con datos pasa por `tpv-update` (copia y vuelta atrás automática).
7. **Credenciales** en `/root/tpv-credenciales.txt` (600): APP_KEY, claves de la
   base de datos y de Reverb y número de instalación. Se escriben en cuanto se
   crea el `.env`, antes de construir o arrancar nada (si algo falla después,
   las claves ya están guardadas). En la consola solo salen las URL y la ruta
   de ese fichero.

> **Reinstalar tras borrar `/opt/tpv`:** la base de datos vive en un volumen de
> Docker que no se borra con la carpeta. `install.sh` recupera las mismas claves
> de `/root/tpv-credenciales.txt` y pone la contraseña del `.env` en la base de
> datos, así que vuelve a funcionar con los datos de antes. Si tampoco están las
> credenciales, genera claves nuevas y avisa si la base de datos ya tenía datos
> (lo cifrado con la APP_KEY anterior no se podrá leer). Si las credenciales
> tenían otra APP_KEY, el fichero anterior se guarda como
> `tpv-credenciales.txt.anterior-<fecha>`. Para empezar de cero de verdad:
> `docker compose -f /opt/tpv/compose.yaml down -v` antes de borrar la carpeta.

> **Demo → cliente real en el mismo equipo:** `sudo bash install.sh` (sin
> `--demo`) pide escribir **BORRAR**: se borran los datos de demo y la numeración
> empieza de cero. Un cliente real no se convierte en demo.

> **Puerto 80 ocupado** («failed to bind host port 0.0.0.0:80 … address already
> in use»): el TPV necesita el 80. El instalador lo comprueba al empezar y dice
> qué lo ocupa. Un servidor web (Apache, Nginx, Lighttpd, Caddy): desde una
> terminal pregunta si lo para y desactiva (o `sudo systemctl disable --now
> apache2`). Otro contenedor de Docker: `sudo docker update --restart=no NOMBRE
> && sudo docker stop NOMBRE`. Para verlo a mano: `sudo ss -ltnp 'sport = :80'` y
> `sudo docker ps --filter publish=80`.

## 5. Primer acceso y Puesta en marcha (/admin)

1. Abre `http://<IP>/admin` desde la caja, la red local o Tailscale: sale
   **Crear administrador**: tu email y contraseña, y el **encargado** con su
   email y PIN (autoriza en la caja). Hazlo nada más instalar: mientras no hay
   ningún usuario, cualquiera de la red local o de Tailscale podría crearlo.
   Después la pantalla desaparece.
2. Se abre **Puesta en marcha** (7 pasos; cada uno se guarda al pulsar
   «Siguiente» y se puede dejar a medias; el panel muestra «X de 7 pasos»):
   1. **Datos de la empresa**: nombre comercial, razón social, NIF, dirección, logo y pie de ticket.
   2. **Fiscal**: régimen de IVA (**confírmalo con el cliente o su gestor**) y si
      declara Sociedades → VERI\*FACTU obligatorio el 01/01/2027 (sí) o el 01/07/2027 (no).
   3. **Tipos de pedido** activos.
   4. **Impresoras**: las USB conectadas aparecen solas; da de alta al menos la de
      tickets y pulsa «Imprimir prueba».
   5. **Copias de seguridad**: clave pública age y destino (S3 compatible o SFTP;
      en SFTP, «Generar clave SSH», «Obtener huella del servidor») y «Probar copia».
   6. **Acceso remoto** (opcional): clave de Tailscale o «Omitir».
   7. **Certificado y VERI\*FACTU**: sube el `.p12`/`.pfx` en /admin → VERI\*FACTU
      y elige «Activar ahora» o «Activar automáticamente en la fecha de obligación».
3. **En «cliente», sin los pasos 1 y 2 no se emiten tickets ni facturas** (la caja
   lo avisa). Sin certificado: aviso en /admin siempre y en la caja desde 30 días
   antes de la fecha; desde la fecha, sin certificado o sin VERI\*FACTU activo, no
   se emite. En demo se vende con los datos de ejemplo.
4. **Usuarios** (camareros con PIN), **carta** y **sala** en /admin como siempre.
5. **Guarda en el gestor de contraseñas**, por cliente: la APP_KEY (con ella se
   descifran en las copias el certificado, la contraseña SMTP y las claves de las
   copias), la clave privada age y el certificado con su contraseña. Después:
   `sudo shred -u /root/tpv-credenciales.txt`.
6. **VERI\*FACTU activo**: venta de prueba (un café): el ticket sale con el QR y en
   la tabla de registros pasa a «Correcto» en un minuto; escanea el QR con el móvil.

TPV ↔ /admin: en la caja, **⚙ → Administración** lleva al login de /admin; en
/admin, **Ir a la TPV** (cabecera) vuelve a la caja. Cada uno tiene su sesión:
salir de uno no cierra el otro.

## 6. Impresoras

- **De red**: IP fija, puerto 9100. Prueba desde el mini PC:
  `printf 'Prueba\n\n\n\x1dV\x00' | nc -w 3 192.168.1.203 9100`.
- **USB**: enchúfala y enciéndela → /admin → Impresoras → Nueva → Conexión USB →
  elígela en «Impresoras USB conectadas». El enlace no cambia aunque reinicies o la
  cambies de puerto. Problemas típicos en [impresoras-usb.md](impresoras-usb.md).

## 7. Móviles de los camareros

Misma wifi que el mini PC → `http://192.168.1.10` (la dirección aparece abajo
a la derecha en la caja). En Android/iOS: *Añadir a pantalla de inicio*.

## 8. Soporte remoto (Tailscale)

Sin abrir puertos en el router del cliente.

- **Unir el equipo**: /admin → Puesta en marcha → Acceso remoto → pega una clave
  de un solo uso. La app la deja en `/opt/tpv/host/tailscale` (700, solo el
  usuario de la app y root); un servicio de root la recoge, **la borra** (también
  si falla), ejecuta `tailscale up --ssh=false` y /admin muestra «Conectado como …».
  Root solo ejecuta `tailscale`; lo que toca la carpeta compartida lo hace como el
  usuario de la app, y la clave nunca va en la línea de órdenes.
- **SSH**: por defecto, el SSH normal del equipo (tu usuario y su contraseña o
  clave) por la IP de Tailscale: `ssh soporte@tpv-<local>`. El **SSH de Tailscale**
  (`tailscale ssh`, sin contraseña del equipo, según las ACL de tu red Tailscale)
  está **desactivado**: solo se activa a mano en /admin → Acceso remoto → «Permitir
  acceso SSH por Tailscale», con un aviso de lo que implica y marcando «Entiendo lo
  que implica». Quítalo con «Quitar acceso SSH por Tailscale» cuando no haga falta.
- **/admin con HTTPS** (solo por Tailscale; en la red local es HTTP):
  `sudo tailscale serve --bg http://localhost:80` → `https://tpv-<local>.<tu-tailnet>.ts.net/admin`.
- En la consola de Tailscale, desactiva la caducidad de la clave del equipo
  (*Disable key expiry*) para que no se desconecte a los 180 días.

## 9. Copias de seguridad

**Qué se copia:** la base de datos completa (ventas, tickets, clientes…), con
`pg_dump`, cifrada con **age**, con rotación **7 diarias, 4 semanales y 6
mensuales** en el propio equipo y, si hay destino, subida con rclone.

**Cuándo:** justo después de **cada cierre Z**, **al arrancar** el equipo si la
última copia correcta tiene más de 24 h (para los locales que lo apagan por la
noche) y a las **05:00** (para los que no lo apagan). Antes de cada actualización.
Sin internet, la subida se reintenta cada hora. **/admin avisa** si la última
copia falla o si la del destino remoto tiene más de 48 h.

### Generar las claves (una vez, en tu Mac)

```bash
brew install age
age-keygen -o tpv-copias-age.txt
#   Public key: age1qyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqszqgpqyqs…
```

- La línea **`Public key: age1…`** es la que va en /admin → Copias de seguridad
  (puede ser la misma para todos tus clientes, o una por cliente).
- **El fichero `tpv-copias-age.txt` es la clave PRIVADA.** No va nunca al mini
  PC. Guárdala en **dos sitios seguros distintos** fuera de él (p. ej. tu gestor
  de contraseñas como nota segura + un USB cifrado guardado en otro lugar).
  **Si se pierde, las copias son irrecuperables**: nadie, ni tú, puede
  descifrarlas.

### Destino remoto

Las copias contienen **datos personales de clientes** (nombres, teléfonos,
direcciones): el destino debe estar **en la UE** (RGPD) y con contrato de
encargado del tratamiento si es un proveedor. Se configura en **/admin → Copias
de seguridad** (o en el paso 5 de Puesta en marcha):

- **S3 compatible** (proveedor europeo o un MinIO tuyo): endpoint, región, bucket,
  access key y secret key (cifrada en la base de datos). Usa credenciales que solo
  puedan escribir en ese bucket/carpeta.
- **Servidor SFTP**: servidor, puerto y usuario. **Generar clave SSH** crea la
  clave del equipo y enseña la pública para añadirla a `~/.ssh/authorized_keys`
  del servidor. **Obtener huella del servidor** lee su clave (ssh-keyscan) y la
  enseña para compararla con la del servidor
  (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`): al confirmarla, rclone
  **solo** se conecta a ese servidor.

**Probar copia** hace una copia real y la sube; el resultado aparece en la misma
pantalla. (Las instalaciones antiguas con `BACKUP_AGE_RECIPIENT`, `BACKUP_REMOTE`
y `RCLONE_CONFIG_*` en el `.env` siguen funcionando mientras /admin esté vacío.)

> **Qué hay que guardar fuera del mini PC, por cliente** (gestor de contraseñas):
> la clave privada age (dos sitios), la **APP_KEY** (`/opt/tpv/.env`) y, con
> VERI\*FACTU, el certificado digital. Sin la clave age no se descifran las
> copias; sin la APP_KEY, los datos cifrados que llevan dentro.

### Restaurar en el mini PC

Trae la clave privada en un USB y bórrala después:

```bash
docker compose -f /opt/tpv/compose.yaml cp scheduler:/backups/tpv-20261104-230000-cierre_z.dump.age /root/
tpv-restore /root/tpv-20261104-230000-cierre_z.dump.age --identity /media/usb/tpv-copias-age.txt
```

Para la app, vacía la base de datos, la restaura y vuelve a arrancar. Si la
copia es de una versión anterior: `cd /opt/tpv && docker compose exec app php artisan migrate --force`.

**VERI\*FACTU al restaurar.** La copia lleva los registros de facturación
encadenados, su estado en la AEAT y el certificado (cifrado con la APP_KEY).
Restaurando **en el mismo equipo** se conserva su número de instalación (está
en `/opt/tpv/.env`, que no se toca). En un **equipo nuevo** es una
reinstalación: `install.sh` le da un número de instalación nuevo y, al
restaurar, la cadena sigue desde el último registro de la copia (pendiente de
confirmar con el asesor: duda Q6 de `docs/verifactu/dudas-asesor.md`). Pon la
misma `APP_KEY` antes de restaurar o vuelve a subir el certificado en /admin →
VERI\*FACTU. Después: `docker compose exec app php artisan tpv:verifactu:verify`
(debe decir «Cadena correcta») y **no** emitas nada si la copia no es la última:
los registros posteriores a la copia ya estarían en la AEAT y la cadena local
quedaría por detrás (avisa al soporte).

### Descifrar y restaurar en otro equipo (equipo nuevo, comprobación, inspección)

```bash
rclone copy copias:tpv-copias/bella-napoli/tpv-20261104-230000-cierre_z.dump.age .   # o del USB
age -d -i tpv-copias-age.txt -o copia.dump tpv-20261104-230000-cierre_z.dump.age
# a) en un mini PC nuevo, después de install.sh:
tpv-restore copia.dump
# b) en cualquier PostgreSQL 18 para mirar los datos:
createdb tpv_copia && pg_restore --no-owner --no-acl -d tpv_copia copia.dump
```

## 10. Actualizar

Fuera del horario del local, con la caja cerrada (cierre Z hecho), sin pedidos
abiertos ni domicilios pendientes de liquidar (si los hay, no hace nada):

```bash
# Imagen construida en el equipo: copia la carpeta de la versión nueva y
sudo bash install.sh                      # construye la imagen y pasa por tpv-update
# Imagen de GHCR:
sudo tpv-update 1.1.0
sudo tpv-update --tar tpv-1.1.0.tar.gz    # imagen copiada a mano (scp por Tailscale)
```

Hace copia cifrada + volcado de vuelta atrás, cambia de imagen, migra y espera
al healthcheck; **si algo falla, vuelve a la versión anterior y a los datos de
antes** automáticamente. Registro: `/var/log/tpv-update.log` (si es automática).

Actualización automática (opcional, solo con GHCR): `AUTO_UPDATE_AT="04:30"` en el
fichero de `--config` y volver a lanzar `install.sh`.

## 11. Comandos útiles

```bash
cd /opt/tpv
docker compose ps                       # estado (todos "healthy")
docker compose logs -f app printing     # logs
docker compose restart printing         # tras cambiar una impresora USB
cat /opt/tpv/.env | grep TPV_VERSION    # versión instalada (también al pie de /admin)
sudo tpv-mantenimiento                  # estado del kiosko
```

### Salir del kiosko para mantenimiento

El kiosko no tiene barras ni menús y se vuelve a abrir solo. Para entrar con tu
usuario (p. ej. `alex`):

1. En la caja: **Ctrl+Alt+F3** → consola de texto → entra con tu usuario (o
   conéctate por SSH / Tailscale).
2. `sudo tpv-mantenimiento on`: el kiosko deja de arrancar, se quita el inicio
   automático y se cierra su sesión; aparece la pantalla de inicio de sesión (con
   escritorio) o la consola (sin escritorio) para entrar con tu usuario.
3. Al terminar: `sudo tpv-mantenimiento off` y cierra tu sesión (o reinicia): el
   kiosko vuelve solo.

Un camarero no puede hacerlo por accidente: hace falta tu contraseña (y el
usuario `kiosk` no tiene sudo).

## 12. SAI

Ver [sai-nut.md](sai-nut.md): NUT apaga el equipo limpiamente con la batería
baja, y la BIOS lo vuelve a encender al volver la corriente.
