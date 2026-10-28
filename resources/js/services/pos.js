import api, { csrf } from './api';

/** Llamadas a la API del TPV de sala. Todas devuelven `data` del JSON. */
const pos = {
    staff: () => api.get('/staff').then((r) => r.data.data),
    async login(userId, pin) {
        await csrf();
        return api.post('/login-pin', { user_id: userId, pin }).then((r) => r.data);
    },
    me: () => api.get('/me').then((r) => r.data),
    logout: () => api.post('/logout'),

    /** Carta con ETag: devuelve null si no ha cambiado (304). */
    catalog: (version) =>
        api
            .get('/catalog', {
                headers: version ? { 'If-None-Match': `"${version}"` } : {},
                validateStatus: (s) => s === 200 || s === 304,
            })
            .then((r) => (r.status === 304 ? null : r.data)),

    rooms: () => api.get('/rooms').then((r) => r.data.data),
    openTable: (tableId, guests) => api.post(`/tables/${tableId}/open`, { guests }).then((r) => r.data.data),

    order: (id) => api.get(`/orders/${id}`).then((r) => r.data.data),
    setGuests: (id, guests) => api.patch(`/orders/${id}`, { guests }).then((r) => r.data.data),
    addLine: (id, payload) => api.post(`/orders/${id}/lines`, payload).then((r) => r.data),
    updateLine: (lineId, payload) => api.patch(`/order-lines/${lineId}`, payload).then((r) => r.data.data),
    voidLine: (lineId, reason) => api.post(`/order-lines/${lineId}/void`, { reason }).then((r) => r.data.data),
    send: (id) => api.post(`/orders/${id}/send`).then((r) => r.data),
    move: (id, tableId) => api.post(`/orders/${id}/move`, { table_id: tableId }).then((r) => r.data.data),
    precheck: (id) => api.post(`/orders/${id}/precheck`).then((r) => r.data.data),

    printers: () => api.get('/printers').then((r) => r.data.data),
    failedJobs: () => api.get('/print-jobs/failed').then((r) => r.data.data),
    retryJob: (id) => api.post(`/print-jobs/${id}/retry`).then((r) => r.data.data),
    redirectJob: (id, printerId) => api.post(`/print-jobs/${id}/redirect`, { printer_id: printerId }).then((r) => r.data.data),
};

/** Mensaje legible de un error de la API. */
export function errorMessage(error) {
    const data = error?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat()[0];
    if (data?.message) return data.message;
    if (!error?.response) return 'Sin conexión con el servidor.';
    return 'Algo ha fallado. Inténtalo de nuevo.';
}

export default pos;
