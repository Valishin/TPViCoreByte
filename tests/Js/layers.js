/**
 * Toque "de verdad" en la pantalla: el dedo cae en la ventana superior (la de
 * mayor z-index que no está inert), nunca en las de debajo. jsdom no calcula
 * posiciones, así que se elige la capa igual que lo haría el navegador.
 */
export function topLayer() {
    const layers = [...document.querySelectorAll('[data-layer]')].filter((el) => !el.closest('[inert]'));
    return layers.sort((a, b) => Number(b.style.zIndex) - Number(a.style.zIndex))[0] ?? null;
}

export function buttonIn(root, text) {
    return [...root.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
}

/** Toca un botón por su texto en la ventana superior. */
export async function tap(text, flush) {
    const layer = topLayer();
    const button = layer && buttonIn(layer, text);
    if (!button) throw new Error(`No hay «${text}» en la ventana superior`);
    button.click();
    await flush();
}
