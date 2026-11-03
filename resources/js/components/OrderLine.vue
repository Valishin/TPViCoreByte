<template>
    <button
        type="button"
        :class="['c-order-line', { 'c-order-line--pending': pending, 'c-order-line--voided': line.voided_at }]"
        @click="$emit('select', line)"
    >
        <span class="c-order-line__qty">{{ line.quantity }}</span>
        <span>
            <span class="c-order-line__name">{{ line.name }}<template v-if="line.variant_name && !isHalf"> ({{ line.variant_name }})</template></span>
            <span v-for="(m, i) in line.modifiers" :key="'m' + i" class="c-order-line__detail">
                + {{ m.quantity > 1 ? m.quantity + '× ' : '' }}{{ m.name }}
            </span>
            <span v-for="child in line.children" :key="child.id" class="c-order-line__detail">
                {{ child.kind === 'mitad' ? '½' : '·' }} {{ child.name }}<template v-if="child.variant_name && child.kind !== 'mitad'"> ({{ child.variant_name }})</template>
                <template v-for="(m, i) in child.modifiers" :key="i">, + {{ m.name }}</template>
                <template v-if="child.unit_price"> ({{ delta(child.unit_price) }})</template>
            </span>
            <span v-if="isHalf && line.half_and_half_surcharge" class="c-order-line__detail">
                + Suplemento mitad y mitad ({{ delta(line.half_and_half_surcharge) }})
            </span>
            <span v-if="line.notes" class="c-order-line__note">{{ line.notes }}</span>
            <span v-if="line.voided_at" class="c-order-line__detail">Anulada: {{ line.void_reason }}</span>
        </span>
        <span class="c-order-line__total">{{ line.is_invitation ? 'Invitación' : money(line.voided_at ? line.unit_price * line.quantity : line.line_total) }}</span>
    </button>
</template>

<script setup>
import { computed } from 'vue';
import { delta, money } from '@/utils/format';

const props = defineProps({
    line: { type: Object, required: true },
});
defineEmits(['select']);

const pending = computed(() => !props.line.sent_at && !props.line.voided_at);
const isHalf = computed(() => props.line.children?.some((c) => c.kind === 'mitad'));
</script>
