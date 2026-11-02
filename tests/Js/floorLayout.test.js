import { expect, it } from 'vitest';
import { layoutTables } from '@/utils/floorLayout';

const table = (id, x, y, w = 1, h = 1) => ({ id, x, y, w, h });

it('las mesas en el mismo sitio no se dibujan una encima de otra', () => {
    const layout = layoutTables([table(1, 0, 0), table(2, 0, 0), table(3, 0, 0)]);

    const spots = [...layout.values()].map((p) => `${p.x},${p.y}`);
    expect(new Set(spots).size).toBe(3);
    expect(layout.get(1)).toMatchObject({ x: 0, y: 0 });   // la más antigua se queda
});

it('respeta las que ya están bien colocadas', () => {
    const tables = [table(1, 0, 0), table(2, 3, 0), table(3, 6, 0, 2, 1)];
    const layout = layoutTables(tables);

    expect(tables.map((t) => layout.get(t.id))).toEqual(tables.map(({ x, y, w, h }) => ({ x, y, w, h })));
});

it('una mesa grande que pisa a otra también se mueve', () => {
    const layout = layoutTables([table(1, 0, 0), table(2, 1, 1, 2, 2)]);
    const [a, b] = [layout.get(1), layout.get(2)];

    const overlap = a.x < b.x + b.w * 2 && b.x < a.x + a.w * 2 && a.y < b.y + b.h * 2 && b.y < a.y + a.h * 2;
    expect(overlap).toBe(false);
});
