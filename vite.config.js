import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import os from 'node:os';

const PRIVATE = /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/;

/** IP actual del ordenador en la red local (Wi-Fi o cable primero; no las de Docker / VPN). */
function lanIp() {
    const candidates = Object.entries(os.networkInterfaces())
        .flatMap(([name, list]) => (list ?? []).map((a) => ({ name, ...a })))
        .filter((a) => a.family === 'IPv4' && !a.internal && PRIVATE.test(a.address));
    const preferred = candidates.find((a) => /^(en|eth|wl)/.test(a.name));
    return (preferred ?? candidates[0])?.address ?? null;
}

/**
 * Desarrollo: /__tpv/lan-ip devuelve la IP de ahora mismo (si el router la
 * cambia, la etiqueta de la caja se actualiza sola). La app corre en Docker y
 * desde allí no se ve la IP real del ordenador; Vite sí, porque corre en él.
 */
const lanIpEndpoint = {
    name: 'tpv-lan-ip',
    configureServer(server) {
        server.middlewares.use('/__tpv/lan-ip', (req, res) => {
            res.setHeader('Content-Type', 'application/json');
            res.setHeader('Access-Control-Allow-Origin', '*');
            res.setHeader('Cache-Control', 'no-store');
            res.end(JSON.stringify({ ip: lanIp() }));
        });
    },
};

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5173);
    // IP del ordenador en la LAN. Los móviles cargan los assets de desarrollo
    // y el HMR desde aquí, así que tiene que ser alcanzable desde la red.
    // Vacío = la IP actual (si el router la cambia, basta reiniciar npm run dev).
    const hmrHost = env.VITE_HMR_HOST || lanIp() || 'localhost';

    return {
        plugins: [
            laravel({
                input: ['resources/scss/app.scss', 'resources/js/app.js'],
                refresh: true,
            }),
            lanIpEndpoint,
            vue({
                template: {
                    transformAssetUrls: { base: null, includeAbsolute: false },
                },
            }),
        ],
        resolve: {
            alias: { '@': '/resources/js' },
        },
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            origin: `http://${hmrHost}:${port}`,
            // La página se sirve desde Laravel (otro puerto u otra IP): permitir
            // cualquier origen solo en el servidor de desarrollo.
            cors: true,
            hmr: { host: hmrHost, port },
            watch: {
                ignored: ['**/storage/framework/views/**', '**/vendor/**'],
            },
        },
    };
});
