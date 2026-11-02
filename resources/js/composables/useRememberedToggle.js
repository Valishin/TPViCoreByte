import { ref, watch } from 'vue';

/**
 * Casilla que se recuerda en este dispositivo (p. ej. «Imprimir ticket» al
 * cobrar): si se desmarca, sigue desmarcada al volver a abrir la pantalla o
 * reiniciar la caja. Sin nada guardado, vale `fallback` (el ajuste de /admin).
 */
export function useRememberedToggle(key, fallback) {
    const storageKey = `tpv.toggle.${key}`;
    let initial = fallback;
    try {
        const saved = localStorage.getItem(storageKey);
        if (saved === '1' || saved === '0') initial = saved === '1';
    } catch {
        // Sin almacenamiento (modo privado…): se usa el valor por defecto.
    }
    const value = ref(Boolean(initial));
    watch(value, (v) => {
        try {
            localStorage.setItem(storageKey, v ? '1' : '0');
        } catch {
            // Sin almacenamiento: solo dura mientras la pantalla esté abierta.
        }
    });

    return value;
}
