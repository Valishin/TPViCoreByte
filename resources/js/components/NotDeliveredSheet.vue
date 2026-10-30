<template>
    <BottomSheet :open="!!order" title="No entregado" @close="$emit('close')">
        <template v-if="order">
            <p class="v-config__rule">
                {{ order.customer_name }} · {{ order.ticket?.number }} · {{ money(order.total) }}.
                Se anula el ticket con un rectificativo (sin devolución: no se cobró). Necesita autorización de encargado.
            </p>
            <p class="v-config__question">Motivo</p>
            <div class="c-options">
                <button v-for="r in reasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': reason === r }]" @click="reason = r">{{ r }}</button>
            </div>
            <TextField v-model="reason" label="Otro motivo" :maxlength="120" />
        </template>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Volver</TpvButton>
            <TpvButton variant="danger" block :disabled="!reason" :loading="busy" @click="confirm">Marcar no entregado</TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TextField from './TextField.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { money } from '@/utils/format';

/** "No entregado" con motivo y autorización: rectificativo total sin devolución. */
const props = defineProps({
    order: { type: Object, default: null },
    // Caja: /takeaway-orders/{id}/not-delivered; repartidor: /my-deliveries/{id}/not-delivered.
    courier: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'done']);
const authorize = useAuthorizeStore();
const notices = useNoticesStore();

const reasons = ['No está en casa', 'Rechaza el pedido', 'Dirección errónea', 'No contesta al teléfono'];
const reason = ref('');
const busy = ref(false);
watch(() => props.order?.id, () => (reason.value = ''));

async function confirm() {
    busy.value = true;
    try {
        const result = await authorize.withAuthorization('no_entregado', (token) =>
            (props.courier ? pos.myNotDelivered : pos.notDelivered)(props.order.id, { reason: reason.value, authorization_token: token }));
        notices.success(result.rectifying ? `No entregado · rectificativo ${result.rectifying.number}` : result.annulled ? `No entregado · ticket ${result.annulled.number} anulado` : 'Marcado como no entregado');
        emit('done', result.takeaway ?? result.data);
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
