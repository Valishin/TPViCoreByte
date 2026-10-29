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

    // Autorización de encargado
    managers: () => api.get('/managers').then((r) => r.data.data),
    authorize: (managerId, pin, action) => api.post('/authorize', { manager_id: managerId, pin, action }).then((r) => r.data.token),

    // Cobro y descuentos
    quickSale: () => api.post('/quick-sale').then((r) => r.data.data),
    checkout: (id, payload) => api.post(`/orders/${id}/checkout`, payload).then((r) => r.data.data),
    discountOrder: (id, payload) => api.post(`/orders/${id}/discount`, payload).then((r) => r.data.data),
    discountLine: (lineId, payload) => api.post(`/order-lines/${lineId}/discount`, payload).then((r) => r.data.data),
    inviteLine: (lineId, payload) => api.post(`/order-lines/${lineId}/invite`, payload).then((r) => r.data.data),
    split: (id, lines) => api.post(`/orders/${id}/split`, { lines }).then((r) => r.data.data),

    // Caja y tickets
    cashSession: () => api.get('/cash-session').then((r) => r.data.data),
    openCash: (payload) => api.post('/cash-session/open', payload).then((r) => r.data.data),
    closeCash: (payload) => api.post('/cash-session/close', payload).then((r) => r.data.data),
    printX: () => api.post('/cash-session/print-x'),
    openDrawer: (payload) => api.post('/drawer/open', payload),
    tickets: () => api.get('/tickets').then((r) => r.data.data),
    ticket: (id) => api.get(`/tickets/${id}`).then((r) => r.data.data),
    reprint: (id, payload) => api.post(`/tickets/${id}/reprint`, payload).then((r) => r.data),
    rectify: (id, payload) => api.post(`/tickets/${id}/rectify`, payload).then((r) => r.data.data),

    printers: () => api.get('/printers').then((r) => r.data.data),
    failedJobs: () => api.get('/print-jobs/failed').then((r) => r.data.data),
    retryJob: (id) => api.post(`/print-jobs/${id}/retry`).then((r) => r.data.data),
    redirectJob: (id, printerId) => api.post(`/print-jobs/${id}/redirect`, { printer_id: printerId }).then((r) => r.data.data),
};

/** Código de error de negocio de la API (authorization_required, cash_session_required, open_orders). */
export function errorCode(error) {
    return error?.response?.data?.code ?? null;
}

/** Mensaje legible de un error de la API. */
export function errorMessage(error) {
    const data = error?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat()[0];
    if (data?.message) return data.message;
    if (!error?.response) return 'Sin conexión con el servidor.';
    return 'Algo ha fallado. Inténtalo de nuevo.';
}

export default pos;
