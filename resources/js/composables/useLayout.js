import { ref } from 'vue';

/**
 * Disposición: "pos" (caja: pantalla horizontal de al menos 1024 px) o
 * "mobile". Se refleja en html[data-layout] para el CSS.
 */
const query = window.matchMedia('(min-width: 1024px) and (orientation: landscape)');
export const layout = ref(query.matches ? 'pos' : 'mobile');

function apply() {
    layout.value = query.matches ? 'pos' : 'mobile';
    document.documentElement.dataset.layout = layout.value;
}
query.addEventListener('change', apply);
apply();

export function useLayout() {
    return { layout };
}
