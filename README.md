# TPV Hostelería

Base de un TPV local-first para hostelería. En producción corre en un mini PC
Linux con Docker: la pantalla táctil del mini PC es la caja (Chromium en modo
kiosko) y los móviles de los camareros entran por la IP local del mini PC.

**Stack:** Laravel 13 · PHP 8.4 · PostgreSQL 18 · Sanctum (SPA con cookies) ·
Reverb (websockets) · Filament 5 (backoffice en `/admin`) · colas en base de
datos · Vue 3 + Vite + Vue Router + Pinia + axios + laravel-echo · SCSS con BEM
· mike42/escpos-php.

---

## Requisitos (desarrollo)

| Herramienta | Versión probada | Para qué |
|---|---|---|
| Docker Desktop | 29.x (Compose v5) | app, PostgreSQL, Reverb y worker |
| Node.js / npm | 25.x / 11.x | Vite (se ejecuta en el Mac, no en Docker) |
| PHP / Composer (Homebrew) | 8.5 / 2.9 | solo para `composer install` en el host (opcional) |

> Si `docker` no se encuentra en la terminal, añade los binarios de Docker
> Desktop al PATH: `echo 'export PATH="$HOME/.docker/bin:$PATH"' >> ~/.zshrc`

Composer está fijado a `platform.php = 8.4` para que el `composer.lock` sea
siempre compatible con el contenedor, aunque en el Mac tengas PHP 8.5.

### ¿Por qué PHP 8.4?
Es la misma versión en desarrollo (runtime 8.4 de Sail) y en el mini PC.
Laravel 13, Filament 5 y escpos-php funcionan también en 8.5, pero 8.4 lleva
más tiempo en producción, tiene paquetes en todas las distros LTS y es la opción
más compatible con extensiones y librerías de terceros (escpos-php apenas se
mantiene).

## Servicios

| Servicio | Qué es | Puerto en el Mac |
|---|---|---|
| `laravel.test` | Laravel (HTTP) | `APP_PORT` = **8081** |
| `pgsql` | PostgreSQL 18 | `FORWARD_DB_PORT` = 5432 |
| `reverb` | Servidor websockets (`reverb:start` en 0.0.0.0) | `FORWARD_REVERB_PORT` = 8080 |
| `queue` | Worker de la cola `default` (`queue:listen`) | — |
| `printing` | Worker exclusivo de la cola `printing` (comandas) | — |
| Vite (en el Mac) | Assets de desarrollo + HMR | `VITE_PORT` = **5174** |

Los puertos 80 y 5173 los usa MAMP / otro proyecto en este Mac; por eso la app
va en 8081 y Vite en 5174. Se cambian en `.env`, nunca hace falta cerrar nada.

`reverb`, `queue` y `printing` reutilizan la imagen de la app (sin build extra).
La impresión va en su propio worker para que una impresora caída (timeout de
3 s por intento) no retrase nada más. No hay
Redis, Mailpit ni Meilisearch: la cola, la caché y las sesiones van en
PostgreSQL. Así el consumo de memoria es mínimo.

En desarrollo el worker usa **`queue:listen`**, que recarga el código en cada
job (no hay que reiniciar el contenedor al cambiar un job). **En producción
será `queue:work`** (más eficiente; se reinicia con `php artisan queue:restart`
en cada despliegue).

> Los workers leen el `.env` al arrancar: si cambias algo que usan (p. ej.
> `PRINTER_MODE`), reinícialos con `./vendor/bin/sail restart queue printing`.

## Primera instalación

```bash
cp .env.example .env            # y revisa VITE_HMR_HOST y SANCTUM_STATEFUL_DOMAINS
composer install                # o: docker run --rm -v "$PWD":/app -w /app laravelsail/php84-composer composer install
npm install
./vendor/bin/sail build
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
# Rellena REVERB_APP_ID, REVERB_APP_KEY y REVERB_APP_SECRET con valores aleatorios, p. ej.:
#   echo "REVERB_APP_ID=$RANDOM$RANDOM REVERB_APP_KEY=$(openssl rand -hex 10) REVERB_APP_SECRET=$(openssl rand -hex 10)"
./vendor/bin/sail artisan migrate --seed
```

## Arrancar el día a día

```bash
./vendor/bin/sail up -d     # app + PostgreSQL + Reverb + worker de colas
npm run dev                 # Vite (déjalo abierto en otra pestaña)
```

- SPA: <http://localhost:8081>  · Diagnóstico: <http://localhost:8081/estado>
- Backoffice: <http://localhost:8081/admin>

Otros comandos útiles:

```bash
./vendor/bin/sail logs -f reverb queue   # ver websockets y cola
./vendor/bin/sail restart reverb         # Reverb NO recarga código solo
./vendor/bin/sail down                   # parar todo (los datos de PostgreSQL se conservan)
./vendor/bin/sail artisan tpv:print-test # página de prueba de impresora
./vendor/bin/sail test                   # tests (usan la BD 'testing' de PostgreSQL)
```

## Usuario admin de desarrollo

> ⚠️ **SOLO DESARROLLO.** Lo crea `AdminUserSeeder`, que no se ejecuta con
> `APP_ENV=production`. No uses estas credenciales en el mini PC.

| | |
|---|---|
| URL | <http://localhost:8081/admin> |
| Admin | `admin@tpv.test` / `admin1234` (PIN `0000`) |
| Encargado | `encargado@tpv.test` / `admin1234` (PIN `9999`) |

Los camareros y repartidores de demo solo tienen PIN (ver `docs/modelo-datos.md`).

## Datos de demo

Hay dos datasets excluyentes: **bar** (mesas, tapas y bebidas) y **pizzería**
(reparto, tamaños, mitad y mitad, menús). `DEMO_DATASET` del `.env` decide
cuál carga `db:seed`; para cambiar sin tocar el `.env`:

```bash
./vendor/bin/sail artisan migrate:fresh --seeder=DemoPizzeriaSeeder
./vendor/bin/sail artisan migrate:fresh --seeder=DemoBarSeeder
```

> `DEMO_DATASET=pizzeria ./vendor/bin/sail artisan ...` **no funciona**: Sail no
> pasa variables de la línea de comandos al contenedor. Usa `--seeder` o el `.env`.

Para tener ventas de ejemplo (una semana de tickets con cierres de caja, útil
para ver el panel y los listados de Ventas):

```bash
./vendor/bin/sail artisan db:seed --class=DemoSalesSeeder
```

El modelo de datos completo (tablas, diagrama ER y reglas de cálculo) está en
[docs/modelo-datos.md](docs/modelo-datos.md).

## Backoffice (/admin)

Filament 5 en español. Menú: **Carta** (productos, categorías, grupos de
modificadores) · **Sala** (salas y mesas) · **Clientes y reparto** (clientes,
zonas) · **Ventas** (pedidos, tickets, cierres: solo consulta) ·
**Configuración** (ajustes del local, series, usuarios, impresoras).

| Rol | Puede |
|---|---|
| Admin | Todo. |
| Encargado | Carta, sala, impresoras, clientes, zonas y consultar ventas. No ve ajustes fiscales, series ni usuarios. |
| Camarero / repartidor | No entran en el backoffice (solo TPV con PIN). |

- Importes: se escriben en euros con coma (`12,50`) y se guardan en céntimos.
  Porcentajes e IVA se ven como `10 %` y se guardan en puntos básicos.
- Los tickets no se pueden editar ni borrar desde ningún sitio.
- "Imprimir prueba" en Impresoras manda la página de prueba a la cola
  `printing` y espera el resultado como mucho 5 s (`PRINTER_WEB_WAIT`): avisa de
  si ha salido, del error o de que la impresora no responde todavía (en modo
  simulado se guarda en `storage/app/private/prints/`). Necesita el worker
  `printing` en marcha. La impresión nunca bloquea una petición web.
- Una mesa con un pedido abierto no se puede desactivar.
- Duplicar un producto crea "… (copia)" desactivado, para revisarlo.

Se pueden cambiar con `DEV_ADMIN_EMAIL` / `DEV_ADMIN_PASSWORD` en `.env` y
volver a sembrar.

## TPV de sala (/)

La SPA en <http://localhost:8081> (o la IP del ordenador desde el móvil).

- **Entrar:** se elige el usuario y se teclea su PIN (4 dígitos). Usan el TPV
  camareros, encargados y admin, y los repartidores (sección aparte, solo ven
  "Mis repartos"). 5 intentos fallidos por
  usuario bloquean 5 minutos. La sesión del TPV es independiente de la de /admin.
- **PINs de demo:** bar → Ana 1111, Luis 2222, Marta 3333; pizzería → Carlos
  1111, repartidores Javi 4444 y Sara 5555; admin 0000 y encargado 9999.
- **Disposiciones:** caja (pantalla horizontal ≥ 1024 px: plano según la
  posición de las mesas, y categorías, productos y pedido a la vez) y móvil
  (rejilla de mesas, y el pedido en una hoja inferior). Tema oscuro/claro con
  el botón ☀/☾, recordado en cada dispositivo.
- **Comanda:** un toque añade un producto simple; si tiene tamaños,
  modificadores, menú o mitad y mitad se abre un configurador paso a paso.
  Las líneas idénticas pendientes se agrupan. "Enviar" manda cada línea a su
  impresora (los componentes de menú y las mitades, a la suya).
- **Líneas ya enviadas:** no se editan; se anulan con motivo y se imprime una
  ANULACIÓN en su impresora.
- **Comandas sin imprimir:** aviso rojo persistente en todas las pantallas con
  "Reintentar" y "Enviar a otra". Nada se reimprime solo (evita duplicados).
- **Bloqueo:** botón "Bloquear" y bloqueo automático por inactividad
  (Ajustes del local → TPV: caja 2 min y móvil desactivado por defecto). Si
  vuelve el mismo camarero, sigue donde estaba.
- **Tiempo real:** todo cambio (mesas, pedidos, impresoras, carta) llega al
  momento a las demás pantallas; el punto verde de la barra indica la conexión.

## Cobro, caja y tickets

- **Caja:** hace falta una caja abierta para cobrar (botón **Caja** en la barra
  superior). Solo encargado o admin la abren y cierran; si lo intenta un
  camarero, se pide el PIN de un encargado sin cerrar su sesión.
- **Cobrar** (desde la comanda, o **Venta rápida** en el plano para barra sin
  mesa): Efectivo (teclado en euros, billetes rápidos, "Importe exacto" y
  cambio), Tarjeta y Bizum (datáfono externo: se propone lo pendiente; la
  tarjeta admite **propina**, que no entra en el ticket ni en el IVA), Otro.
  Pago mixto hasta cubrir el total. **Dividir a partes iguales** = un solo
  ticket con varios pagos (justificante de pago opcional por parte, que no es
  factura). **Dividir por productos** = cuenta nueva con su propio ticket.
- El cajón solo se abre si se cobra en efectivo. "Abrir cajón" sin venta pide
  autorización. Un doble toque en "Cobrar" no emite dos tickets (clave de
  idempotencia).
- **Autorización de encargado** para: descuentos por encima del límite
  (Ajustes del local, 10 % por defecto), invitaciones, anular tickets, abrir el
  cajón sin venta, reimprimir a partir de la 2.ª copia y abrir/cerrar caja.
  Queda registrado quién la pidió y quién la dio.
- **Anular un ticket** (Caja → Anular, o en /admin → Tickets): emite un
  rectificativo por diferencias en la serie R (total o parcial) y registra la
  devolución en la caja. En /admin también "por sustitución".
- **Cierre Z**: arqueo con importe o contando billetes y monedas; avisa si hay
  pedidos abiertos o domicilios sin cobrar. Imprime el Z (correlativo) con ventas, formas de pago,
  propinas, IVA, camareros y tipos de pedido.
- **Exportación fiscal** (/admin → Ventas → Exportación fiscal, o
  `sail artisan tpv:export-fiscal --from=AAAA-MM-DD --to=AAAA-MM-DD`): ZIP con
  CSV y PDF de tickets, rectificativos y cierres Z.

## Para llevar y domicilio

Se ven si esos tipos están activos en Ajustes del local (la pizzería de demo
no tiene sala: entra directamente en **Pedidos**).

- **Pedidos** (barra superior): columnas Pendiente · En cocina · Listo · En
  reparto y "Entregados hoy" plegado (pestañas en el móvil). Cada tarjeta
  avisa con texto si quedan menos de 10 min o si va tarde.
- **Nuevo pedido:** Para llevar (nombre y teléfono opcionales) o Domicilio
  (teléfono con el teclado numérico → ficha con notas, direcciones y últimos
  pedidos, "repetir" con la carta de hoy; o alta rápida con la zona sugerida
  por el CP). Hora: "Lo antes posible" (tiempos en Ajustes) o +20…+60 min.
  Pago previsto: efectivo (con "cambio para"), tarjeta o ya pagado.
- **Estados:** enviar la comanda pasa a En cocina; Listo a mano. Domicilio:
  **Dar salida** con el repartidor → se emite el ticket (pendiente de cobro,
  o pagado si ya lo estaba) y se imprime el ticket de domicilio (y la copia
  con QR de Google Maps si está activada). Para llevar: se cobra al recogerlo.
- **Pedido mínimo** de la zona: aviso al enviar; se puede seguir con la
  autorización de un encargado.
- **Cancelar** antes del ticket, con motivo (autorización y anulación impresa
  si ya se envió a cocina). Con ticket, solo con rectificativo.
- **Repartidores** (caja): fondo de cambio (movimiento de caja, no es venta),
  sus repartos y lo que debe entregar (cobrado en efectivo + fondo).
  **Liquidar** cobra cada ticket con su método real en la caja abierta y
  muestra la diferencia. **No entregado**: rectificativo total sin devolución.
- **Mis repartos** (móvil del repartidor): dirección e indicaciones, abrir en
  Maps, llamar, importe y cambio; Entregado con el cobro real (lo propone la
  caja al liquidar) o No entregado.
- Los informes X y Z muestran los domicilios pendientes de cobro y los
  movimientos de caja; cerrar el Z con pendientes pide confirmación.

> Fuera de alcance por ahora: editor visual del plano y modo sin conexión.

## Entrar desde el móvil (red local)

1. El móvil tiene que estar en la **misma wifi** que el Mac.
2. La dirección está **abajo a la derecha en la caja** ("Móviles:
   http://192.168.1.35:8081"). Se actualiza sola cada minuto si el router
   cambia la IP. También: `ipconfig getifaddr en0`.
3. Si la IP ha cambiado, reinicia `npm run dev`: con `VITE_HMR_HOST` vacío,
   Vite usa la IP actual. `SANCTUM_STATEFUL_DOMAINS` admite cualquier IP de la
   red local (`192.168.*`, `10.*`), así que no hay que tocar `.env`.
4. En el móvil abre esa dirección (o `/estado` para probar la conexión).
5. Prueba los tres botones de `/estado`. Ábrelo también en el Mac y pulsa
   "Enviar evento" en uno: debe aparecer en los dos a la vez.

En el mini PC (sin Vite) la etiqueta usa `APP_LAN_IP` de `.env` (su IP fija),
o la IP con la que se ha entrado si ya es de la red.

La primera vez macOS puede preguntar si permites conexiones entrantes a `node`:
acepta (si no, el móvil no cargará los assets de Vite).

**Cómo está hecho:** Vite escucha en `0.0.0.0` y anuncia `VITE_HMR_HOST` (o la IP detectada) como
origen de los assets y del HMR (el navegador del móvil tiene que poder llegar a
él). Echo se conecta siempre a `window.location.hostname`, nunca a una IP fija,
así que la caja (localhost) y los móviles (IP) usan el mismo build. Reverb
escucha en `0.0.0.0`. Laravel publica los eventos en Reverb por la red interna
de Docker (`REVERB_HOST=reverb`). `SESSION_DOMAIN=null` hace que la cookie de
sesión sea del host con el que se entra (localhost o IP).

## Impresora

`PrinterManager` (`app/Services/Printing`) tiene dos modos, según `PRINTER_MODE`:

- `simulated` (por defecto): guarda cada ticket en
  `storage/app/private/prints/` como `.txt` legible y `.bin` con los bytes
  ESC/POS exactos que irían a la impresora.
- `real`: impresión de verdad, por red (TCP, `PRINTER_HOST:PRINTER_PORT`, normalmente 9100) o USB según cada impresora. `network` se acepta como alias antiguo (deja un aviso en el log).

Formas de lanzar una página de prueba (nombre, acentos, ñ, €, fecha y corte):

- Botón "Imprimir prueba" en `/estado` → job `PrintTestPage` en cola.
- `./vendor/bin/sail artisan tpv:print-test` → imprime directamente.
- `./vendor/bin/sail artisan tpv:print-test --queue` → lo manda a la cola.

### Impresoras USB

Cada impresora se configura en /admin → Impresoras como **Red** (IP y puerto)
o **USB** (dispositivo, p. ej. `/dev/impresora-ticket`). Con
`PRINTER_MODE=real` se imprime de verdad y cada una usa su conexión. La
escritura USB es no bloqueante y se corta a los `PRINTER_TIMEOUT` segundos (3)
si la impresora no acepta datos; no depende de pcntl.

> **En el Mac no se pueden probar las USB:** Docker Desktop para macOS no
> puede pasar dispositivos USB a los contenedores. En desarrollo se usa el
> modo simulado; las USB se prueban en el mini PC con Debian.

En el mini PC hace falta una regla udev (enlace fijo), el driver `usblp`, el
grupo `lp` y montar el dispositivo en los contenedores `printing` y `app`:
todo explicado en [docs/impresoras-usb.md](docs/impresoras-usb.md), con un
ejemplo en `docker-compose.prod.yml`.

### Probar la impresora real

1. Conecta la impresora a la red e imprime su hoja de autotest (suele ser
   mantener pulsado FEED al encender) para ver su IP.
2. Comprueba que el Mac llega a ella: `nc -vz 192.168.1.200 9100`.
3. En `.env`:
   ```
   PRINTER_MODE=real
   PRINTER_HOST=192.168.1.200
   PRINTER_PORT=9100
   ```
4. `./vendor/bin/sail artisan tpv:print-test`
5. El worker de colas lee `.env` en cada job (`queue:listen`), no hace falta
   reiniciarlo para el botón de `/estado`.

**Página de códigos:** `PRINTER_CODEPAGE` es el número de tabla de caracteres
(`ESC t n`) de la impresora. `16` = WPC1252 (á, é, ñ, €) en la mayoría de Epson
y compatibles. Si salen caracteres raros, prueba `19` (CP858, también con €) o
`2` (CP850, sin €), o déjalo vacío para que escpos-php elija automáticamente
según `PRINTER_PROFILE` (`default`, `simple`, `TM-T88V`, …). La hoja de
autotest de la impresora suele listar sus tablas. `PRINTER_COLUMNS` es 48 para
papel de 80 mm y 32 para 58 mm.

## Estructura del frontend

```
resources/
├── js/
│   ├── app.js            # arranque: Vue + Pinia + Router + Echo
│   ├── App.vue
│   ├── router/           # rutas de la SPA (/, /estado)
│   ├── stores/           # Pinia (diagnostics.js)
│   ├── views/            # páginas (HomeView, EstadoView)
│   ├── components/       # TpvButton, StatusCard
│   └── services/         # api.js (axios + Sanctum), echo.js (Reverb)
├── scss/
│   ├── _tokens.scss      # variables: colores, espacios, tipografía, táctil
│   ├── _base.scss        # reset y estilos base
│   ├── components/       # bloques BEM: .c-button, .c-status-card
│   ├── views/            # bloques BEM de páginas: .v-home, .v-estado
│   └── app.scss          # punto de entrada
└── views/app.blade.php   # contenedor de la SPA
```

Convención BEM: `.c-bloque__elemento--modificador` para componentes y `.v-…`
para vistas. Sin Tailwind ni utilidades en la SPA. Filament trae sus propios
estilos precompilados (`public/css/filament`, publicados por
`php artisan filament:upgrade` al hacer `composer install`), así que no depende
de Vite.

`routes/web.php` tiene una ruta comodín que sirve la SPA para todo excepto
`admin`, `api`, `livewire`, `filament`, `broadcasting`, `sanctum`, `storage`,
`build`, `up` y los assets estáticos.

## Producción (mini PC)

Guía paso a paso: **[docs/instalacion-minipc.md](docs/instalacion-minipc.md)**
(BIOS, Debian 12, IP reservada, instalador, impresoras, Tailscale, copias,
actualizaciones) y **[docs/checklist-instalacion.md](docs/checklist-instalacion.md)**
para cada cliente. SAI: [docs/sai-nut.md](docs/sai-nut.md).

- **Imagen** (FrankenPHP + PHP 8.4, linux/amd64) desde el Mac:
  `TPV_IMAGE=ghcr.io/<usuario>/tpv-hosteleria scripts/build-image.sh --push`
  (o `--tar`). La versión sale de `VERSION` (semver a mano) más el commit, y se
  ve al pie de /admin, en /estado y en `/api/health`.
- **Paquete del instalador** para el USB: `scripts/make-install-bundle.sh`
  (`--with-image` para incluir la imagen).
- **Servicios** (`docker-compose.prod.yml`): app (puerto 80; FrankenPHP pasa
  `/app` a Reverb), reverb, queue, printing, scheduler y postgres 18.
- **En el mini PC**: `install.sh` (una vez), `tpv-update <versión>` (con vuelta
  atrás automática), `tpv-restore <copia>`.
- **Copias**: `tpv:backup` cifrado con age, tras cada Z, al arrancar si hace
  más de 24 h de la última y a las 05:00; rotación 7/4/6 y subida con rclone.

Probar la producción en el Mac: `scripts/build-image.sh` y levantar
`docker-compose.prod.yml` con un `.env` sacado de `docker/production/env.example`
(`APP_HTTP_PORT=8090`, `PRINTER_MODE=simulated`). La imagen no incluye Faker:
los datos de demo se cargan con un volcado de la base de datos de desarrollo y
`tpv-restore`.

## Problemas típicos

| Síntoma | Causa / solución |
|---|---|
| El backoffice va lento en desarrollo | `PHP_CLI_SERVER_WORKERS=4` en `.env` y `sail restart laravel.test` (Filament pide muchos ficheros a la vez). |
| `/estado` da 500 "Vite manifest not found" | No está corriendo `npm run dev` (o falta `npm run build`). |
| En el móvil la página sale en blanco | El móvil no llega a Vite: revisa `VITE_HMR_HOST` (IP actual del Mac), el firewall de macOS para `node` y que estás en la misma wifi. |
| El Mac ha cambiado de IP (página en blanco, incluso en el Mac) | Pasa al cambiar de red o al renovar el router la IP. Mira la nueva con `ipconfig getifaddr en0`, actualiza `VITE_HMR_HOST` y `SANCTUM_STATEFUL_DOMAINS`, reinicia `npm run dev` y `sail restart`. Mejor: reserva una IP fija para el Mac en el router. |
| He cambiado el `.env` y la impresión sigue igual | Los workers leen el `.env` al arrancar: `sail restart queue printing`. |
| La carta del TPV no se actualiza | Se recarga sola al editarla en /admin. Si no, recarga la página; en último caso `sail artisan cache:clear`. |
| POST a la API da 419 (CSRF) | El host desde el que entras no está en `SANCTUM_STATEFUL_DOMAINS` (incluye la variante con `:8081`). |
| Socket en `unavailable`/`failed` | Reverb parado o puerto 8080 bloqueado: `sail logs reverb`. Tras cambiar `.env`: `sail restart reverb`. |
| El evento no llega pero el socket está `connected` | Laravel no llega a Reverb: `REVERB_HOST` debe ser `reverb` (servicio Docker) y `BROADCAST_CONNECTION=reverb`. |
| Reverb muere al arrancar con "relation cache does not exist" | Arrancó antes de migrar; se reinicia solo (`restart: unless-stopped`). Ejecuta `sail artisan migrate`. |
| El botón de imprimir no hace nada | Worker de colas parado: `sail logs queue`. |
| Caracteres raros en el ticket | Cambia `PRINTER_CODEPAGE` (ver sección Impresora). |
| `port is already allocated` | Otro programa usa el puerto: cambia `APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_REVERB_PORT` o `VITE_PORT` en `.env`. |
| `docker: command not found` | Añade `~/.docker/bin` al PATH (ver Requisitos). |
