import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Pila de ventanas (modales, hojas y pantallas superpuestas).
 *
 * - La última abierta queda SIEMPRE encima (z-index según su posición en la
 *   pila, no según el orden en el DOM) y es la única que recibe toques y
 *   teclas: las de debajo y la app quedan `inert` (sin clics, foco ni
 *   teclado físico).
 * - Al abrirse, el foco pasa a la ventana nueva; al cerrarse, vuelve a donde
 *   estaba si sigue disponible.
 * - El teclado en pantalla y los avisos van por encima de la pila
 *   ($z-keyboard, $z-toast) y escriben en el campo con foco, que solo puede
 *   estar en la ventana superior.
 */
const BASE_Z = 100;
const STEP_Z = 10;

const stack = reactive([]);

/** ¿Hay alguna ventana abierta? (la app de debajo queda inert) */
export const layerOpen = computed(() => stack.length > 0);
export const layerDepth = computed(() => stack.length);

let seq = 0;

export function useLayer(open) {
    const id = ++seq;
    const el = ref(null);
    let restore = null;

    const index = computed(() => stack.indexOf(id));
    const isTop = computed(() => stack.length > 0 && stack[stack.length - 1] === id);
    const zIndex = computed(() => BASE_Z + Math.max(0, index.value) * STEP_Z);

    function push() {
        if (stack.includes(id)) return;
        restore = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        stack.push(id);
        nextTick(() => {
            // El foco va a la ventana (no a su primer campo: no abre teclados sin querer).
            const target = el.value?.querySelector('[data-layer-focus]') ?? el.value;
            target?.focus?.({ preventScroll: true });
        });
    }
    function pop() {
        const i = stack.indexOf(id);
        if (i < 0) return;
        stack.splice(i, 1);
        const back = restore;
        restore = null;
        nextTick(() => {
            if (back?.isConnected && !back.closest('[inert]')) back.focus({ preventScroll: true });
        });
    }

    watch(() => Boolean(open.value), (value) => (value ? push() : pop()), { immediate: true });
    onBeforeUnmount(pop);

    return {
        el,
        isTop,
        zIndex,
        /** Atributos para el elemento raíz de la ventana. */
        attrs: computed(() => ({
            style: { zIndex: zIndex.value },
            inert: isTop.value ? null : '',
            tabindex: '-1',
            'data-layer': index.value,
        })),
    };
}

/** Solo para tests. */
export function resetLayers() {
    stack.splice(0, stack.length);
}
