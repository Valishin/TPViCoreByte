import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, expect, it } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import { layerOpen, resetLayers } from '@/composables/useLayer';
import { useAmountGuardStore } from '@/stores/amountGuard';
import { topLayer } from './layers';

const state = reactive({ a: false, b: false, c: false });

// Montados al revés de como se abren: el orden del DOM no decide quién queda encima.
const Harness = defineComponent(() => () => [
    h(ModalDialog, { open: state.c, title: 'C' }, () => h('button', 'en C')),
    h(BottomSheet, { open: state.b, title: 'B' }, () => h('input', { 'aria-label': 'campo B' })),
    h(ModalDialog, { open: state.a, title: 'A' }, () => h('button', { id: 'in-a' }, 'en A')),
]);

let wrapper;
beforeEach(() => {
    setActivePinia(createPinia());
    document.body.innerHTML = '<div id="app"><button id="outside">fuera</button></div>';
    Object.assign(state, { a: false, b: false, c: false });
});
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});

const layer = (title) => document.querySelector(`[aria-label="${title}"]`);

it('la última ventana abierta queda encima y es la única que recibe toques y teclas', async () => {
    wrapper = mount(Harness, { attachTo: '#app' });
    document.getElementById('outside').focus();

    state.a = true;
    await flushPromises();
    expect(layerOpen.value).toBe(true);
    expect(topLayer()).toBe(layer('A'));

    state.b = true;   // hoja sobre modal
    await flushPromises();
    state.c = true;   // modal sobre hoja
    await flushPromises();

    const [a, b, c] = ['A', 'B', 'C'].map(layer);
    expect(Number(c.style.zIndex)).toBeGreaterThan(Number(b.style.zIndex));
    expect(Number(b.style.zIndex)).toBeGreaterThan(Number(a.style.zIndex));
    expect([a.hasAttribute('inert'), b.hasAttribute('inert'), c.hasAttribute('inert')]).toEqual([true, true, false]);
    expect(topLayer()).toBe(c);
    expect(c.contains(document.activeElement)).toBe(true);

    // Al cerrar la de arriba, la siguiente vuelve a recibir toques; al cerrar todas, la app.
    state.c = false;
    await flushPromises();
    expect(topLayer()).toBe(layer('B'));
    expect(layer('B').hasAttribute('inert')).toBe(false);

    state.b = false;
    state.a = false;
    await flushPromises();
    await nextTick();
    expect(layerOpen.value).toBe(false);
    expect(document.activeElement.id).toBe('outside');   // el foco vuelve a donde estaba
});

it('confirmación de importes: por debajo del máximo no pregunta; por encima, espera la respuesta', async () => {
    const guard = useAmountGuardStore();
    await expect(guard.check('cash_tendered', 5000, 'el efectivo entregado')).resolves.toBe(true);

    const answer = guard.check('cash_tendered', 1500000, 'el efectivo entregado');
    expect(guard.request).toMatchObject({ cents: 1500000, max: 100000 });
    guard.answer(false);
    await expect(answer).resolves.toBe(false);

    const tip = guard.check('tip', 10000, 'la propina');
    guard.answer(true);
    await expect(tip).resolves.toBe(true);
});
