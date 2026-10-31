/**
 * Mesas unidas en el plano: color de cada grupo (siempre con la etiqueta
 * «3+4», nunca solo el color) y líneas que relacionan sus mesas en caja.
 */
const PALETTE = 6;

export function groupColor(group) {
    return `var(--c-group-${group.main_table_id % PALETTE})`;
}

/** Centro de una mesa en unidades de la cuadrícula del plano (cada unidad de tamaño = 2 celdas). */
function center(t) {
    return { x: t.x + t.w, y: t.y + t.h };
}

/**
 * Segmentos de la mesa principal a cada mesa unida de la misma sala.
 * @returns {{ key: string, x1: number, y1: number, x2: number, y2: number, color: string }[]}
 */
export function groupLinks(tables) {
    const byId = new Map(tables.map((t) => [t.id, t]));
    const links = [];
    for (const t of tables) {
        const g = t.group;
        if (!g || g.is_main) continue;
        const main = byId.get(g.main_table_id);
        if (!main) continue;
        const a = center(main);
        const b = center(t);
        links.push({ key: `${main.id}-${t.id}`, x1: a.x, y1: a.y, x2: b.x, y2: b.y, color: groupColor(g) });
    }
    return links;
}
