# Instalación en el mini PC (producción)

Del USB de Debian al TPV funcionando en la pantalla táctil. Para cada cliente,
usa además [checklist-instalacion.md](checklist-instalacion.md).

**Equipo previsto:** mini PC Intel N100/N150, 8-16 GB, **disco SSD** (SATA o
NVMe; no eMMC ni HDD: la base de datos escribe a menudo), pantalla táctil
(HDMI + USB), impresoras de red y/o USB, SAI opcional ([sai-nut.md](sai-nut.md)).

**Paquetes de Debian 13 que usa:** cage, chromium, wlr-randr, nut (SAI),
ufw, unattended-upgrades, systemd-timesyncd; Docker CE del repositorio de
Docker; Tailscale de su repositorio. age y rclone van dentro de la imagen del
TPV (en el host solo hacen falta para descifrar una copia: `apt install age`).

**Qué se instala:** Debian 13 (trixie) sin escritorio · Docker · el TPV en contenedores
(`/opt/tpv`) · kiosko (usuario `kiosk`: cage + Chromium a pantalla completa) ·
Tailscale para el soporte remoto · copias de seguridad cifradas.

---

## 0. Antes de ir al local (en tu Mac)

1. **Imagen** de la versión que vas a instalar:
   ```bash
   TPV_IMAGE=ghcr.io/<usuario>/tpv-hosteleria scripts/build-image.sh --push   # a GHCR
   # o, sin internet en el local / sin GHCR:
   TPV_IMAGE=ghcr.io/<usuario>/tpv-hosteleria scripts/build-image.sh --tar    # dist/tpv-<versión>.tar.gz
   ```
2. **Paquete del instalador** (`dist/tpv-instalador-<versión>.tar.gz`):
   ```bash
   scripts/make-install-bundle.sh                # o --with-image para incluir el .tar
   ```
3. **Token de GHCR de solo lectura** para el mini PC (ver [Tokens de GitHub](#tokens-de-github-y-ghcr)).
4. **Claves de las copias (age)** — una vez, en tu Mac (ver [§9](#9-copias-de-seguridad)).
5. **Clave de Tailscale** de un solo uso: admin.tailscale.com → Settings → Keys
   → *Generate auth key* (reusable: no; ephemeral: no; con *tag* `tag:tpv`).
6. Copia el paquete a un USB junto con el **USB de instalación de Debian 13**
   (`debian-13.x.x-amd64-netinst.iso` de debian.org, grabada con balenaEtcher
   o `dd`). Debian 12 ya no tiene soporte de seguridad normal (julio de 2026).

### Tokens de GitHub y GHCR

GHCR (el registro de imágenes de GitHub) solo acepta **tokens clásicos**
(*Personal access tokens (classic)*); los *fine-grained* no sirven para
paquetes. Se crean en GitHub → Settings → Developer settings → Personal
access tokens → **Tokens (classic)** → *Generate new token (classic)*.

| Para qué | Dónde | Permisos (scopes) | Caducidad |
|---|---|---|---|
| **Subir la imagen** desde el Mac | tu Mac (`docker login`) | `write:packages` (incluye `read:packages`) | 90 días, y renovar |
| **Descargar la imagen** en el mini PC | `GHCR_TOKEN` del `.conf` | **solo `read:packages`** | sin caducidad o 1 año; **uno por cliente** |

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
- **Selección de programas**: desmarca todo el escritorio; deja solo
  **servidor SSH** y **utilidades estándar**.
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

Desde tu Mac (o copiando del USB): `scp dist/tpv-instalador-1.0.0.tar.gz soporte@192.168.1.10:`

```bash
ssh soporte@192.168.1.10
su -                                    # root
tar xzf /home/soporte/tpv-instalador-1.0.0.tar.gz && cd tpv-instalador-1.0.0
cp tpv-install.conf.example tpv-install.conf && nano tpv-install.conf
./install.sh --config tpv-install.conf  # (--dry-run para ver antes lo que haría)
```

`tpv-install.conf` lleva: nombre del local, IP, email del admin, tipos de
pedido, imagen y versión, usuario y token de GHCR (o `IMAGE_TAR` con el `.tar.gz`),
impresoras USB (`nombre:idVendor:idProduct[:serie]`, ver `lsusb`), tu clave
pública age, el destino de las copias, la clave de Tailscale y, si se quiere,
horario de pantalla y actualización automática (vacíos = nunca).

El instalador (se puede volver a lanzar sin perder nada):

1. Zona horaria **Europe/Madrid**, NTP, actualizaciones de seguridad
   automáticas (sin reinicios), TRIM semanal del SSD (`fstrim.timer`) y
   cortafuegos **ufw** (SSH, 80 y Tailscale).
2. **Docker CE** (repositorio oficial para trixie) con logs rotados.
3. **Kiosko**: usuario `kiosk` sin contraseña ni sudo, autologin en tty1, cage
   + Chromium en `http://localhost/?kiosk=1` sin barras, menús, traducción ni
   "restaurar páginas", sin pellizcar para hacer zoom. Si Chromium se cierra o
   se cuelga, vuelve a arrancar solo (con espera creciente para no entrar en
   bucle). El cursor se oculta a los 3 s sin mover el ratón. La pantalla no se
   apaga (o se apaga y enciende a las horas de `SCREEN_OFF`/`SCREEN_ON`).
4. **Impresoras USB**: regla udev → `/dev/usb/impresora-<nombre>` (ver
   [impresoras-usb.md](impresoras-usb.md)).
5. **Tailscale** con SSH.
6. **TPV** en `/opt/tpv`: `.env` con **claves y contraseñas nuevas** (APP_KEY,
   base de datos, Reverb), imagen, migraciones y el **admin real** (sin datos
   de demo). Contraseña y PIN en `/root/tpv-credenciales.txt` (solo root).
7. Resumen con la URL, la IP y los siguientes pasos.

Reinicia (`reboot`): la pantalla debe mostrar la pantalla de PIN del TPV.

## 5. Primeros pasos en /admin

Desde cualquier equipo de la red: `http://192.168.1.10/admin` con el email del
admin y la contraseña de `/root/tpv-credenciales.txt`.

1. **Ajustes del local**: razón social, **NIF**, dirección (salen en todos los
   tickets), tipos de pedido, logo, pie del ticket.
2. **Impresoras**: red (`IP` y `9100`) o USB (`/dev/usb/impresora-ticket`) →
   **Imprimir prueba** en cada una (acentos, ñ y €).
3. **Usuarios**: camareros y encargados con su PIN (y repartidores si hay reparto).
4. **Carta**: categorías (con su impresora), productos, modificadores.
5. **Sala**: salas y mesas.
6. Borra el fichero de credenciales cuando las hayas guardado:
   `shred -u /root/tpv-credenciales.txt`.

## 6. Impresoras

- **De red**: IP fija, puerto 9100. Prueba desde el mini PC:
  `printf 'Prueba\n\n\n\x1dV\x00' | nc -w 3 192.168.1.203 9100`.
- **USB**: `lsusb` para los IDs → `PRINTERS_USB` en el `.conf` → volver a lanzar
  `install.sh`. Detalles y problemas típicos en [impresoras-usb.md](impresoras-usb.md).

## 7. Móviles de los camareros

Misma wifi que el mini PC → `http://192.168.1.10` (la dirección aparece abajo
a la derecha en la caja). En Android/iOS: *Añadir a pantalla de inicio*.

## 8. Soporte remoto (Tailscale)

Sin abrir puertos en el router del cliente.

- Si pusiste `TAILSCALE_AUTHKEY`, el equipo ya está en tu red Tailscale como
  `tpv-<local>`. Si no: `sudo tailscale up --ssh --hostname tpv-<local>` y abre
  el enlace que muestra.
- **SSH**: `ssh soporte@tpv-<local>` (o `tailscale ssh`).
- **/admin con HTTPS** (solo por Tailscale; en la red local es HTTP):
  ```bash
  sudo tailscale serve --bg http://localhost:80
  ```
  → `https://tpv-<local>.<tu-tailnet>.ts.net/admin`.
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

- La línea **`Public key: age1…`** es la que va en `BACKUP_AGE_RECIPIENT` del
  `.conf` (puede ser la misma para todos tus clientes, o una por cliente).
- **El fichero `tpv-copias-age.txt` es la clave PRIVADA.** No va nunca al mini
  PC. Guárdala en **dos sitios seguros distintos** fuera de él (p. ej. tu gestor
  de contraseñas como nota segura + un USB cifrado guardado en otro lugar).
  **Si se pierde, las copias son irrecuperables**: nadie, ni tú, puede
  descifrarlas.

### Destino remoto

Las copias contienen **datos personales de clientes** (nombres, teléfonos,
direcciones): el destino debe estar **en la UE** (RGPD) y con contrato de
encargado del tratamiento si es un proveedor. Opciones:

- **S3 compatible** (proveedor europeo, o un MinIO tuyo). En `/opt/tpv/.env`:
  ```ini
  BACKUP_REMOTE=copias:tpv-copias/bella-napoli
  RCLONE_CONFIG_COPIAS_TYPE=s3
  RCLONE_CONFIG_COPIAS_PROVIDER=Other
  RCLONE_CONFIG_COPIAS_ENDPOINT=https://s3.eu-central-1.ejemplo.com
  RCLONE_CONFIG_COPIAS_ACCESS_KEY_ID=…
  RCLONE_CONFIG_COPIAS_SECRET_ACCESS_KEY=…
  ```
  Usa credenciales que solo puedan escribir en ese bucket/carpeta.
- **Tu servidor por SSH/SFTP**:
  ```ini
  BACKUP_REMOTE=copias:/srv/copias/bella-napoli
  RCLONE_CONFIG_COPIAS_TYPE=sftp
  RCLONE_CONFIG_COPIAS_HOST=copias.ejemplo.com
  RCLONE_CONFIG_COPIAS_USER=tpv-bella-napoli
  RCLONE_CONFIG_COPIAS_KEY_FILE=/app/storage/app/private/ssh/id_ed25519
  ```
  (la clave SSH: `docker compose cp id_ed25519 app:/app/storage/app/private/ssh/`).

Tras cambiar el `.env`: `cd /opt/tpv && docker compose up -d`. Probar:

```bash
cd /opt/tpv
docker compose exec app php artisan tpv:backup            # copia + subida ahora
docker compose exec app sh -c 'ls -lh /backups; rclone ls "$BACKUP_REMOTE"'
```

### Restaurar en el mini PC

Trae la clave privada en un USB y bórrala después:

```bash
docker compose -f /opt/tpv/compose.yaml cp scheduler:/backups/tpv-20261104-230000-cierre_z.dump.age /root/
tpv-restore /root/tpv-20261104-230000-cierre_z.dump.age --identity /media/usb/tpv-copias-age.txt
```

Para la app, vacía la base de datos, la restaura y vuelve a arrancar. Si la
copia es de una versión anterior: `cd /opt/tpv && docker compose exec app php artisan migrate --force`.

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
sudo tpv-update 1.1.0                     # desde GHCR
sudo tpv-update --tar tpv-1.1.0.tar.gz    # imagen copiada a mano (scp por Tailscale)
```

Hace copia cifrada + volcado de vuelta atrás, cambia de imagen, migra y espera
al healthcheck; **si algo falla, vuelve a la versión anterior y a los datos de
antes** automáticamente. Registro: `/var/log/tpv-update.log` (si es automática).

Actualización automática (opcional): `AUTO_UPDATE_AT="04:30"` en el `.conf` y
volver a lanzar `install.sh` → cada noche `tpv-update latest` (no hace nada si
ya está al día o la caja está abierta).

## 11. Comandos útiles

```bash
cd /opt/tpv
docker compose ps                       # estado (todos "healthy")
docker compose logs -f app printing     # logs
docker compose restart printing         # tras cambiar una impresora USB
docker compose exec app php artisan tpv:print-test
cat /opt/tpv/.env | grep TPV_VERSION    # versión instalada (también al pie de /admin)
sudo systemctl restart getty@tty1       # reiniciar el kiosko
```

## 12. SAI

Ver [sai-nut.md](sai-nut.md): NUT apaga el equipo limpiamente con la batería
baja, y la BIOS lo vuelve a encender al volver la corriente.
