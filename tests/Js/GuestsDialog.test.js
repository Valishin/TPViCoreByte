import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import GuestsDialog from '@/components/GuestsDialog.vue';
import NumericKeypad from '@/components/NumericKeypad.vue';
import { resetLayers } from '@/composables/useLayer';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});

async function open(current = null) {
    wrapper = mount(GuestsDialog, { props: { open: false, current }, attachTo: document.body });
    await wrapper.setProps({ open: true });
    await nextTick();
}
const buttons = () => [...document.body.querySelectorAll('button')];
const button = (text) => buttons().find((b) => b.textContent.includes(text));

describe('¿Cuántos comensales?', () => {
    it('salen del 1 al 8 y «Más de 8», sin teclado', async () => {
        await open();
        expect(buttons().filter((b) => /^\d+ person/.test(b.getAttribute('aria-label') ?? ''))).toHaveLength(8);
        expect(button('Más de 8')).toBeTruthy();
        expect(wrapper.findComponent(NumericKeypad).exists()).toBe(false);
    });

    it('«Más de 8» abre el teclado y «8 o menos» vuelve a las opciones', async () => {
        await open();
        button('Más de 8').click();
        await nextTick();
        expect(wrapper.findComponent(NumericKeypad).exists()).toBe(true);

        wrapper.findComponent(NumericKeypad).vm.$emit('submit', '12');
        expect(wrapper.emitted('confirm')).toEqual([[12]]);

        button('8 o menos').click();
        await nextTick();
        expect(wrapper.findComponent(NumericKeypad).exists()).toBe(false);
        expect(button('Más de 8')).toBeTruthy();
    });

    it('una mesa que ya tenía 10 abre directamente el teclado', async () => {
        await open(10);
        expect(wrapper.findComponent(NumericKeypad).exists()).toBe(true);
    });
});
