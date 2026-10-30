<template>
    <BottomSheet :open="!!ticket" :title="ticket ? `Anular por error ${ticket.number}` : ''" @close="$emit('close')">
        <template v-if="ticket">
            <p class="v-config__rule">
                Solo si <strong>no hubo venta</strong> (ticket duplicado, emitido por error o de prueba). Se envía a Hacienda un
                registro de anulación, el documento deja de contar en las ventas y, si se cobró, se devuelve el importe.
            </p>
            <p class="v-config__rule">Si la venta existió (devolución, error de cobro, producto en mal estado), usa «Anular» (rectificativo).</p>
            <p class="v-config__question">Motivo</p>
            <div class="c-options">
                <button v-for="r in reasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': reason === r }]" @click="reason = r">{{ r }}</button>
            </div>
        </template>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton variant="danger" block :disabled="!reason" :loading="busy" @click="submit">Anular {{ ticket?.number }}</TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';

/** Anulación de un documento emitido por error, sin venta (RRSIF 11.1): registro de anulación de VERI*FACTU. */
const props = defineProps({ ticket: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);

const authorize = useAuthorizeStore();
const notices = useNoticesStore();
const reasons = ['Ticket duplicado', 'Emitido por error (no hubo venta)', 'Prueba o formación'];
const reason = ref('');
const busy = ref(false);

watch(() => props.ticket?.id, () => {
    reason.value = '';
});

async function submit() {
    busy.value = true;
    try {
        await authorize.withAuthorization('anular_ticket', (token) => pos.annul(props.ticket.id, { reason: reason.value, authorization_token: token }));
        notices.success(`${props.ticket.number} anulado`);
        emit('done');
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
