import { defineStore } from 'pinia';
import pos from '@/services/pos';

/**
 * Pedido abierto en pantalla y borrador del configurador (se conserva al
 * bloquear si vuelve a entrar el mismo camarero).
 */
export const useOrderStore = defineStore('order', {
    state: () => ({
        order: null,
        busy: false,
        draft: null, // { productId, state } del configurador a medias
    }),
    getters: {
        lines: (s) => s.order?.lines ?? [],
        pending: (s) => (s.order?.lines ?? []).filter((l) => !l.sent_at && !l.voided_at),
        pendingCount() {
            return this.pending.length;
        },
        /** Envíos con sus líneas y el estado de impresión de cada impresora. */
        sends: (s) =>
            (s.order?.sends ?? [])
                .slice()
                .reverse()
                .map((send) => ({
                    ...send,
                    lines: s.order.lines.filter((l) => l.send_number === send.number),
                    jobs: s.order.print_jobs.filter((j) => j.send_number === send.number && j.kind === 'comanda'),
                })),
    },
    actions: {
        async load(id) {
            this.order = await pos.order(id);
            return this.order;
        },
        async reload() {
            if (this.order) await this.load(this.order.id);
        },
        async run(fn) {
            this.busy = true;
            try {
                const result = await fn();
                if (result?.data?.id) this.order = result.data;
                else if (result?.id && result?.lines) this.order = result;
                return result;
            } finally {
                this.busy = false;
            }
        },
        addLine(payload) {
            return this.run(() => pos.addLine(this.order.id, payload));
        },
        updateLine(line, payload) {
            return this.run(() => pos.updateLine(line.id, payload));
        },
        voidLine(line, reason) {
            return this.run(() => pos.voidLine(line.id, reason));
        },
        send(options = {}) {
            return this.run(() => pos.send(this.order.id, options));
        },
        move(tableId) {
            return this.run(() => pos.move(this.order.id, tableId));
        },
        precheck() {
            return this.run(() => pos.precheck(this.order.id));
        },
        setGuests(guests) {
            return this.run(() => pos.setGuests(this.order.id, guests));
        },
        clear() {
            this.order = null;
            this.draft = null;
        },
    },
});
