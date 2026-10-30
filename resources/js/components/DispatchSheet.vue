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
                    class="c-option c-option--person"
                    :disabled="busy"
                    @click="$emit('confirm', c, recipient)"
                >
                    <span class="c-option__avatar" :style="{ background: c.color }">{{ c.initials }}</span>
                    {{ c.name }}
                </button>
            </div>
            <p v-if="!couriers.length" class="v-config__rule">No hay repartidores activos. Dalos de alta en /admin → Usuarios.</p>
        </template>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
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

// invoiceHint: aviso del servidor (más de 3.000 €, art. 4.2.c RD 1619/2012) que obliga a facturar.
const props = defineProps({ order: { type: Object, default: null }, busy: { type: Boolean, default: false }, invoiceHint: { type: String, default: '' } });
defineEmits(['confirm', 'close']);

// Factura completa con datos del cliente en vez del ticket (serie F).
const recipient = ref(null);
const recipientOpen = ref(false);
watch(() => props.order?.id, () => {
    recipient.value = null;
    recipientOpen.value = false;
});
watch(() => props.invoiceHint, (hint) => hint && (recipientOpen.value = true));

const couriers = ref([]);
onMounted(async () => {
    couriers.value = await pos.couriers().catch(() => []);
});
</script>
