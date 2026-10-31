<template>
    <button
        type="button"
        :class="[
            'c-table-card',
            `c-table-card--${table.status}`,
            {
                'c-table-card--round': table.shape === 'redonda',
                'c-table-card--grouped': group,
                [`c-table-card--${mark}`]: mark,
            },
        ]"
        :style="[style, group ? { '--group-color': groupColor(group) } : null]"
        :aria-label="ariaLabel"
        :aria-pressed="mark === 'selected' ? 'true' : mark === 'selectable' ? 'false' : undefined"
        :aria-disabled="mark === 'disabled' || mark === 'current' ? 'true' : undefined"
        @click="$emit('select', table)"
    >
        <span class="c-table-card__head">
            <span class="c-table-card__name">{{ table.name }}</span>
            <span v-if="group" class="c-table-card__group">{{ group.label }}</span>
        </span>
        <span v-if="mark === 'selected'" class="c-table-card__check" aria-hidden="true">✓</span>
        <span v-if="mark === 'current'" class="c-table-card__note">Esta mesa</span>
        <span v-else-if="note" class="c-table-card__note">{{ note }}</span>
        <template v-if="table.order">
            <span v-if="group && !group.is_main" class="c-table-card__total">Con mesa {{ mainName }}</span>
            <span v-else class="c-table-card__total">{{ money(table.total ?? table.order.total) }}</span>
            <span v-if="accounts > 1" class="c-table-card__accounts">{{ accounts }} cuentas</span>
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
import { groupColor } from '@/utils/tableGroups';

const props = defineProps({
    table: { type: Object, required: true },
    now: { type: Number, default: () => Date.now() },
    style: { type: Object, default: null },
    // Modo selección (unir mesas): selectable | selected | current | disabled
    mark: { type: String, default: null },
    note: { type: String, default: '' },
    mainName: { type: String, default: '' },
});
defineEmits(['select']);

const labels = { libre: 'libre', ocupada: 'ocupada', cuenta_pedida: 'cuenta pedida', fuera_servicio: 'fuera de servicio' };
const statusLabel = computed(() => labels[props.table.status] ?? props.table.status);
const minutes = computed(() => minutesSince(props.table.order?.opened_at, props.now));
const accounts = computed(() => props.table.orders?.length ?? (props.table.order ? 1 : 0));
const group = computed(() => props.table.group);
const ariaLabel = computed(() => {
    const parts = [`Mesa ${props.table.name}`, statusLabel.value];
    if (group.value) parts.push(`unida: mesas ${group.value.label}`);
    if (accounts.value > 1) parts.push(`${accounts.value} cuentas`);
    if (props.mark === 'current') parts.push('esta mesa');
    if (props.mark === 'selected') parts.push('elegida');
    if (props.note) parts.push(props.note);
    return parts.join(', ');
});
</script>
