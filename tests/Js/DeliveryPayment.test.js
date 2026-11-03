import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/services/pos', () => ({ default: { couriers: vi.fn(() => Promise.resolve([{ id: 7, name: 'Javi', initials: 'JA', color: '#09f' }])) } }));

import DispatchSheet from '@/components/DispatchSheet.vue';
import TakeawayPaymentPicker from '@/components/TakeawayPaymentPicker.vue';
import { resetLayers } from '@/composables/useLayer';

let wrapper;
beforeEach(() => setActivePinia(createPinia()));
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});
const buttons = () => [...document.body.querySelectorAll('button')].map((b) => b.textContent.trim());

describe('Pago de un domicilio', () => {
    it('al enviar un domicilio: efectivo o tarjeta, sin «Ya pagado»', () => {
        wrapper = mount(TakeawayPaymentPicker, { props: { total: 2150, allowPrepaid: false }, attachTo: document.body });
        expect(wrapper.findAll('[role="radio"]').map((b) => b.text())).toEqual(['Efectivo', 'Tarjeta']);
    });

    it('«Hora y pago» conserva «Ya pagado» (pedidos pagados online)', () => {
        wrapper = mount(TakeawayPaymentPicker, { props: { total: 2150 }, attachTo: document.body });
        expect(wrapper.findAll('[role="radio"]').map((b) => b.text())).toContain('Ya pagado');
    });

    const open = async (couriers) => {
        const pos = (await import('@/services/pos')).default;
        pos.couriers.mockResolvedValue(couriers);
        const order = { id: 3, customer_name: 'Lucía', cash_change_for: 5000, change_needed: 2850 };
        wrapper = mount(DispatchSheet, { props: { order: null }, attachTo: document.body });
        await wrapper.setProps({ order });
        await flushPromises();
    };
    const tap = async (text) => {
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes(text)).click();
        await flushPromises();
    };
    const box = () => document.body.querySelector('input[type="checkbox"]');

    it('cambio para 50 € y repartidor sin fondo: el cambio sale de la caja (marcado)', async () => {
        await open([{ id: 7, name: 'Javi', initials: 'JA', color: '#09f', float: 0 }]);
        await tap('Javi');
        expect(wrapper.emitted('confirm')).toBeUndefined();   // primero se elige; la casilla depende del repartidor
        expect(box().checked).toBe(true);
        expect(document.body.textContent).toContain('Le doy el cambio desde la caja: 28,50');
        expect(document.body.textContent).toContain('al liquidar entregará 50,00');

        await tap('Dar salida con Javi');
        expect(wrapper.emitted('confirm')[0][2]).toBe(true);
    });

    it('repartidor que ya lleva fondo: desmarcada (da el cambio de su fondo)', async () => {
        await open([{ id: 8, name: 'Sara', initials: 'SA', color: '#f00', float: 3000 }]);
        expect(document.body.textContent).toContain('lleva fondo de 30,00');
        await tap('Sara');
        expect(box().checked).toBe(false);
        expect(document.body.textContent).toContain('Da el cambio de su fondo (30,00');

        await tap('Dar salida con Sara');
        expect(wrapper.emitted('confirm')[0][2]).toBe(false);
    });

    it('sin «cambio para»: un toque en el repartidor y sale', async () => {
        const pos = (await import('@/services/pos')).default;
        pos.couriers.mockResolvedValue([{ id: 7, name: 'Javi', initials: 'JA', color: '#09f', float: 0 }]);
        wrapper = mount(DispatchSheet, { props: { order: null }, attachTo: document.body });
        await wrapper.setProps({ order: { id: 4, customer_name: 'Pep', change_needed: 0 } });
        await flushPromises();
        await tap('Javi');
        expect(wrapper.emitted('confirm')[0][2]).toBe(false);
    });
});
