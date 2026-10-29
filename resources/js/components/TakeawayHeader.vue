<template>
    <section class="c-takeaway-header" aria-label="Datos del pedido">
        <span :class="['c-takeaway-card__type', `c-takeaway-card__type--${t.type}`]">{{ t.type === 'domicilio' ? 'DOMICILIO' : 'LLEVAR' }}</span>
        <div class="c-takeaway-header__main">
            <strong class="c-takeaway-header__name">{{ t.customer_name || 'Sin nombre' }}</strong>
            <span v-if="t.customer_phone">{{ t.customer_phone }}</span>
            <span v-if="t.address_line" class="c-takeaway-header__address">{{ t.address_line }}</span>
            <span v-if="t.address?.instructions" class="c-takeaway-header__address">{{ t.address.instructions }}</span>
        </div>
        <div class="c-takeaway-header__side">
            <span>{{ promisedLabel(t) }}</span>
            <span>{{ paymentLabel(t) }}<template v-if="t.cash_change_for"> · cambio para {{ money(t.cash_change_for) }}</template></span>
            <span v-if="t.zone">{{ t.zone.name }} · envío {{ money(t.zone.delivery_fee) }} · mín. {{ money(t.zone.minimum_order) }}</span>
        </div>
        <TpvButton v-if="editable" variant="secondary" @click="editing = true">Hora y pago</TpvButton>
        <p v-if="t.customer_notes" class="c-alert c-alert--warning c-takeaway-header__notes"><span class="c-alert__text">⚠ {{ t.customer_notes }}</span></p>

        <BottomSheet :open="editing" title="Hora y pago" @close="editing = false">
            <p class="v-config__question">{{ t.type === 'domicilio' ? 'Hora de entrega' : 'Hora de recogida' }}</p>
            <TakeawayTimePicker v-model="time" :minutes="asapMinutes" />
            <p class="v-config__question">Pago previsto</p>
            <TakeawayPaymentPicker v-model="payment" :total="total" />
            <template #footer>
                <TpvButton variant="ghost" @click="editing = false">Cancelar</TpvButton>
                <TpvButton variant="primary" block :loading="saving" @click="save">Guardar</TpvButton>
            </template>
        </BottomSheet>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TakeawayPaymentPicker from './TakeawayPaymentPicker.vue';
import TakeawayTimePicker from './TakeawayTimePicker.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { paymentLabel, promisedLabel, useTakeawayStore } from '@/stores/takeaway';
import { money } from '@/utils/format';

/** Cabecera de la comanda de llevar / domicilio: cliente, dirección, hora y pago (editables). */
const props = defineProps({
    takeaway: { type: Object, required: true },
    total: { type: Number, default: 0 },
    editable: { type: Boolean, default: true },
});
const emit = defineEmits(['updated']);
const notices = useNoticesStore();
const board = useTakeawayStore();

const t = computed(() => props.takeaway);
const editing = ref(false);
const saving = ref(false);
const time = ref({ asap: true });
const payment = ref({ payment: 'efectivo', cash_change_for: null });
const asapMinutes = computed(() => (t.value.type === 'domicilio' ? board.settings?.delivery_minutes ?? 35 : board.settings?.takeaway_prep_minutes ?? 15));

watch(editing, (open) => {
    if (!open) return;
    time.value = t.value.asap ? { asap: true } : { promised_at: t.value.promised_at };
    payment.value = t.value.prepaid_method
        ? { payment: `pagado:${t.value.prepaid_method}`, cash_change_for: null }
        : { payment: t.value.planned_payment_method ?? 'efectivo', cash_change_for: t.value.cash_change_for };
});

async function save() {
    saving.value = true;
    try {
        const changes = { ...payment.value };
        if (time.value.asap || time.value.promised_in) Object.assign(changes, time.value);
        const result = await pos.updateTakeaway(t.value.id, changes);
        emit('updated', result.data);
        editing.value = false;
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        saving.value = false;
    }
}
</script>
