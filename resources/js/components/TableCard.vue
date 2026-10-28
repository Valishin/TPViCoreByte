<template>
    <button
        type="button"
        :class="['c-table-card', `c-table-card--${table.status}`, { 'c-table-card--round': table.shape === 'redonda' }]"
        :style="style"
        :aria-label="`Mesa ${table.name}, ${statusLabel}`"
        @click="$emit('select', table)"
    >
        <span class="c-table-card__name">{{ table.name }}</span>
        <template v-if="table.order">
            <span class="c-table-card__total">{{ money(table.order.total) }}</span>
            <span class="c-table-card__meta">
                <span>{{ duration(minutes) }}<template v-if="table.order.guests"> · {{ table.order.guests }}p</template></span>
                <span
                    v-if="table.order.waiter"
                    class="c-table-card__waiter"
                    :style="{ background: table.order.waiter.color }"
                    :title="table.order.waiter.name"
                >{{ table.order.waiter.initials }}</span>
            </span>
        </template>
        <span v-else class="c-table-card__meta">
            <span class="c-table-card__seats">{{ table.seats }} plazas</span>
        </span>
    </button>
</template>

<script setup>
import { computed } from 'vue';
import { duration, minutesSince, money } from '@/utils/format';

const props = defineProps({
    table: { type: Object, required: true },
    now: { type: Number, default: () => Date.now() },
    style: { type: Object, default: null },
});
defineEmits(['select']);

const labels = { libre: 'libre', ocupada: 'ocupada', cuenta_pedida: 'cuenta pedida', fuera_servicio: 'fuera de servicio' };
const statusLabel = computed(() => labels[props.table.status] ?? props.table.status);
const minutes = computed(() => minutesSince(props.table.order?.opened_at, props.now));
</script>
