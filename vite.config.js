import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT || 5173);
    // IP del ordenador en la LAN. Los móviles cargan los assets de desarrollo
    // y el HMR desde aquí, así que tiene que ser alcanzable desde la red.
    const hmrHost = env.VITE_HMR_HOST || 'localhost';

    return {
        plugins: [
            laravel({
                input: ['resources/scss/app.scss', 'resources/js/app.js'],
                refresh: true,
            }),
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
