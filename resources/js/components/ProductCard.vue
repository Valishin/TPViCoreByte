<template>
    <button
        type="button"
        :class="['c-product-card', { 'c-product-card--unavailable': !product.available, 'c-product-card--flash': flash }]"
        :style="{ '--category-color': color }"
        :disabled="!product.available"
        @click="$emit('select', product)"
    >
        <span v-if="flags" class="c-product-card__flags">{{ flags }}</span>
        <span class="c-product-card__name">{{ product.name }}</span>
        <span class="c-product-card__price">{{ product.available ? priceText : 'Agotado' }}</span>
    </button>
</template>

<script setup>
import { computed } from 'vue';
import { money } from '@/utils/format';

const props = defineProps({
    product: { type: Object, required: true },
    color: { type: String, default: null },
    flash: { type: Boolean, default: false },
});
defineEmits(['select']);

const priceText = computed(() => {
    const v = props.product.variants;
    if (v.length) return `desde ${money(Math.min(...v.map((x) => x.price)))}`;
    return money(props.product.price);
});
const flags = computed(() => {
    const p = props.product;
    if (p.type === 'menu') return 'Menú';
    if (p.allows_half_and_half) return '½';
    if (p.variants.length || p.modifier_group_ids.length) return '⋯';
    return '';
});
</script>
