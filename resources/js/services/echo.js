import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api';

window.Pusher = Pusher;

/**
 * Conexión a Reverb.
 *
 * El host es SIEMPRE el del navegador (window.location.hostname): así la caja
 * (localhost) y los móviles (IP de la LAN) se conectan al mismo servidor sin
 * configurar IPs fijas en el build. Los canales privados se autorizan con la
 * sesión del TPV (cookie + XSRF) a través de axios.
 */
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || null;
const scheme = import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
// Desarrollo: puerto propio de Reverb (VITE_REVERB_PORT). Producción: el mismo
// de la página, porque FrankenPHP pasa /app a Reverb (un solo puerto que abrir).
const port = Number(import.meta.env.VITE_REVERB_PORT || window.location.port || (scheme === 'https' ? 443 : 80));

const echo = new Echo({
    broadcaster: 'reverb',
    // La clave de la instalación viene en la página; la de build solo en desarrollo.
    key: meta('reverb-key') || import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
        authorize: (socketId, callback) => {
            api.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name }, { baseURL: '/api' })
                .then((response) => callback(null, response.data))
                .catch((error) => callback(error));
        },
    }),
});

window.Echo = echo;

export default echo;
