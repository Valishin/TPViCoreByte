/**
 * Pantalla táctil: al hacer scroll con el dedo no se pulsa lo que hay debajo.
 *
 * El navegador cuenta como clic un toque que se ha movido poco (sin llegar a
 * hacer scroll) y el toque que frena una lista que aún se desliza. Aquí se
 * anula el clic de un toque (dedo o lápiz; el ratón no) si:
 *   - el dedo se ha movido más de MOVE_PX desde donde tocó;
 *   - algo ha hecho scroll mientras el dedo estaba puesto y se había movido;
 *   - empezó mientras una lista aún se deslizaba tras soltarla con el dedo
 *     (scroll hace < AFTER_SCROLL_MS).
 * El scroll que hace la propia pantalla (p. ej. bajar al último producto
 * añadido) no cuenta: tocar rápido dos veces sigue funcionando. Los clics del
 * teclado (Intro, espacio) no se tocan.
 */
export const MOVE_PX = 10;
export const AFTER_SCROLL_MS = 150;
const CLICK_WINDOW_MS = 800;

let installed = null;

export function startTouchClickGuard(target = window) {
    if (installed) return installed;
    let gesture = null;
    let lastScroll = -Infinity;
    let fingerScroll = false; // el último scroll lo empezó un dedo (y puede seguir deslizándose)
    const now = () => performance.now();

    const listeners = {
        pointerdown(e) {
            if (e.pointerType === 'mouse') {
                gesture = null;
                return;
            }
            const sliding = fingerScroll && now() - lastScroll < AFTER_SCROLL_MS;
            if (!sliding) fingerScroll = false;
            gesture = { id: e.pointerId, x: e.clientX, y: e.clientY, moved: 0, cancel: sliding, endedAt: null };
        },
        pointermove(e) {
            if (!gesture || e.pointerId !== gesture.id || gesture.endedAt !== null) return;
            gesture.moved = Math.max(gesture.moved, Math.hypot(e.clientX - gesture.x, e.clientY - gesture.y));
            if (gesture.moved > MOVE_PX) gesture.cancel = true;
        },
        pointerup(e) {
            if (gesture && e.pointerId === gesture.id) gesture.endedAt = now();
        },
        pointercancel(e) {
            // El navegador se ha quedado el gesto para hacer scroll.
            if (gesture && e.pointerId === gesture.id) Object.assign(gesture, { cancel: true, endedAt: now() });
            fingerScroll = true;
        },
        scroll() {
            lastScroll = now();
            // Con el dedo puesto y movido: es el dedo el que arrastra la lista.
            if (gesture && gesture.endedAt === null && gesture.moved > 2) {
                gesture.cancel = true;
                fingerScroll = true;
            }
        },
        click(e) {
            const g = gesture;
            if (!g || e.detail === 0) return; // sin toque reciente o clic de teclado
            gesture = null;
            if (g.cancel && g.endedAt !== null && now() - g.endedAt < CLICK_WINDOW_MS) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        },
    };
    const options = { capture: true, passive: false };
    for (const [type, fn] of Object.entries(listeners)) {
        // scroll no burbujea: en captura se ven también los de cada lista.
        (type === 'scroll' ? document : target).addEventListener(type, fn, options);
    }

    installed = () => {
        for (const [type, fn] of Object.entries(listeners)) {
            (type === 'scroll' ? document : target).removeEventListener(type, fn, options);
        }
        installed = null;
    };
    return installed;
}
