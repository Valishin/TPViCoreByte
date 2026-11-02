import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import AmountKeypad from '@/components/AmountKeypad.vue';

let wrapper;
afterEach(() => wrapper?.unmount());

function mountKeypad(initial, props = {}) {
    const cents = ref(initial);
    wrapper = mount(defineComponent({
        setup: () => () => h(AmountKeypad, { modelValue: cents.value, 'onUpdate:modelValue': (v) => (cents.value = v), ...props }),
    }));
    return cents;
}
const tap = async (label) => {
    const button = wrapper.findAll('button').find((b) => b.text() === label || b.attributes('aria-label') === label);
    await button.trigger('click');
    await nextTick();
};

describe('Teclado de importes', () => {
    it('cobrar una parte con tarjeta: el total propuesto se sustituye al teclear', async () => {
        const cents = mountKeypad(2500, { exact: 2500 });   // se propone todo lo pendiente
        await tap('1');
        await tap('0');
        expect(cents.value).toBe(1000);   // antes: 25 → 2510 €
    });

    it('lo que se teclea después se va sumando como siempre', async () => {
        const cents = mountKeypad(0);
        for (const k of ['1', '2', 'Coma decimal', '5']) await tap(k);
        expect(cents.value).toBe(1250);
    });

    it('«Importe exacto» o un billete también se sustituyen al teclear', async () => {
        const cents = mountKeypad(0, { exact: 4550, quick: [5000] });
        await tap('50 €');
        expect(cents.value).toBe(5000);
        await tap('2');
        expect(cents.value).toBe(200);
    });

    it('con ⌫ se corrige el importe propuesto en vez de sustituirlo', async () => {
        const cents = mountKeypad(2500);
        await tap('Borrar');
        await tap('0');
        expect(cents.value).toBe(2000);
    });
});
