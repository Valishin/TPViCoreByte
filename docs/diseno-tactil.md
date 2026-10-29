# Diseño táctil y teclado en pantalla

Requisitos de diseño de la SPA del TPV (caja y móviles). Recoge lo definido en
las fases 5-6 (sala), 9 (cobro y caja) y los ajustes posteriores. Toda pantalla
nueva debe cumplirlos.

## Contexto

- Pensado para un **bar en pleno servicio**: se usa de pie, con prisa, con los
  dedos mojados y a veces a pleno sol en la terraza.
- **Una sola app con dos disposiciones:**
  - **Caja**: pantalla táctil horizontal de **≥ 1024 px** (el mini PC, Chromium
    en modo kiosko).
  - **Móvil**: vertical, los móviles de los camareros por la IP local.

## Reglas generales

1. **Alto contraste** y **legible a un metro de distancia**.
2. **Objetivos táctiles mínimos: 64 px en caja y 48 px en móvil.**
3. **Sin animaciones lentas** (como mucho una transición breve).
4. **Ninguna acción frecuente debe necesitar más de 2 toques.**
5. **Evitar confirmaciones**, salvo para **anular algo ya enviado** (y acciones
   irreversibles equivalentes: anular un ticket, cerrar caja con mesas abiertas).
6. **Temas:** **oscuro por defecto en caja** y **claro disponible en ambas**
   disposiciones, con un interruptor que se recuerda **por dispositivo**
   (localStorage). **Ambos de alto contraste.**
7. **Estilos con SCSS y metodología BEM.** Sin Tailwind ni frameworks
   utility-first ni frameworks de UI en la SPA.
8. Antes de programar pantallas: **tokens** (colores, tipografía, espaciados,
   tamaños táctiles) y una **librería de componentes BEM reutilizables**: botón,
   teclado numérico, hoja inferior, modal, tarjeta de mesa, tarjeta de
   producto, línea de pedido y toast.

## Elementos siempre visibles

- **Camarero actual**, **indicador de conexión** y **avisos de impresora**.
- **Botón de bloquear** (en móvil, dentro del menú de la barra).
- **Barra superior en móvil:** solo el camarero, la conexión y los avisos. El
  **nombre del local solo en caja** (recortado en móvil no aporta).
- **Avisos de impresión persistentes** (no un toast que desaparece) en todas
  las pantallas, con mesa e impresora, y acciones **Reintentar** y **Enviar a
  otra impresora**.

## Teclado en pantalla

### Reglas del teclado y del ratón

- **En la caja no se usa el teclado del sistema** (el del sistema operativo o
  el del navegador en modo kiosko): todo se introduce con teclados propios de la
  app.
- **Números** (PIN, importes, cantidades, comensales, fondo y arqueo):
  **teclado numérico propio**.
- **Notas rápidas configurables en Filament:** cada categoría tiene su lista
  ordenable ("Sin hielo", "Poco hecha"…) y hay unas **generales del local**
  (Ajustes del local) para todas. En la línea del pedido salen como botones,
  primero las de la categoría y después las generales: **un toque añade o
  quita**, se pueden marcar varias, y **"Nota libre"** queda para lo demás. La
  nota de la línea es un solo texto: las rápidas en su orden y detrás la libre.
- **Teclado en pantalla propio para texto** (`c-keyboard`): QWERTY español
  con **ñ**, fila de números, signos básicos (`, . - / @ º ª #`), espacio
  grande, borrar y **"Hecho"**. **Acentos con tecla muerta:** `´` y después la
  vocal da á é í ó ú (mientras está pendiente, las vocales ya se ven con
  tilde); `¨` + u da ü; dos pulsaciones escriben el signo. Mayúsculas: un toque
  para una letra, dos para bloquear. Pone mayúscula al empezar el campo o la
  frase (no en email/URL).
  - Teclas de **64 px de alto** como mínimo.
  - **Aparece al enfocar cualquier campo de texto en la caja**. **El scroll no
    se mueve al tocar un campo**: las hojas y modales suben por encima del
    teclado y el teclado muestra arriba el campo que se edita (etiqueta y
    texto), así que siempre se ve lo que se escribe.
  - **Más scroll con el teclado abierto:** la pantalla gana abajo el alto del
    teclado, así que se puede bajar hasta el final y ver lo que se escribe
    aunque el campo esté abajo del todo.
  - **Tecla "Ocultar":** esconde el teclado sin soltar el campo (se puede
    seguir con el teclado físico); vuelve al tocar el campo. "Hecho" confirma
    y suelta el campo.
  - **Modo numérico** (1-9, 0, `+`, `-`, `.`, espacio) para teléfono, código
    postal…
  - **Convive con el teclado físico:** se puede escribir con los dos en el
    mismo campo.
  - **En caja nunca se abre el teclado del sistema** (`inputmode="none"` en
    todos los campos de texto, también con el ajuste desactivado).
  - **En móvil no se usa:** se usa el teclado del teléfono (numérico en los
    campos numéricos).
- **Ajuste "Mostrar teclado en pantalla en caja"** (Ajustes del local → TPV,
  activado por defecto). Desactivado, en la caja solo se escribe con el
  teclado físico.
- **El ratón no puede romper nada:** todo funciona igual con un clic que con
  un toque (sin gestos que solo existan en táctil, sin dobles disparos con
  dobles clics). **Nada depende del hover:** ninguna acción ni información se
  ve solo al pasar el ratón por encima. El hover puede cambiar el color, pero
  nada más.

> **Estado (29/10/2026):** todo lo anterior está implementado (teclados
> numéricos, teclado en pantalla con modo numérico, ajuste del local y notas
> rápidas por categoría y generales).

### PIN (entrada y autorización de encargado)

- **Rejilla de usuarios** con iniciales y color, y **teclado numérico grande**
  para el PIN.
- **Igual en caja y en móvil:** primero **"Seleccione el usuario"** (personal
  de sala y, aparte, **Repartidores**); al tocar uno, la lista se sustituye por
  el usuario elegido (con **"Cambiar"**) y el teclado con **"Introduce tu
  PIN"**. En caja, todo **centrado**.
- Tras un bloqueo, el usuario que estaba ya viene elegido: solo teclea el PIN.
- La **autorización de encargado** se pide en un modal (elegir encargado y
  teclear su PIN) **sin cerrar la sesión del camarero**.

### Importes (cobro, fondo de caja, arqueo)

- **Entrada en euros normales, no tipo datáfono:** teclear **"20" son
  20,00 €**; la tecla **","** introduce los céntimos (**máximo 2 decimales**);
  **tecla de borrar**.
- **Botones rápidos de billetes (5, 10, 20, 50 €) e "Importe exacto".**
- En **tarjeta y Bizum** (datáfono externo) se **propone por defecto el
  importe pendiente**; solo se confirma.
- **Teclado numérico propio** para el fondo inicial y el arqueo, con
  **desglose opcional por billetes y monedas**.

## Pantallas

### Plano de mesas

- Pestañas por sala. En **caja**, mesas colocadas según su posición y con su
  forma; en **móvil**, **rejilla compacta**.
- **Colores por estado**, **importe** y **tiempo abierta** en cada mesa.
- Mesa libre: al tocarla se piden los comensales y se abre.
- Mesa con **varias cuentas**: muestra **"N cuentas"** con el total de todas y
  al tocarla se elige cuál abrir (importe y líneas resumidas).

### Comanda

- **Caja:** categorías (con su color), productos y líneas del pedido **a la vez**.
- **Móvil:** categorías → productos, con el resumen del pedido en una **hoja
  inferior desplegable**.
- Producto con variantes, modificadores, menú o mitad y mitad: **hoja de
  configuración paso a paso** con validación de mínimos/máximos.
- **Las líneas pendientes de enviar se distinguen claramente** de las enviadas:
  **arriba y destacadas**. Las enviadas, **agrupadas por envío**
  ("1.er envío 21:14", "2.º envío 21:32"), cada envío con su estado de
  impresión (impreso / fallido).
- **Botón "Enviar" grande con el número de líneas pendientes.**

### Cobro

- **Resumen grande**: líneas, descuentos, desglose de IVA y **TOTAL muy visible**.
- **Métodos de pago con botones grandes**: Efectivo, Tarjeta, Bizum, Otro.
- **Cambio mostrado en grande.**
- El botón **Cobrar se deshabilita mientras el cobro está en curso**.

---

## Cómo se aplica en el código

- Tokens en `resources/scss/_tokens.scss` y temas en `_themes.scss`
  (`html[data-theme]`). Tamaño táctil en `var(--touch)`: 48 px y 64 px con
  `html[data-layout="pos"]`.
- La disposición la decide `resources/js/composables/useLayout.js` (caja si la
  pantalla es horizontal y de al menos 1024 px).
- Componentes BEM en `resources/scss/components/` (`c-…`) y vistas en
  `resources/scss/views/` (`v-…`). Teclados: `NumericKeypad.vue` (PIN y
  números) y `AmountKeypad.vue` (importes en euros).
- Teclado en pantalla: `components/OnScreenKeyboard.vue` (montado una vez en
  `App.vue`, escucha el foco de **cualquier** `<input>` de texto o
  `<textarea>`) con la lógica en `composables/useOnScreenKeyboard.js`. Para un
  campo nuevo no hay que hacer nada; para elegir el modo:
  `<TextField v-model="…" label="Teléfono" keyboard="numeric" />` o la
  directiva `v-keyboard="'numeric'"` (`'off'` lo desactiva en ese campo).
  Estilos en `scss/components/_keyboard.scss`.
- Notas rápidas: `components/QuickNotes.vue` con `catalog.quickNotesFor(productId)`
  y `utils/notes.js`; vienen en la carta (`/api/catalog`: `categories[].quick_notes`
  y `quick_notes` generales).
