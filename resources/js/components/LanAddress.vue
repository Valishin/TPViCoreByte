<template>
    <div v-if="url" class="c-lan-address" role="note" :aria-label="`Para conectar un móvil: ${url}`">
        Móviles: <strong>{{ url }}</strong>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Dirección para conectar los móviles de la misma red, fija abajo a la
 * derecha en la caja. Se refresca cada minuto por si el router cambia la IP.
 *  - Desarrollo: la da Vite (corre en el ordenador y ve su IP real).
 *  - Producción: /api/network (APP_LAN_IP o la IP con la que se ha entrado).
 */
const ip = ref(null);
const PRIVATE = /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/;

const url = computed(() => {
    if (!ip.value) return null;
    const port = window.location.port && window.location.port !== '80' ? `:${window.location.port}` : '';
    return `http://${ip.value}${port}`;
});

async function load() {
    try {
        // Origen de Vite a partir del módulo (no new URL('/…', import.meta.url): Vite lo trata como un asset).
        const endpoint = import.meta.env.DEV ? `${new URL(import.meta.url).origin}/__tpv/lan-ip` : '/api/network';
        const data = await fetch(endpoint, { cache: 'no-store' }).then((r) => r.json());
        ip.value = data.ip ?? (PRIVATE.test(window.location.hostname) ? window.location.hostname : null);
    } catch {
        // sin respuesta: si ya se ha entrado por la IP, esa es la buena
        ip.value = PRIVATE.test(window.location.hostname) ? window.location.hostname : ip.value;
    }
}

let timer = null;
onMounted(() => {
    load();
    timer = setInterval(load, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
