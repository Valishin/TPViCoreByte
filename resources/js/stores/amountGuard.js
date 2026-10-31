import { defineStore } from 'pinia';
import { useSessionStore } from './session';

/**
 * Importes fuera de lo normal (un cero de más): por encima del máximo de
 * Ajustes del local (fondo de caja, arqueo, efectivo entregado, propina) se
 * pide confirmación con el importe en grande antes de seguir.
 *
 * await check('opening_float', cents, 'el fondo de caja') → true si se sigue.
 */
export const DEFAULT_LIMITS = { opening_float: 200000, cash_count: 1000000, cash_tendered: 100000, tip: 5000 };

export const useAmountGuardStore = defineStore('amountGuard', {
    state: () => ({ request: null }), // { cents, max, label, resolve }
    actions: {
        limit(kind) {
            return useSessionStore().settings?.amount_limits?.[kind] ?? DEFAULT_LIMITS[kind];
        },
        check(kind, cents, label) {
            const max = this.limit(kind);
            if (!max || cents <= max) return Promise.resolve(true);
            this.request?.resolve(false);
            return new Promise((resolve) => {
                this.request = { cents, max, label, resolve };
            });
        },
        answer(ok) {
            this.request?.resolve(ok);
            this.request = null;
        },
    },
});
