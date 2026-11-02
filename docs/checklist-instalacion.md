# Checklist de instalación (una por cliente)

Local: ____________________  Fecha: __________  Versión: ________  Técnico: __________

**El día de la instalación:** copiar la carpeta → `sudo bash install.sh` → esperar →
abrir `/admin` (código de configuración) → **Puesta en marcha**.

## Antes de ir
- [ ] Carpeta del proyecto en el pendrive (`scripts/make-install-bundle.sh` → `dist/tpv-<versión>.tar.gz`) + USB de Debian 13 (trixie)
- [ ] (Si se usa GHCR) token de solo lectura para este cliente
- [ ] Clave pública age (`age1…`) y destino remoto de las copias (en la UE): S3 o SFTP
- [ ] (Opcional) clave de Tailscale de un solo uso
- [ ] Datos fiscales del local (razón social, NIF, dirección) y lista de personal con PIN
- [ ] **Confirmar el régimen de IVA con el cliente o su gestor**: 01 general / 20 simplificado → ____
- [ ] ¿Declara el Impuesto sobre Sociedades? (sí → VERI\*FACTU obligatorio el 01/01/2027; no → 01/07/2027) → ____
- [ ] (Si ya lo tiene) certificado electrónico (`.p12`/`.pfx`) del titular o de su representante, y su contraseña

## Equipo
- [ ] BIOS: **encender al recibir corriente** (Power On after AC loss)
- [ ] BIOS: sin suspensión / ErP; arranque desde el disco interno
- [ ] **Disco SSD** (SATA o NVMe), no eMMC ni HDD: `lsblk -d -o NAME,ROTA,TRAN` → ROTA 0
- [ ] Debian 13 (con o sin escritorio) con SSH; equipo `tpv-<local>`; contraseñas guardadas
- [ ] Mini PC por **cable**; IP **reservada** en el router: ______________ (MAC ______________)
- [ ] IP de las impresoras de red reservadas: ______________________________

## Instalación
- [ ] `sudo bash install.sh` (o `--demo`, `--ghcr TOKEN`) terminado sin errores
- [ ] Apuntado el **código de configuración** (también en `/root/tpv-credenciales.txt`)
- [ ] Revisados los **avisos** del resumen (p. ej. escritorio no soportado)
- [ ] `systemctl is-enabled fstrim.timer` → enabled · `docker compose -f /opt/tpv/compose.yaml ps`: todos **healthy**
- [ ] Reinicio: el kiosko arranca solo en la pantalla táctil, sin barras ni avisos (con escritorio: sin bloqueo ni notificaciones)
- [ ] Desenchufar y enchufar la corriente: arranca solo y vuelve el TPV
- [ ] Cerrar Chromium a mano (`pkill chromium` por SSH): vuelve a salir solo
- [ ] `sudo tpv-mantenimiento on` → entras con tu usuario → `sudo tpv-mantenimiento off` → vuelve el kiosko

## /admin: primer acceso y Puesta en marcha
- [ ] **Crear administrador** con el código; encargado con su email y PIN
- [ ] 1 Datos de la empresa · 2 Fiscal (régimen y Sociedades) · 3 Tipos de pedido
- [ ] 4 Impresoras: USB detectadas y de red dadas de alta; **Imprimir prueba** en todas (acentos, ñ, €, corte)
- [ ] 5 Copias: clave age, destino; en SFTP, clave SSH copiada al servidor y **huella del servidor** comparada; **Probar copia** → «subida al destino ✓»
- [ ] 6 Acceso remoto: Tailscale «Conectado como tpv-<local>» (u omitido); *key expiry* desactivado
- [ ] 7 Certificado subido (titular, NIF y caducidad correctos) y VERI\*FACTU **activado ahora** o **en la fecha de obligación**
- [ ] Panel: «Puesta en marcha: 7 de 7 pasos»

## TPV
- [ ] Usuarios y PIN; carta; salas y mesas
- [ ] Móvil en la wifi del local → `http://<IP>` → entra con PIN y ve los cambios al momento
- [ ] Pedido de prueba: comanda en cocina, cobro, ticket; anular con rectificativo
- [ ] Con VERI\*FACTU activo: el ticket lleva el **QR**, el registro pasa a **Correcto** y la sede de la AEAT lo encuentra
- [ ] Caja ⚙ → Administración pide email y contraseña; /admin → Ir a la TPV vuelve a la caja
- [ ] Abrir y cerrar caja (Z de prueba) — ⚠ los Z son correlativos: hazlo antes de empezar a vender

## Copias y soporte
- [ ] Restauración probada (en otro equipo o en una prueba): la clave privada funciona
- [ ] Clave privada age guardada en **dos sitios** fuera del mini PC
- [ ] **APP_KEY** y claves de `/root/tpv-credenciales.txt` guardadas en el gestor de contraseñas, junto con el certificado digital y su contraseña
- [ ] /admin sin avisos de copias ni de VERI\*FACTU
- [ ] (Si hay SAI) NUT configurado y `upsmon -c fsd` probado
- [ ] `sudo shred -u /root/tpv-credenciales.txt` tras guardarlo todo

## Entrega
- [ ] Explicado al cliente: PIN, caja, cierre Z diario, avisos de impresión
- [ ] Teléfono / canal de soporte entregado
