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

    it('cambio para 50 €: al dar salida, el cambio sale de la caja (marcado por defecto)', async () => {
        const order = { id: 3, customer_name: 'Lucía', cash_change_for: 5000, change_needed: 2850 };
        wrapper = mount(DispatchSheet, { props: { order: null }, attachTo: document.body });
        await wrapper.setProps({ order });
        await flushPromises();

        expect(document.body.textContent).toContain('Le doy el cambio desde la caja: 28,50');
        expect(document.body.textContent).toContain('al liquidar entregará 50,00');
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Javi')).click();
        expect(wrapper.emitted('confirm')[0][2]).toBe(true);

        document.body.querySelector('input[type="checkbox"]').click();
        await flushPromises();
        [...document.body.querySelectorAll('button')].find((b) => b.textContent.includes('Javi')).click();
        expect(wrapper.emitted('confirm')[1][2]).toBe(false);
        expect(buttons()).toContain('Cancelar');
    });
});
