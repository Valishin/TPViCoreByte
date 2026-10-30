# Checklist de instalación (una por cliente)

Local: ____________________  Fecha: __________  Versión: ________  Técnico: __________

## Antes de ir
- [ ] Imagen publicada en GHCR (o `.tar.gz` en el USB) — versión ______
- [ ] `dist/tpv-instalador-<versión>.tar.gz` en el USB + USB de Debian 13 (trixie)
- [ ] Token GHCR de solo lectura para este cliente
- [ ] Clave pública age (`age1…`) y destino remoto de las copias (en la UE)
- [ ] Clave de Tailscale de un solo uso
- [ ] Datos fiscales del local (razón social, NIF, dirección) y lista de personal con PIN

## Equipo
- [ ] BIOS: **encender al recibir corriente** (Power On after AC loss)
- [ ] BIOS: sin suspensión / ErP; arranque desde el disco interno
- [ ] **Disco SSD** (SATA o NVMe), no eMMC ni HDD: `lsblk -d -o NAME,ROTA,TRAN` → ROTA 0
- [ ] Debian 13 sin escritorio, con SSH; equipo `tpv-<local>`; contraseña root guardada
- [ ] `systemctl is-enabled fstrim.timer` → enabled
- [ ] Mini PC por **cable**; IP **reservada** en el router: ______________ (MAC ______________)
- [ ] IP de las impresoras de red reservadas: ______________________________
- [ ] `lsusb` de las impresoras USB: ______________________________

## Instalación
- [ ] `tpv-install.conf` revisado (`./install.sh --dry-run` sin errores)
- [ ] `./install.sh` terminado sin errores; credenciales apuntadas
- [ ] `docker compose ps`: todos los servicios **healthy**
- [ ] Reinicio: el kiosko arranca solo en la pantalla táctil, sin barras ni avisos
- [ ] Desenchufar y enchufar la corriente: arranca solo y vuelve el TPV
- [ ] Cerrar Chromium a mano (`pkill chromium` por SSH): vuelve a salir solo
- [ ] El cursor desaparece a los 3 s sin mover el ratón; el táctil responde en toda la pantalla

## TPV
- [ ] /admin → Ajustes del local con los datos fiscales reales
- [ ] Impresoras: **Imprimir prueba** en todas (acentos, ñ, €, corte)
- [ ] Impresora USB: desenchufar y enchufar → sigue imprimiendo
- [ ] Usuarios y PIN; carta; salas y mesas
- [ ] Móvil en la wifi del local → `http://<IP>` → entra con PIN y ve los cambios al momento
- [ ] Pedido de prueba: comanda en cocina, cobro, ticket; anular con rectificativo
- [ ] Abrir y cerrar caja (Z de prueba) — ⚠ los Z son correlativos: hazlo antes de empezar a vender

## Copias y soporte
- [ ] `docker compose exec app php artisan tpv:backup` → OK y subida al destino remoto
- [ ] Restauración probada (en otro equipo o en una prueba): la clave privada funciona
- [ ] Clave privada age guardada en **dos sitios** fuera del mini PC
- [ ] **APP_KEY de este cliente** (`/root/tpv-credenciales.txt`) guardada en el gestor de contraseñas, junto con la clave privada age (y, con VERI\*FACTU, el certificado digital)
- [ ] /admin sin aviso de copias
- [ ] Tailscale: `ssh` por Tailscale funciona; *key expiry* desactivado
- [ ] (Si hay SAI) NUT configurado y `upsmon -c fsd` probado
- [ ] `shred -u /root/tpv-credenciales.txt` tras guardar las credenciales

## Entrega
- [ ] Explicado al cliente: PIN, caja, cierre Z diario, avisos de impresión
- [ ] Teléfono / canal de soporte entregado
