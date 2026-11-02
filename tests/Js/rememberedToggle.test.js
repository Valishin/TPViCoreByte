import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { useRememberedToggle } from '@/composables/useRememberedToggle';

describe('useRememberedToggle', () => {
    beforeEach(() => localStorage.clear());

    it('sin nada guardado usa el valor por defecto', () => {
        expect(useRememberedToggle('checkout.print_ticket', true).value).toBe(true);
    });

    it('desmarcada sigue desmarcada al volver a abrir', async () => {
        const first = useRememberedToggle('checkout.print_ticket', true);
        first.value = false;
        await nextTick();

        expect(useRememberedToggle('checkout.print_ticket', true).value).toBe(false);
    });

    it('cada casilla se guarda por separado', async () => {
        const receipts = useRememberedToggle('checkout.receipts', false);
        receipts.value = true;
        await nextTick();

        expect(useRememberedToggle('checkout.print_ticket', true).value).toBe(true);
        expect(useRememberedToggle('checkout.receipts', false).value).toBe(true);
    });
});
