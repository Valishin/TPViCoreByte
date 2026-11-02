import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import PdfViewer from '@/components/PdfViewer.vue';
import { resetLayers } from '@/composables/useLayer';

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    resetLayers();
});

describe('Visor de PDF dentro de la TPV', () => {
    it('muestra la factura a pantalla completa y «Cerrar y volver» cierra', async () => {
        wrapper = mount(PdfViewer, { props: { url: null, title: 'Factura F-26-000001' }, attachTo: document.body });
        expect(document.querySelector('.c-pdf-viewer')).toBeNull();

        await wrapper.setProps({ url: '/api/tickets/7/pdf' });
        await nextTick();
        expect(document.querySelector('iframe').getAttribute('src')).toBe('/api/tickets/7/pdf');
        expect(document.querySelector('.c-pdf-viewer__title').textContent).toBe('Factura F-26-000001');

        [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Cerrar y volver')).click();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});
