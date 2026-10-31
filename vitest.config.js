import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

/** Tests de componentes de la SPA (npm run test:js). */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/Js/**/*.test.js'],
        setupFiles: ['tests/Js/setup.js'],
    },
});
