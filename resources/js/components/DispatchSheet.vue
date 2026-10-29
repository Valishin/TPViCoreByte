<template>
    <BottomSheet :open="!!order" :title="order ? `Dar salida · ${order.customer_name ?? ''}` : ''" @close="$emit('close')">
        <template v-if="order">
            <p class="v-config__rule">Se emite el ticket <strong>{{ order.prepaid_method ? 'como pagado' : 'pendiente de cobro' }}</strong> y se imprime el ticket de domicilio.</p>
            <p class="v-config__question">¿Quién lo lleva?</p>
            <div class="c-options c-options--large">
                <button
                    v-for="c in couriers"
                    :key="c.id"
                    type="button"
                    class="c-option c-option--person"
                    :disabled="busy"
                    @click="$emit('confirm', c)"
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
</template>

<script setup>
import { onMounted, ref } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import pos from '@/services/pos';

defineProps({ order: { type: Object, default: null }, busy: { type: Boolean, default: false } });
defineEmits(['confirm', 'close']);

const couriers = ref([]);
onMounted(async () => {
    couriers.value = await pos.couriers().catch(() => []);
});
</script>
