import { defineStore } from 'pinia';
import echo from '@/services/echo';
import { useFloorStore } from './floor';
import { useOrderStore } from './order';
import { useNoticesStore } from './notices';
import { useCatalogStore } from './catalog';
import { useTakeawayStore } from './takeaway';
import { useSessionStore } from './session';

/**
 * Tiempo real: canal privado `pos`. Al (re)conectar recarga el estado
 * completo, por si se perdió algún evento mientras no había conexión.
 */
export const useRealtimeStore = defineStore('realtime', {
    // cashVersion: sube con cada cambio de caja (la pantalla Caja la vigila y se recarga).
    state: () => ({ state: 'connecting', subscribed: false, cashVersion: 0 }),
    getters: {
        connected: (s) => s.state === 'connected',
    },
    actions: {
        start() {
            const connection = echo.connector.pusher.connection;
            this.state = connection.state;
            if (!this._bound) {
                this._bound = true;
                connection.bind('state_change', ({ previous, current }) => {
                    this.state = current;
                    if (current === 'connected' && previous !== 'initialized' && this.subscribed) {
                        this.resync();
                    }
                });
            }
            if (this.subscribed) return;
            this.subscribed = true;

            const floor = useFloorStore();
            const order = useOrderStore();
            const notices = useNoticesStore();
            const catalog = useCatalogStore();
            const takeaway = useTakeawayStore();

            // Repartidor: solo le interesan sus repartos ("Mis repartos" vigila takeaway.version).
            if (useSessionStore().isCourier) {
                echo.private('pos').listen('.takeaway.changed', () => takeaway.version++);
                return;
            }

            echo.private('pos')
                .listen('.takeaway.changed', (e) => {
                    takeaway.version++;
                    takeaway.refresh();
                    if (order.order?.id === e.orderId) order.reload().catch(() => {});
                })
                .listen('.table.status', () => floor.refresh())
                .listen('.floor.changed', () => floor.refresh())
                .listen('.cash.changed', () => this.cashVersion++)
                .listen('.order.updated', (e) => {
                    floor.refresh();
                    if (!e.tableId) takeaway.refresh();
                    if (order.order?.id === e.orderId) order.reload().catch(() => {});
                })
                .listen('.printer.status', (e) => {
                    notices.updatePrinter(e);
                    if (e.status === 'error') notices.error(`Impresora ${e.name} con error`);
                })
                .listen('.print-job.updated', (e) => {
                    notices.loadPrinting().catch(() => {});
                    if (order.order?.id === e.orderId) order.reload().catch(() => {});
                })
                .listen('.catalog.changed', (e) => {
                    if (e.version !== catalog.version) catalog.load().catch(() => {});
                });
        },
        stop() {
            echo.leave('pos');
            this.subscribed = false;
        },
        resync() {
            if (useSessionStore().isCourier) {
                useTakeawayStore().version++;
                return;
            }
            useFloorStore().load().catch(() => {});
            useOrderStore().reload().catch(() => {});
            useNoticesStore().loadPrinting().catch(() => {});
            useCatalogStore().load().catch(() => {});
            useTakeawayStore().refresh();
            this.cashVersion++;
        },
    },
});
