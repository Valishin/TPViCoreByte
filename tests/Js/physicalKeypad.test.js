import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import AmountKeypad from '@/components/AmountKeypad.vue';
import NumericKeypad from '@/components/NumericKeypad.vue';

// jsdom no calcula cajas: el teclado cuenta como visible.
HTMLElement.prototype.getClientRects ??= () => [{}];
if (!HTMLElement.prototype.getClientRects.call(document.body).length) {
    HTMLElement.prototype.getClientRects = () => [{}];
}

const key = (k, target = window) => target.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
let wrapper;
afterEach(() => wrapper?.unmount());

function mountPin(props = {}) {
    const value = ref('');
    const events = { complete: [], submit: [] };
    wrapper = mount(defineComponent({
        setup: () => () => h('div', [
            h('input', { id: 'campo' }),
            h(NumericKeypad, {
                modelValue: value.value,
                'onUpdate:modelValue': (v) => (value.value = v),
                onComplete: (v) => events.complete.push(v),
                onSubmit: (v) => events.submit.push(v),
                ...props,
            }),
        ]),
    }), { attachTo: document.body });
    return { value, events };
}

describe('Teclado físico en los teclados en pantalla', () => {
    it('PIN: las cifras escriben y al llegar a 4 entra', async () => {
        const { value, events } = mountPin({ length: 4, masked: true });
        for (const k of ['1', '2', 'a', '3', 'Backspace', '3', '4']) key(k);
        await nextTick();
        expect(value.value).toBe('1234');
        expect(events.complete).toEqual(['1234']);
    });

    it('teléfono: Intro busca y Supr borra', async () => {
        const { value, events } = mountPin({ length: 12, okLabel: 'Buscar' });
        for (const k of '612345678') key(k);
        await nextTick();
        key('Enter');
        expect(events.submit).toEqual(['612345678']);
        key('Backspace');
        key('Backspace');
        await nextTick();
        expect(value.value).toBe('6123456', 'Retroceso: cifra a cifra.');
        key('Delete');
        await nextTick();
        expect(value.value).toBe('');
    });

    it('teléfono: el botón ⌫ borra cifra a cifra (no todo)', async () => {
        const { value } = mountPin({ length: 12, okLabel: 'Buscar' });
        for (const k of '612') key(k);
        await nextTick();
        expect(wrapper.text()).not.toContain('Borrar');
        await wrapper.find('[aria-label="Borrar último"]').trigger('click');
        expect(value.value).toBe('61');
    });

    it('si se escribe en un campo de texto, el teclado en pantalla no se entera', async () => {
        const { value } = mountPin();
        const input = document.getElementById('campo');
        input.focus();
        key('5', input);
        await nextTick();
        expect(value.value).toBe('');
    });

    it('solo responde el de la ventana de arriba (el de debajo está inert)', async () => {
        const { value } = mountPin();
        wrapper.element.setAttribute('inert', '');
        key('7');
        await nextTick();
        expect(value.value).toBe('');
    });

    it('importes: cifras y coma (o punto) para los céntimos', async () => {
        const cents = ref(0);
        wrapper = mount(defineComponent({
            setup: () => () => h(AmountKeypad, { modelValue: cents.value, 'onUpdate:modelValue': (v) => (cents.value = v) }),
        }), { attachTo: document.body });
        for (const k of ['1', '2', '.', '5']) key(k);
        await nextTick();
        expect(cents.value).toBe(1250);
        key('Backspace');
        await nextTick();
        expect(cents.value).toBe(1200);
    });
});
