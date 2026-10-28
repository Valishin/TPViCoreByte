<template>
    <div class="v-order__panel">
        <div class="v-order__lines">
            <p v-if="!order.lines.length" class="v-order__empty">Toca un producto para añadirlo.</p>

            <!-- Pendientes de enviar: arriba y destacadas -->
            <template v-if="order.pending.length">
                <div class="c-order-group c-order-group--pending">
                    <span>Pendiente de enviar ({{ order.pendingCount }})</span>
                </div>
                <OrderLine v-for="line in order.pending" :key="line.id" :line="line" @select="$emit('select-line', line)" />
            </template>

            <!-- Envíos, del más reciente al más antiguo -->
            <template v-for="send in order.sends" :key="send.number">
                <div class="c-order-group">
                    <span>{{ ordinal(send.number) }} envío · {{ time(send.sent_at) }}</span>
                    <span class="c-order-group__jobs">
                        <button
                            v-for="job in send.jobs"
                            :key="job.id"
                            type="button"
                            :class="['c-badge', badgeClass(job)]"
                            :title="job.error || ''"
                            @click="job.status === 'fallido' && $emit('failed-job', job)"
                        >
                            {{ job.printer?.name }} {{ statusIcon(job) }}
                        </button>
                    </span>
                </div>
                <OrderLine v-for="line in send.lines" :key="line.id" :line="line" @select="$emit('select-line', line)" />
            </template>
        </div>

        <div class="v-order__totals">
            <span>Total</span>
            <span>{{ money(order.order?.total ?? 0) }}</span>
        </div>
        <div class="v-order__send">
            <TpvButton
                variant="primary"
                size="lg"
                block
                :disabled="!order.pendingCount"
                :loading="sending"
                :count="order.pendingCount || null"
                @click="$emit('send')"
            >
                Enviar
            </TpvButton>
        </div>
    </div>
</template>

<script setup>
import OrderLine from './OrderLine.vue';
import TpvButton from './TpvButton.vue';
import { useOrderStore } from '@/stores/order';
import { money, ordinal, time } from '@/utils/format';

defineProps({ sending: { type: Boolean, default: false } });
defineEmits(['select-line', 'send', 'failed-job']);

const order = useOrderStore();

function badgeClass(job) {
    return { impreso: 'c-badge--ok', fallido: 'c-badge--error', pendiente: 'c-badge--pending' }[job.status];
}
function statusIcon(job) {
    return { impreso: '✓', fallido: '✗ reintentar', pendiente: '…' }[job.status];
}
</script>
