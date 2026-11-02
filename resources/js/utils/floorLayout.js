/**
 * Posiciones del plano de la caja sin mesas superpuestas: una mesa que se
 * pisa con otra ya colocada (p. ej. dos en 0,0) se mueve al primer hueco
 * libre (cada 3 celdas, 4 por fila, como en el servidor: App\Support\FloorLayout).
 * Así ninguna mesa —ni su pedido abierto— desaparece debajo de otra.
 */
const STEP = 3;
const PER_ROW = 4;

function overlaps(a, b) {
    return a.x < b.x + b.w * 2 && b.x < a.x + a.w * 2 && a.y < b.y + b.h * 2 && b.y < a.y + a.h * 2;
}

/** @returns {Map<number, {x: number, y: number, w: number, h: number}>} */
export function layoutTables(tables) {
    const placed = [];
    const result = new Map();
    const ordered = [...tables].sort((a, b) => a.id - b.id);   // la más antigua se queda en su sitio
    for (const t of ordered) {
        let rect = { x: t.x ?? 0, y: t.y ?? 0, w: t.w || 1, h: t.h || 1 };
        if (placed.some((p) => overlaps(rect, p))) {
            for (let i = 0; ; i++) {
                const candidate = { ...rect, x: (i % PER_ROW) * STEP, y: Math.floor(i / PER_ROW) * STEP };
                if (!placed.some((p) => overlaps(candidate, p))) {
                    rect = candidate;
                    break;
                }
            }
        }
        placed.push(rect);
        result.set(t.id, rect);
    }
    return result;
}
