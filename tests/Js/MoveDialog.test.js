import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const rooms = [
    { id: 1, name: 'Comedor', tables: [
        { id: 11, name: '1', status: 'ocupada', seats: 4, pos_x: 0, pos_y: 0, width: 1, height: 1 },
        { id: 12, name: '2', status: 'libre', seats: 4, pos_x: 3, pos_y: 0, width: 1, height: 1 },
        { id: 13, name: '3', status: 'ocupada', seats: 4, pos_x: 6, pos_y: 0, width: 1, height: 1 },
    ] },
    { id: 2, name: 'Terraza', tables: [{ id: 21, name: '20', status: 'libre', seats: 2, pos_x: 0, pos_y: 0, width: 1, height: 1 }] },
];
vi.mock('@/services/pos', () => ({ default: { rooms: vi.fn(() => Promise.resolve({ data: rooms, unplaced: [] })) } }));

import MoveDialog from '@/components/MoveDialog.vue';
import { resetLayers } from '@/composables/useLayer';
import { useFloorStore } from '@/stores/floor';

let wrapper;
beforeEach(() => {
    setActivePinia(createPinia());
    useFloorStore().rooms = rooms;
});
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});
const card = (name) => [...document.body.querySelectorAll('.c-table-card')].find((c) => c.textContent.trim().startsWith(name));

describe('Mover a otra mesa (con el plano, como «Unir mesas»)', () => {
    it('muestra el plano de la sala actual: esta mesa, libres y ocupadas', async () => {
        wrapper = mount(MoveDialog, { props: { open: false, order: { table_label: 'Mesa 1', table: { id: 11 } } }, attachTo: document.body });
        await wrapper.setProps({ open: true });
        await flushPromises();

        expect(document.body.textContent).toContain('Mover Mesa 1 a…');
        expect(card('1').className).toContain('c-table-card--current');
        expect(card('2').className).toContain('c-table-card--selectable');
        expect(card('3').className).toContain('c-table-card--disabled');
        expect(document.body.textContent).toContain('Terraza · 1 libres');
    });

    it('al tocar una libre se elige y «Mover a Mesa 2» la confirma; las ocupadas no se eligen', async () => {
        wrapper = mount(MoveDialog, { props: { open: false, order: { table_label: 'Mesa 1', table: { id: 11 } } }, attachTo: document.body });
        await wrapper.setProps({ open: true });
        await flushPromises();

        card('3').click();
        await flushPromises();
        expect(document.body.textContent).toContain('Elige una mesa libre');

        card('2').click();
        await flushPromises();
        const button = [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Mover a Mesa 2'));
        button.click();
        expect(wrapper.emitted('confirm')).toEqual([[12]]);
    });
});
