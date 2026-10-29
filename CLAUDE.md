# TPV Hostelería — reglas del proyecto

TPV local-first para hostelería: Laravel 13 (PHP 8.4) + PostgreSQL + Sanctum
(SPA con cookies) + Reverb + Filament 5 (/admin) + Vue 3 SPA. Entorno con Sail
(`./vendor/bin/sail …`); Vite se ejecuta en el host (`npm run dev`). Ver
README.md y docs/.

## Reglas clave (obligatorias)

1. **Diseño:** toda pantalla de la SPA cumple [docs/diseno-tactil.md](docs/diseno-tactil.md)
   (64 px en caja / 48 px en móvil, alto contraste, ≤ 2 toques, teclados propios…).
2. **Importes en céntimos** (`integer`). Nunca floats. IVA y porcentajes en
   puntos básicos (1000 = 10 %). Aritmética con `App\Support\Money`; formato con
   `App\Support\Format` (PHP) y `resources/js/utils/format.js` (JS).
3. **Tickets inmutables:** no se editan ni se borran (modelo + trigger de
   PostgreSQL). Solo cambian `payment_status`/`paid_at` y `status` de emitido a
   rectificado. Para corregir: rectificativo (`RectificationService`).
4. **Snapshots:** las líneas de pedido y de ticket copian nombre, precio, IVA
   aplicado y modificadores; el pedido a domicilio copia nombre, teléfono y
   dirección del cliente; el ticket copia los datos fiscales del emisor.
   Cambiar la carta o un cliente nunca altera pedidos ni tickets.
5. **SPA con SCSS + BEM**, sin Tailwind ni frameworks de UI (`c-` componentes,
   `v-` vistas, tokens en `resources/scss/_tokens.scss`). Filament usa lo suyo.
6. **El estado de las mesas (`tables.status`) solo lo cambia `OrderService`**
   (los demás servicios lo llaman: `OrderSender`, `TicketIssuer`). Nunca desde controladores,
   Filament ni la SPA.
7. **Cobros con idempotencia:** `/checkout` exige `idempotency_key` (una por
   intento de cobro); la misma clave devuelve el mismo ticket.
8. **La exportación fiscal debe funcionar siempre**, también en el futuro modo
   solo consulta (`App\Support\ReadOnlyMode`, `#[AllowedInReadOnly]`).

## Otras convenciones

- Identificadores en inglés; valores de enum y textos de interfaz en español.
- Lógica de negocio en servicios (`app/Services`); controladores finos.
- Eventos de pantalla: `ShouldBroadcastNow` + `ShouldDispatchAfterCommit` en el
  canal privado `pos`. La impresión va por la cola `printing` (`print_jobs`);
  un envío fallido nunca se reimprime solo.
- Autorización de encargado con `AuthorizationService` (token de un solo uso).
- Tests contra PostgreSQL (`./vendor/bin/sail test`); toda la suite debe pasar.
  Formato con `./vendor/bin/sail bin pint`.
- Commits pequeños por bloques, en español.
