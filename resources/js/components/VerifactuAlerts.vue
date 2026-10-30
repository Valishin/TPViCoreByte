<template>
    <div v-if="notices.length" class="c-alerts" role="alert">
        <div v-for="(n, i) in notices" :key="i" :class="['c-alert', { 'c-alert--warning': n.level === 'warning' }]">
            <span class="c-alert__text">{{ n.text }}</span>
        </div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import pos from '@/services/pos';

/**
 * Avisos persistentes de VERI*FACTU (registros sin enviar desde el primer
 * momento —OM 16.4—, rechazados, certificado, obligación). Se consultan cada minuto.
 */
const notices = ref([]);
let timer = null;

async function load() {
    try {
        notices.value = await pos.verifactuStatus();
    } catch {
        // sin conexión con el servidor: ya lo indica la barra superior
    }
}
onMounted(() => {
    load();
    timer = setInterval(load, 60_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
