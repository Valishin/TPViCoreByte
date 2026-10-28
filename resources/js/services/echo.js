import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Conexión a Reverb.
 *
 * El host es SIEMPRE el del navegador (window.location.hostname): así la caja
 * (localhost) y los móviles (IP de la LAN) se conectan al mismo servidor sin
 * configurar IPs fijas en el build.
 */
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? window.location.protocol.replace(':', '');
const port = Number(import.meta.env.VITE_REVERB_PORT ?? 8080);

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});

window.Echo = echo;

export default echo;
