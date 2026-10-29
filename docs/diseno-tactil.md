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
- **Notas rápidas configurables por categoría en Filament:** cada categoría
  tiene su lista de notas ("Sin sal", "Poco hecho"…), que salen como botones
  al añadir un producto o editar una línea. Se pueden tocar varias, y el
  texto libre queda para los casos raros.
- **Teclado en pantalla propio para texto** (QWERTY español con **ñ** y
  **acentos**: á é í ó ú ü, y mayúsculas). **Aparece al enfocar un campo de
  texto en la caja** (notas, motivos, nombres) y **convive con el teclado
  físico**: si hay uno conectado, se puede escribir con los dos y no se pierde
  nada.
- **Ajuste "Mostrar teclado en pantalla en caja"** (Ajustes en Filament,
  activado por defecto). Desactivado, en la caja solo se usa el teclado físico.
  En **móvil** se usa el teclado del propio teléfono.
- **El ratón no puede romper nada:** todo funciona igual con un clic que con
  un toque (sin gestos que solo existan en táctil, sin dobles disparos con
  dobles clics). **Nada depende del hover:** ninguna acción ni información se
  ve solo al pasar el ratón por encima. El hover puede cambiar el color, pero
  nada más.

> **Estado (29/10/2026):** de esto ya están hechos los teclados numéricos
> (`NumericKeypad`, `AmountKeypad`) y que nada dependa del hover. **Falta**:
> el teclado QWERTY en pantalla, el ajuste "Mostrar teclado en pantalla en
> caja" y que las notas rápidas sean por categoría. Hoy son una lista fija en
> `ProductConfigurator.vue` y `LineActions.vue`, y los campos de texto abren
> el teclado del sistema.

### PIN (entrada y autorización de encargado)

- **Rejilla de usuarios** con iniciales y color, y **teclado numérico grande**
  para el PIN.
- **Escritorio / caja:** los **nombres arriba** y el **teclado debajo**, todo
  **centrado**. Títulos **"Seleccione el usuario"** encima de los usuarios e
  **"Introduce tu PIN"** encima del teclado.
- El teclado del PIN está **en gris y bloqueado hasta que se selecciona el
  usuario**.
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
  números) y `AmountKeypad.vue` (importes en euros). Teclado de texto previsto:
  un componente `OnScreenKeyboard.vue` que se abra al enfocar un campo
  (`c-field__input`) en la disposición de caja, según el ajuste del local.
