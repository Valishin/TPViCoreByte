import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Teclado físico (USB) para los teclados en pantalla hechos con botones
 * (PIN, teléfono, importes…): las cifras, «,» / «.», Retroceso, Supr e Intro
 * hacen lo mismo que tocar la tecla.
 *
 * Solo atiende uno: el último montado que esté visible y en la ventana de
 * arriba (fuera de un `inert`). Si el foco está en un campo de texto, las
 * teclas son para ese campo y aquí no se hace nada.
 */
const keypads = [];

function editable(target) {
    if (!(target instanceof HTMLElement)) return false;
    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}
function usable(entry) {
    const el = entry.el.value;
    return !!el && el.isConnected && !el.closest('[inert]') && el.getClientRects().length > 0 && !entry.disabled?.();
}

function onKeydown(event) {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || editable(event.target)) return;
    const entry = [...keypads].reverse().find(usable);
    if (!entry) return;
    if (entry.handle(event.key) === true) event.preventDefault();
}

/**
 * @param {import('vue').Ref<HTMLElement|null>} el  raíz del teclado en pantalla
 * @param {(key: string) => boolean|void} handle  devuelve true si ha usado la tecla
 * @param {() => boolean} [disabled]
 */
export function usePhysicalKeypad(el, handle, disabled) {
    const entry = { el, handle, disabled };
    onMounted(() => {
        if (keypads.length === 0) window.addEventListener('keydown', onKeydown);
        keypads.push(entry);
    });
    onBeforeUnmount(() => {
        const i = keypads.indexOf(entry);
        if (i >= 0) keypads.splice(i, 1);
        if (keypads.length === 0) window.removeEventListener('keydown', onKeydown);
    });
}
