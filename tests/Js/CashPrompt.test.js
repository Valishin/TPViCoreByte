import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { tap, topLayer } from './layers';

vi.mock('@/services/pos', () => {
    const pos = { cashSession: vi.fn(), openCash: vi.fn(), managers: vi.fn(), authorize: vi.fn() };
    return { default: pos, errorCode: () => null, errorMessage: () => 'error' };
});

import pos from '@/services/pos';
import CashPrompt from '@/components/CashPrompt.vue';
import { resetLayers } from '@/composables/useLayer';
import { useSessionStore } from '@/stores/session';

let wrapper;
let session;

beforeEach(() => {
    setActivePinia(createPinia());
    document.body.innerHTML = '<div id="app"></div>';
    session = useSessionStore();
    pos.openCash.mockResolvedValue({ id: 1 });
});
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
    vi.clearAllMocks();
});

/** Simula entrar con el PIN (lo que hace session.login). */
async function logIn(role = 'encargado') {
    session.user = { id: 1, role };
    session.loginCount++;
    await flushPromises();
}

describe('Al entrar con la caja cerrada', () => {
    it('ofrece abrirla y se abre desde la misma ventana', async () => {
        pos.cashSession.mockResolvedValue(null);
        wrapper = mount(CashPrompt, { attachTo: '#app' });
        await logIn();

        const dialog = topLayer();
        expect(dialog.textContent).toContain('La caja está cerrada');
        expect(dialog.textContent).toContain('¿La abres ahora?');

        await tap('Abrir con 150,00 €', flushPromises);
        expect(pos.openCash).toHaveBeenCalledWith(expect.objectContaining({ opening_float: 15000 }));
        expect(topLayer()).toBeNull();
    });

    it('«Ahora no» la cierra sin abrir la caja', async () => {
        pos.cashSession.mockResolvedValue(null);
        wrapper = mount(CashPrompt, { attachTo: '#app' });
        await logIn();

        await tap('Ahora no', flushPromises);
        expect(topLayer()).toBeNull();
        expect(pos.openCash).not.toHaveBeenCalled();
    });

    it('no sale si la caja ya está abierta, ni al repartidor, ni sin entrar', async () => {
        wrapper = mount(CashPrompt, { attachTo: '#app' });
        await flushPromises();
        expect(pos.cashSession).not.toHaveBeenCalled();   // al cargar la página no se pregunta

        pos.cashSession.mockResolvedValue({ id: 3 });
        await logIn();
        expect(topLayer()).toBeNull();

        pos.cashSession.mockResolvedValue(null);
        await logIn('repartidor');
        expect(topLayer()).toBeNull();
    });
});

describe('Abrir caja contando billetes y monedas', () => {
    it('el botón dice lo contado, no el fondo propuesto', async () => {
        pos.cashSession.mockResolvedValue(null);
        wrapper = mount(CashPrompt, { attachTo: '#app' });
        await logIn();

        await tap('Contar billetes y monedas', flushPromises);
        expect(topLayer().textContent).toContain('Abrir con 0,00 €');

        await tap('Importe', flushPromises);
        expect(topLayer().textContent).toContain('Abrir con 150,00 €');
    });
});
