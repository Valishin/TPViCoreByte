import { defineStore } from 'pinia';
import api from '@/services/api';
import echo from '@/services/echo';

const PING_CHANNEL = 'diagnostico';
const PING_EVENT = '.diagnostico.ping';

/** Estado de la página /estado: API, websockets e impresión. */
export const useDiagnosticsStore = defineStore('diagnostics', {
    state: () => ({
        api: { status: 'idle', message: 'Sin comprobar', detail: null },
        reverb: { status: 'idle', message: 'Sin comprobar', connection: 'initialized', last: null },
        printer: { status: 'idle', message: 'Sin comprobar', detail: null },
        listening: false,
    }),

    actions: {
        async checkApi() {
            this.api = { status: 'loading', message: 'Comprobando…', detail: null };
            try {
                const { data } = await api.get('/health');
                this.api = {
                    status: data.status === 'ok' ? 'ok' : 'error',
                    message: data.status === 'ok' ? 'API y base de datos OK' : 'La API responde con errores',
                    detail: data,
                };
            } catch (error) {
                this.api = { status: 'error', message: 'Sin conexión con la API', detail: errorDetail(error) };
            }
        },

        /** Se suscribe al canal de diagnóstico y sigue el estado del socket. */
        listen() {
            if (this.listening) return;
            this.listening = true;

            const connection = echo.connector.pusher.connection;
            this.reverb.connection = connection.state;
            connection.bind('state_change', ({ current }) => {
                this.reverb.connection = current;
            });

            echo.channel(PING_CHANNEL).listen(PING_EVENT, (payload) => {
                this.reverb = {
                    ...this.reverb,
                    status: 'ok',
                    message: 'Evento recibido en tiempo real',
                    last: { ...payload, receivedAt: new Date().toISOString() },
                };
            });
        },

        stop() {
            echo.leaveChannel(PING_CHANNEL);
            this.listening = false;
        },

        async sendPing() {
            this.reverb = { ...this.reverb, status: 'loading', message: 'Enviando evento…' };
            try {
                const { data } = await api.post('/diagnostico/ping', { origen: navigator.userAgent });
                // El evento puede llegar antes que la respuesta del POST: se
                // compara por id. Si no llega en 5 s, falla servidor ↔ Reverb.
                setTimeout(() => {
                    if (this.reverb.last?.id !== data.id) {
                        this.reverb = { ...this.reverb, status: 'error', message: 'El evento no ha llegado' };
                    }
                }, 5000);
            } catch (error) {
                this.reverb = { ...this.reverb, status: 'error', message: 'No se pudo emitir el evento', detail: errorDetail(error) };
            }
        },

        async printTest() {
            this.printer = { status: 'loading', message: 'Enviando a la cola…', detail: null };
            try {
                const { data } = await api.post('/diagnostico/imprimir');
                this.printer = { status: 'ok', message: 'Trabajo encolado', detail: data };
            } catch (error) {
                this.printer = { status: 'error', message: 'No se pudo encolar', detail: errorDetail(error) };
            }
        },
    },
});

function errorDetail(error) {
    return error.response?.data ?? { error: error.message };
}
