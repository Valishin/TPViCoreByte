import { applyInputMode } from '@/composables/useOnScreenKeyboard';

/**
 * v-keyboard: modo del teclado en pantalla de un campo.
 *   <input v-keyboard>              texto (es el modo por defecto)
 *   <input v-keyboard="'numeric'">  teléfono, código postal…
 *   <input v-keyboard="'off'">      sin teclado en pantalla
 * En móvil, el modo numérico pide al teléfono su teclado numérico.
 */
function apply(el, value) {
    const mode = value ?? 'text';
    el.dataset.keyboard = mode;
    if (mode === 'numeric' && !el.hasAttribute('inputmode') && el.dataset.oskInputmode === undefined) {
        el.setAttribute('inputmode', 'numeric');
    }
    applyInputMode(el);
}

export default {
    mounted: (el, binding) => apply(el, binding.value),
    updated: (el, binding) => {
        if (binding.value !== binding.oldValue) apply(el, binding.value);
    },
};
