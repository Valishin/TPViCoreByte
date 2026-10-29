<template>
    <BottomSheet :open="open" title="Dividir por productos" @close="$emit('close')">
        <p class="v-config__rule">Elige lo que se paga aparte: irá a una cuenta nueva con su propio ticket.</p>
        <div v-for="line in lines" :key="line.id" class="v-config__row" style="margin-bottom: 0.5rem">
            <span>{{ line.name }}<template v-if="line.variant_name"> ({{ line.variant_name }})</template> · {{ money(line.line_total) }}</span>
            <span class="c-stepper">
                <TpvButton variant="secondary" icon size="sm" aria-label="Menos" :disabled="!picked[line.id]" @click="picked[line.id]--">−</TpvButton>
                <span class="c-stepper__value">{{ picked[line.id] || 0 }}/{{ line.quantity }}</span>
                <TpvButton variant="secondary" icon size="sm" aria-label="Más" :disabled="(picked[line.id] || 0) >= line.quantity" @click="picked[line.id] = (picked[line.id] || 0) + 1">+</TpvButton>
            </span>
        </div>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton variant="primary" block :disabled="!count" :loading="busy" @click="split">Separar {{ count }} y cobrar</TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { useOrderStore } from '@/stores/order';
import { money } from '@/utils/format';

const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'split']);

const order = useOrderStore();
const notices = useNoticesStore();
const picked = reactive({});
const busy = ref(false);
const lines = computed(() => (order.order?.lines ?? []).filter((l) => !l.voided_at));
const count = computed(() => Object.values(picked).reduce((t, n) => t + n, 0));

watch(() => props.open, () => Object.keys(picked).forEach((k) => delete picked[k]));

async function split() {
    busy.value = true;
    try {
        const selection = Object.fromEntries(Object.entries(picked).filter(([, n]) => n > 0));
        emit('split', await pos.split(order.order.id, selection));
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
