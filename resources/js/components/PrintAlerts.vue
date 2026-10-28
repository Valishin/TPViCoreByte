<template>
    <div v-if="notices.failedJobs.length || orphanPrinters.length" class="c-alerts" role="alert">
        <div v-for="job in notices.failedJobs" :key="job.id" class="c-alert">
            <span class="c-alert__text">
                {{ where(job) }} · {{ job.printer?.name }}: {{ kindText(job) }} sin imprimir
                <span class="c-alert__detail">{{ job.error }}</span>
            </span>
            <span class="c-alert__actions">
                <TpvButton variant="secondary" size="sm" @click="retry(job)">Reintentar</TpvButton>
                <TpvButton variant="secondary" size="sm" @click="redirecting = job">Enviar a otra</TpvButton>
            </span>
        </div>
        <div v-for="p in orphanPrinters" :key="'p' + p.id" class="c-alert c-alert--warning">
            <span class="c-alert__text">
                Impresora {{ p.name }} con error
                <span class="c-alert__detail">{{ p.error }}</span>
            </span>
        </div>

        <ModalDialog :open="!!redirecting" title="Enviar a otra impresora" @close="redirecting = null">
            <div class="c-options">
                <button
                    v-for="p in targets"
                    :key="p.id"
                    type="button"
                    class="c-option"
                    @click="redirect(p.id)"
                >
                    {{ p.name }}
                    <span class="c-option__extra">{{ p.status === 'error' ? 'con error' : 'OK' }}</span>
                </button>
            </div>
            <template #actions>
                <TpvButton variant="ghost" @click="redirecting = null">Cancelar</TpvButton>
            </template>
        </ModalDialog>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import TpvButton from './TpvButton.vue';
import ModalDialog from './ModalDialog.vue';
import { useNoticesStore } from '@/stores/notices';
import { errorMessage } from '@/services/pos';
import { ordinal } from '@/utils/format';

const notices = useNoticesStore();
const redirecting = ref(null);

// Impresoras con error que no tienen ya un aviso de envío fallido.
const orphanPrinters = computed(() =>
    notices.printersInError.filter((p) => !notices.failedJobs.some((j) => j.printer?.id === p.id)),
);
const targets = computed(() => notices.printers.filter((p) => p.id !== redirecting.value?.printer?.id));

const types = { para_llevar: 'Para llevar', domicilio: 'Domicilio', barra: 'Barra' };
function where(job) {
    return job.table ? `Mesa ${job.table}` : types[job.order_type] ?? 'Pedido';
}
function kindText(job) {
    if (job.kind === 'anulacion') return 'anulación';
    if (job.kind === 'cuenta') return 'cuenta';
    return job.send_number ? `${ordinal(job.send_number)} envío` : 'comanda';
}
async function retry(job) {
    try {
        await notices.retry(job);
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
async function redirect(printerId) {
    const job = redirecting.value;
    redirecting.value = null;
    try {
        await notices.redirect(job, printerId);
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
</script>
