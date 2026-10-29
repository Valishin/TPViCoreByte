<template>
    <article :class="['c-takeaway-card', left && `c-takeaway-card--${left.level}`]">
        <button type="button" class="c-takeaway-card__body" :disabled="order.status !== 'abierto'" @click="$emit('open', order)">
            <span class="c-takeaway-card__top">
                <span :class="['c-takeaway-card__type', `c-takeaway-card__type--${order.type}`]">{{ order.type === 'domicilio' ? 'DOMICILIO' : 'LLEVAR' }}</span>
                <span class="c-takeaway-card__total">{{ money(order.total) }}</span>
            </span>
            <span class="c-takeaway-card__name">{{ order.customer_name || 'Sin nombre' }}</span>
            <span v-if="order.type === 'domicilio'" class="c-takeaway-card__meta">
                {{ order.zone?.name ?? 'Sin zona' }}<template v-if="order.address_line"> · {{ order.address_line }}</template>
            </span>
            <span class="c-takeaway-card__meta">{{ promisedLabel(order) }} · {{ paymentLabel(order) }}</span>
            <span v-if="order.courier" class="c-takeaway-card__meta">Repartidor: <strong>{{ order.courier.name }}</strong></span>
            <span v-if="order.delivery_failed_reason" class="c-takeaway-card__meta">No entregado: {{ order.delivery_failed_reason }}</span>
            <span v-if="left" :class="['c-takeaway-card__left', `c-takeaway-card__left--${left.level}`]">
                {{ left.level === 'late' ? '⚠ ' : left.level === 'soon' ? '⏱ ' : '' }}{{ left.text }}
            </span>
        </button>
        <div v-if="actions.length" class="c-takeaway-card__actions">
            <TpvButton
                v-for="a in actions"
                :key="a.key"
                :variant="a.variant ?? 'primary'"
                size="lg"
                block
                :loading="busy === a.key"
                @click="$emit('action', a.key, order)"
            >{{ a.label }}</TpvButton>
        </div>
    </article>
</template>

<script setup>
import { computed } from 'vue';
import TpvButton from './TpvButton.vue';
import { paymentLabel, promisedLabel, timeLeft } from '@/stores/takeaway';
import { money } from '@/utils/format';

const props = defineProps({
    order: { type: Object, required: true },
    now: { type: Number, required: true },
    busy: { type: String, default: null },
});
defineEmits(['open', 'action']);

const left = computed(() => timeLeft(props.order, props.now));

/** Botón grande para pasar al siguiente paso. */
const actions = computed(() => {
    const o = props.order;
    switch (o.delivery_status) {
        case 'pendiente':
            return [{ key: 'open', label: o.pending_count ? `Abrir · ${o.pending_count} por enviar` : 'Abrir pedido', variant: 'secondary' }];
        case 'en_cocina':
            return [{ key: 'ready', label: 'Listo' }];
        case 'listo':
            if (o.type === 'domicilio') return [{ key: 'dispatch', label: 'Dar salida' }];
            return o.status !== 'abierto' || o.prepaid_method
                ? [{ key: 'deliver', label: 'Entregar', variant: 'success' }]
                : [{ key: 'checkout', label: 'Cobrar y entregar', variant: 'success' }];
        case 'en_reparto':
            return [{ key: 'couriers', label: 'Liquidar', variant: 'secondary' }];
        default:
            return [];
    }
});
</script>
