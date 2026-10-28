import { onUnmounted, watch } from 'vue';

/**
 * Llama a onIdle tras `minutes()` minutos sin tocar la pantalla.
 * 0 = desactivado. Se reinicia con cada toque o tecla.
 */
export function useInactivity(minutes, onIdle, enabled) {
    let timer = null;
    const events = ['pointerdown', 'keydown', 'wheel'];

    function reset() {
        clearTimeout(timer);
        const m = minutes();
        if (enabled() && m > 0) timer = setTimeout(onIdle, m * 60000);
    }

    events.forEach((e) => window.addEventListener(e, reset, { passive: true }));
    watch([minutes, enabled], reset, { immediate: true });

    onUnmounted(() => {
        clearTimeout(timer);
        events.forEach((e) => window.removeEventListener(e, reset));
    });
}
