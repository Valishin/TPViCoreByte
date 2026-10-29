import { defineStore } from 'pinia';
import pos from '@/services/pos';

/** Columnas del tablero "Pedidos" (llevar y domicilio). */
export const COLUMNS = [
    { status: 'pendiente', label: 'Pendiente' },
    { status: 'en_cocina', label: 'En cocina' },
    { status: 'listo', label: 'Listo' },
    { status: 'en_reparto', label: 'En reparto' },
];

/** Tablero "Pedidos": se recarga con los eventos de tiempo real (takeaway.changed / order.updated). */
export const useTakeawayStore = defineStore('takeaway', {
    // version: sube con cada evento de llevar / domicilio (otras pantallas, p. ej. Repartidores, lo vigilan).
    state: () => ({ orders: [], settings: null, loaded: false, loading: false, now: Date.now(), version: 0 }),
    getters: {
        byStatus: (s) => (status) => s.orders.filter((o) => o.delivery_status === status),
        finished: (s) => s.orders.filter((o) => ['entregado', 'no_entregado'].includes(o.delivery_status)),
    },
    actions: {
        async load() {
            this.loading = true;
            try {
                const result = await pos.takeawayBoard();
                this.orders = result.data;
                this.settings = result.settings;
                this.loaded = true;
            } finally {
                this.loading = false;
            }
        },
        /** Varios eventos seguidos → una sola recarga. */
        refresh() {
            if (!this.loaded) return;
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this.load().catch(() => {}), 150);
        },
        upsert(order) {
            if (!order) return;
            const i = this.orders.findIndex((o) => o.id === order.id);
            if (i >= 0) this.orders.splice(i, 1, order);
            else this.orders.push(order);
        },
        tick() {
            this.now = Date.now();
        },
    },
});

/**
 * Tiempo hasta la hora prometida: "Quedan 25 min", en aviso si quedan menos
 * de 10 y "Tarde 5 min" si ya ha pasado (siempre con texto, no solo color).
 */
export function timeLeft(order, now) {
    if (!order?.promised_at || ['entregado', 'no_entregado'].includes(order.delivery_status)) return null;
    const minutes = Math.round((new Date(order.promised_at).getTime() - now) / 60000);
    if (minutes < 0) return { level: 'late', text: `Tarde ${-minutes} min` };
    return { level: minutes < 10 ? 'soon' : 'ok', text: minutes === 0 ? 'Ahora' : `Quedan ${minutes} min` };
}

export function hhmm(iso) {
    return iso ? new Date(iso).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }) : '';
}

/** "Lo antes posible (21:30)" o "21:30". */
export function promisedLabel(order) {
    if (!order?.promised_at) return '';
    return order.asap ? `Lo antes posible (${hhmm(order.promised_at)})` : hhmm(order.promised_at);
}

export const PAYMENT_LABELS = { efectivo: 'Efectivo', tarjeta: 'Tarjeta', bizum: 'Bizum', online: 'Online', otro: 'Otro' };

export function paymentLabel(order) {
    if (order.prepaid_method) return `Pagado (${PAYMENT_LABELS[order.prepaid_method]})`;
    return PAYMENT_LABELS[order.planned_payment_method] ?? '—';
}
