import axios from 'axios';

/**
 * Cliente HTTP de la SPA.
 *
 * Sanctum en modo SPA: autenticación por cookie de sesión + XSRF-TOKEN.
 * La SPA se sirve desde el mismo origen que la API, así que las rutas son
 * relativas y funcionan igual en localhost, por IP de la LAN o en el mini PC.
 */
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/** Pide la cookie CSRF antes de un login u otra petición con estado. */
export function csrf() {
    return axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export default api;
