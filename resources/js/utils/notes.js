/**
 * La nota de una línea es un solo texto ("Sin hielo, Con limón, para el niño"):
 * las notas rápidas elegidas, en el orden configurado, y detrás la nota libre.
 */
const SEP = ', ';
const key = (s) => s.trim().toLocaleLowerCase('es');

function parts(notes) {
    return (notes ?? '').split(',').map((s) => s.trim()).filter(Boolean);
}

/** @returns {{ selected: string[], free: string }} */
export function splitNotes(notes, quick) {
    const quickKeys = new Map(quick.map((q) => [key(q), q]));
    const selected = new Set();
    const free = [];
    for (const part of parts(notes)) {
        const match = quickKeys.get(key(part));
        match ? selected.add(match) : free.push(part);
    }
    return { selected: quick.filter((q) => selected.has(q)), free: free.join(SEP) };
}

export function joinNotes(selected, free) {
    return [...selected, free.trim()].filter(Boolean).join(SEP);
}
