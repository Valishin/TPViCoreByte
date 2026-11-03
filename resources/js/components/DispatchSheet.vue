<template>
    <BottomSheet :open="!!order" :title="order ? `Dar salida · ${order.customer_name ?? ''}` : ''" @close="$emit('close')">
        <template v-if="order">
            <p class="v-config__rule">Se emite {{ recipient ? 'la factura' : 'el ticket' }} <strong>{{ order.prepaid_method ? 'como pagado' : 'pendiente de cobro' }}</strong> y se imprime el ticket de domicilio.</p>
            <div v-if="recipient" class="c-recipient__chosen">
                <span>Factura a <strong>{{ recipient.legal_name }}</strong><small>{{ recipient.tax_id }}</small></span>
                <TpvButton variant="secondary" size="sm" @click="recipientOpen = true">Cambiar</TpvButton>
                <TpvButton variant="ghost" size="sm" aria-label="Quitar los datos de factura" @click="recipient = null">✕</TpvButton>
            </div>
            <TpvButton v-else variant="secondary" block @click="recipientOpen = true">Factura con datos</TpvButton>
            <p class="v-config__question">¿Quién lo lleva?</p>
            <div class="c-options c-options--large">
                <button
                    v-for="c in couriers"
                    :key="c.id"
                    type="button"
                    :class="['c-option', 'c-option--person', { 'c-option--selected': chosen?.id === c.id }]"
                    :disabled="busy"
                    @click="pick(c)"
                >
                    <span class="c-option__avatar" :style="{ background: c.color }">{{ c.initials }}</span>
                    <span>{{ c.name }}<span v-if="order.change_needed" class="c-option__extra">{{ c.float ? `lleva fondo de ${money(c.float)}` : 'sin fondo' }}</span></span>
                </button>
            </div>
            <!-- «Cambio para 50 €»: sin fondo, el cambio sale de la caja (marcado); con fondo, lo da de su fondo (desmarcado). -->
            <label v-if="order.change_needed && chosen" class="v-checkout__toggle c-dispatch__change">
                <input v-model="changeFromTill" type="checkbox" />
                <span>
                    Le doy el cambio desde la caja: <strong>{{ money(order.change_needed) }}</strong>
                    <small v-if="changeFromTill">Paga con {{ money(order.cash_change_for) }}: al liquidar entregará {{ money(order.cash_change_for) }}.</small>
                    <small v-else>Da el cambio de su fondo{{ chosen.float ? ` (${money(chosen.float)})` : '' }}: al liquidar entregará {{ money(order.cash_change_for - order.change_needed) }} más su fondo.</small>
                </span>
            </label>
            <p v-if="!couriers.length" class="v-config__rule">No hay repartidores activos. Dalos de alta en /admin → Usuarios.</p>
        </template>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton v-if="order?.change_needed" variant="primary" block :disabled="!chosen" :loading="busy" @click="confirm(chosen)">
                {{ chosen ? `Dar salida con ${chosen.name}` : 'Elige el repartidor' }}
            </TpvButton>
        </template>
    </BottomSheet>
    <InvoiceRecipientSheet :open="recipientOpen" :hint="invoiceHint" :initial-query="order?.customer_name ?? ''" @close="recipientOpen = false" @select="(c) => { recipient = c; recipientOpen = false; }" />
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import InvoiceRecipientSheet from './InvoiceRecipientSheet.vue';
import TpvButton from './TpvButton.vue';
import pos from '@/services/pos';
import { money } from '@/utils/format';

// invoiceHint: aviso del servidor (más de 3.000 €, art. 4.2.c RD 1619/2012) que obliga a facturar.
const props = defineProps({ order: { type: Object, default: null }, busy: { type: Boolean, default: false }, invoiceHint: { type: String, default: '' } });
const emit = defineEmits(['confirm', 'close']);

// Factura completa con datos del cliente en vez del ticket (serie F).
const recipient = ref(null);
const recipientOpen = ref(false);
// «Cambio para»: primero se elige el repartidor; la casilla se marca si no lleva fondo.
const chosen = ref(null);
const changeFromTill = ref(true);
watch(() => props.order?.id, () => {
    recipient.value = null;
    recipientOpen.value = false;
    chosen.value = null;
    changeFromTill.value = true;
    if (props.order) loadCouriers();   // el fondo de cada uno, al día
});
function pick(courier) {
    if (!props.order?.change_needed) {
        confirm(courier);   // sin cambio que preparar: un toque y sale
        return;
    }
    chosen.value = courier;
    changeFromTill.value = !courier.float;
}
function confirm(courier) {
    emit('confirm', courier, recipient.value, !!props.order?.change_needed && changeFromTill.value);
}
watch(() => props.invoiceHint, (hint) => hint && (recipientOpen.value = true));

const couriers = ref([]);
async function loadCouriers() {
    couriers.value = await pos.couriers().catch(() => couriers.value);
}
onMounted(loadCouriers);
</script>
