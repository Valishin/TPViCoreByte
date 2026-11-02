import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/services/pos', () => {
    const pos = {
        lookupCustomer: vi.fn(),
        createCustomer: vi.fn(),
        createTakeaway: vi.fn(),
        addAddress: vi.fn(),
        zones: vi.fn(() => Promise.resolve({ data: [] })),
        takeawayBoard: vi.fn(() => Promise.resolve({ data: [], settings: {} })),
    };
    return { default: pos, errorCode: () => null, errorMessage: () => 'error' };
});
const replace = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn(), replace }) }));

import pos from '@/services/pos';
import NumericKeypad from '@/components/NumericKeypad.vue';
import NewTakeawayView from '@/views/NewTakeawayView.vue';
import { useSessionStore } from '@/stores/session';
import { useTakeawayStore } from '@/stores/takeaway';

const address = { id: 5, street: 'Rambla Vidal', number: '3', postal_code: '17220', city: 'Sant Feliu de Guíxols', zone: null };
const stubs = {
    AddressForm: { name: 'AddressForm', props: ['modelValue'], template: '<div class="stub-address" />' },
    TakeawayTimePicker: { template: '<div class="stub-time" />' },
};

function mountView() {
    setActivePinia(createPinia());
    useSessionStore().settings = { enabled_order_types: ['domicilio', 'para_llevar'] };
    useTakeawayStore().settings = { delivery_minutes: 35, takeaway_prep_minutes: 15 };
    return mount(NewTakeawayView, { global: { stubs } });
}
async function typePhone(wrapper, phone) {
    wrapper.findComponent(NumericKeypad).vm.$emit('update:modelValue', phone);
    await flushPromises();
}
const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().includes(text));

describe('Nuevo pedido', () => {
    beforeEach(() => vi.clearAllMocks());

    it('primero solo pide el teléfono', () => {
        const wrapper = mountView();
        expect(wrapper.text()).toContain('Teléfono del cliente');
        expect(wrapper.findAll('.v-new-order__type')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Pago');
        expect(wrapper.find('.stub-time').exists()).toBe(false);
    });

    it('cliente nuevo: solo el formulario de alta y después el pedido, sin pago', async () => {
        pos.lookupCustomer.mockResolvedValue({ phone: '+34612345678', customer: null });
        pos.createCustomer.mockResolvedValue({ id: 9, name: 'Laia', addresses: [address] });
        const wrapper = mountView();
        await typePhone(wrapper, '612345678');

        expect(wrapper.text()).toContain('Cliente nuevo');
        expect(wrapper.find('.stub-address').exists()).toBe(true);
        expect(wrapper.find('.stub-time').exists()).toBe(false);
        expect(button(wrapper, 'Guardar cliente').attributes('disabled')).toBeDefined();

        await wrapper.find('input').setValue('Laia');
        wrapper.findComponent({ name: 'AddressForm' }).vm.$emit('update:modelValue', { street: 'Rambla Vidal', postal_code: '17220', delivery_zone_id: 1 });
        await flushPromises();
        await button(wrapper, 'Guardar cliente').trigger('click');
        await flushPromises();

        expect(pos.createCustomer).toHaveBeenCalledWith(expect.objectContaining({ phone: '+34612345678', name: 'Laia' }));
        expect(wrapper.find('.stub-time').exists()).toBe(true);
        expect(wrapper.text()).toContain('Crear y añadir productos');
        expect(wrapper.text()).not.toContain('Pago previsto');
    });

    it('cliente conocido: directo al pedido y se crea sin forma de pago', async () => {
        pos.lookupCustomer.mockResolvedValue({ phone: '+34612345678', customer: { id: 9, name: 'Laia', addresses: [address] } });
        pos.createTakeaway.mockResolvedValue({ data: { id: 30 }, takeaway: { id: 30 } });
        const wrapper = mountView();
        await typePhone(wrapper, '612345678');

        expect(wrapper.text()).toContain('Laia');
        expect(wrapper.text()).not.toContain('Cliente nuevo');
        await button(wrapper, 'Crear y añadir productos').trigger('click');
        await flushPromises();

        const payload = pos.createTakeaway.mock.calls[0][0];
        expect(payload).toMatchObject({ type: 'domicilio', customer_id: 9, address_id: 5 });
        expect(payload).not.toHaveProperty('payment');
        expect(replace).toHaveBeenCalledWith('/pedidos/30');
    });
});
