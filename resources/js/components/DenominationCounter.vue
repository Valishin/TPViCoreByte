<template>
    <div class="c-denoms">
        <div v-for="d in denominations" :key="d" class="c-denoms__row">
            <span class="c-denoms__value">{{ label(d) }}</span>
            <span class="c-stepper">
                <TpvButton variant="secondary" icon size="sm" :aria-label="`Quitar ${label(d)}`" :disabled="!counts[d]" @click="change(d, -1)">−</TpvButton>
                <span class="c-stepper__value">{{ counts[d] || 0 }}</span>
                <TpvButton variant="secondary" icon size="sm" :aria-label="`Añadir ${label(d)}`" @click="change(d, 1)">+</TpvButton>
            </span>
            <span class="c-denoms__total">{{ money((counts[d] || 0) * d) }}</span>
        </div>
        <div class="c-denoms__sum">Total contado: <strong>{{ money(total) }}</strong></div>
    </div>
</template>

<script setup>
import { computed, reactive } from 'vue';
import TpvButton from './TpvButton.vue';
import { money } from '@/utils/format';

/** Desglose de billetes y monedas. Emite { breakdown: {céntimos: unidades}, total }. */
const emit = defineEmits(['change']);
const denominations = [10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];
const counts = reactive({});
const total = computed(() => denominations.reduce((t, d) => t + (counts[d] || 0) * d, 0));

function label(cents) {
    return cents >= 100 ? `${cents / 100} €` : `${cents} cént.`;
}
function change(d, delta) {
    counts[d] = Math.max(0, (counts[d] || 0) + delta);
    const breakdown = Object.fromEntries(Object.entries(counts).filter(([, n]) => n > 0));
    emit('change', { breakdown, total: total.value });
}
</script>
