<template>
    <div class="c-address-form">
        <div class="c-address-form__row">
            <TextField v-model="a.street" class="c-address-form__wide" label="Calle" :maxlength="255" placeholder="Calle de Atocha" />
            <TextField v-model="a.number" label="Número" :maxlength="20" />
        </div>
        <div class="c-address-form__row">
            <TextField v-model="a.floor_door" label="Piso y puerta" :maxlength="50" placeholder="3º B" />
            <TextField v-model="a.postal_code" label="Código postal" keyboard="numeric" inputmode="numeric" :maxlength="5" @update:model-value="suggestZone" />
            <TextField v-model="a.city" label="Población" :maxlength="255" />
        </div>
        <TextField v-model="a.instructions" label="Indicaciones" :maxlength="500" placeholder="Portal verde, timbre roto…" />

        <p class="v-config__question">Zona de reparto</p>
        <div class="c-options">
            <button
                v-for="z in zones"
                :key="z.id"
                type="button"
                :class="['c-option', { 'c-option--selected': a.delivery_zone_id === z.id }]"
                @click="a.delivery_zone_id = z.id"
            >
                {{ z.name }}{{ z.id === suggestedId ? ' (por CP)' : '' }}
                <span class="c-option__extra">Envío {{ money(z.delivery_fee) }} · mín. {{ money(z.minimum_order) }}</span>
            </button>
        </div>
        <p v-if="a.postal_code?.length === 5 && !suggestedId" class="v-config__rule">Ese código postal no está en ninguna zona: elige una a mano.</p>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import TextField from './TextField.vue';
import pos from '@/services/pos';
import { money } from '@/utils/format';

/** Dirección de entrega con la zona sugerida por el código postal. v-model: objeto dirección. */
const props = defineProps({ modelValue: { type: Object, default: () => ({}) } });
const emit = defineEmits(['update:modelValue']);

const a = reactive({ street: '', number: '', floor_door: '', postal_code: '', city: '', instructions: '', delivery_zone_id: null, ...props.modelValue });
const zones = ref([]);
const suggestedId = ref(null);

watch(a, () => emit('update:modelValue', { ...a }), { deep: true });

onMounted(async () => {
    const result = await pos.zones(a.postal_code).catch(() => ({ data: [] }));
    zones.value = result.data;
    suggestedId.value = result.suggested_id;
});

let last = '';
async function suggestZone(cp) {
    if (!cp || cp.length !== 5 || cp === last) return;
    last = cp;
    const result = await pos.zones(cp).catch(() => null);
    if (!result) return;
    zones.value = result.data;
    suggestedId.value = result.suggested_id;
    if (result.suggested_id) a.delivery_zone_id = result.suggested_id;
}
</script>
