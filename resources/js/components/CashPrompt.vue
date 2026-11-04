<template>
    <OpenCashDialog :open="open" prompt @close="open = false" @opened="open = false" />
</template>

<script setup>
import { ref, watch } from 'vue';
import OpenCashDialog from './OpenCashDialog.vue';
import pos from '@/services/pos';
import { useSessionStore } from '@/stores/session';

/**
 * Al entrar con el PIN en la caja, si no hay ninguna caja abierta, ofrece
 * abrirla de entrada (así no salta a mitad de un cobro). «Ahora no» la deja
 * cerrada hasta la próxima entrada.
 */
const session = useSessionStore();
const open = ref(false);

watch(
    () => session.loginCount,
    async () => {
        if (!session.user || session.isCourier) return;
        try {
            open.value = !(await pos.cashSession());
        } catch {
            // Sin respuesta no molestamos: al cobrar se pedirá igualmente.
        }
    },
);
</script>
