import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { buttonIn, tap, topLayer } from './layers';

// API del TPV simulada: abrir caja sin autorización → 403 authorization_required.
vi.mock('@/services/pos', () => {
    const pos = {
        managers: vi.fn(),
        authorize: vi.fn(),
        openCash: vi.fn(),
    };
    return {
        default: pos,
        errorCode: (e) => e?.response?.data?.code ?? null,
        errorMessage: (e) => e?.response?.data?.message ?? 'error',
    };
});

import pos from '@/services/pos';
import AmountConfirmModal from '@/components/AmountConfirmModal.vue';
import AuthorizeModal from '@/components/AuthorizeModal.vue';
import OpenCashDialog from '@/components/OpenCashDialog.vue';
import { resetLayers } from '@/composables/useLayer';

const authorizationRequired = () =>
    Object.assign(new Error('403'), { response: { data: { code: 'authorization_required', message: 'Abrir caja: necesita la autorización de un encargado.' } } });

/** Como en App.vue: los modales globales montados ANTES que la pantalla (el orden que causaba el fallo). */
const Harness = defineComponent({
    setup() {
        const open = ref(true);
        const opened = ref(null);
        return () => [
            h(AuthorizeModal),
            h(AmountConfirmModal),
            h(OpenCashDialog, { open: open.value, onOpened: (s) => { opened.value = s; open.value = false; }, onClose: () => (open.value = false) }),
            h('output', { id: 'opened' }, opened.value ? 'abierta' : ''),
        ];
    },
});

let wrapper;

beforeEach(() => {
    setActivePinia(createPinia());
    document.body.innerHTML = '<div id="app"></div>';
    pos.managers.mockResolvedValue([{ id: 7, name: 'Encargado', initials: 'EN', color: '#09f' }]);
    pos.authorize.mockResolvedValue('token-1');
    pos.openCash.mockImplementation(({ authorization_token }) =>
        authorization_token ? Promise.resolve({ id: 1 }) : Promise.reject(authorizationRequired()));
});
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});

function floatShown() {
    return document.querySelector('.c-amount__value')?.textContent.trim();
}

describe('Cobrar sin caja: abrir caja con autorización de encargado', () => {
    it('el PIN va a la autorización (encima) y el fondo no cambia', async () => {
        wrapper = mount(Harness, { attachTo: '#app' });
        await flushPromises();
        expect(floatShown()).toBe('150 €');

        // Camarero: «Abrir con 150,00 €» → pide la autorización.
        await tap('Abrir con 150,00 €', flushPromises);
        const auth = document.querySelector('[aria-label="Autorización de encargado"]');
        const openCash = document.querySelector('[aria-label="Abrir caja"]');
        expect(auth).not.toBeNull();

        // La autorización queda encima y es la única que recibe toques y teclas.
        expect(Number(auth.style.zIndex)).toBeGreaterThan(Number(openCash.style.zIndex));
        expect(openCash.hasAttribute('inert')).toBe(true);
        expect(auth.hasAttribute('inert')).toBe(false);
        expect(topLayer()).toBe(auth);
        expect(auth.contains(document.activeElement)).toBe(true);

        // Teclear el PIN tocando la pantalla: los «9» caen en la autorización.
        for (let i = 0; i < 3; i++) await tap('9', flushPromises);
        expect(floatShown()).toBe('150 €');   // el fondo no ha recibido ningún «9»
        expect(auth.querySelectorAll('.c-keypad__dot--filled')).toHaveLength(3);
        await tap('9', flushPromises);

        expect(pos.authorize).toHaveBeenCalledWith(7, '9999', 'abrir_caja');
        expect(pos.openCash).toHaveBeenLastCalledWith({ opening_float: 15000, breakdown: null, authorization_token: 'token-1' });
        expect(document.getElementById('opened').textContent).toBe('abierta');
        expect(document.getElementById('app').hasAttribute('inert')).toBe(false);
    });

    it('el importe enviado es el del momento de pulsar, aunque cambie durante la autorización', async () => {
        wrapper = mount(Harness, { attachTo: '#app' });
        await flushPromises();
        await tap('Abrir con 150,00 €', flushPromises);

        // Aunque algo llegara al teclado del fondo (antes: el PIN), no cambia lo que se envía.
        const openCash = document.querySelector('[aria-label="Abrir caja"]');
        buttonIn(openCash, '9').click();
        await flushPromises();
        for (let i = 0; i < 4; i++) await tap('9', flushPromises);

        expect(pos.openCash).toHaveBeenLastCalledWith(expect.objectContaining({ opening_float: 15000 }));
    });

    it('un fondo fuera de lo normal pide confirmación con el importe en grande', async () => {
        wrapper = mount(Harness, { attachTo: '#app' });
        await flushPromises();
        for (const key of ['9', '9', '9']) await tap(key, flushPromises);   // 150999 €
        expect(floatShown()).toBe('150999 €');

        await tap('Abrir con 150.999,00 €', flushPromises);
        const confirm = topLayer();
        expect(confirm.getAttribute('aria-label')).toBe('¿Seguro?');
        expect(confirm.textContent).toContain('150.999,00 €');
        expect(pos.openCash).not.toHaveBeenCalled();

        await tap('Corregir', flushPromises);
        expect(pos.openCash).not.toHaveBeenCalled();
        expect(topLayer().getAttribute('aria-label')).toBe('Abrir caja');

        await tap('Abrir con 150.999,00 €', flushPromises);
        await tap('Sí, es correcto', flushPromises);
        expect(pos.openCash).toHaveBeenCalledWith(expect.objectContaining({ opening_float: 15099900 }));
    });
});
