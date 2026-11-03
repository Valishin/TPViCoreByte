<template>
    <div class="c-payment-picker">
        <!-- Lo que hay que decirle al cliente -->
        <p v-if="total" class="c-payment-picker__total">Total del pedido <strong>{{ money(total) }}</strong></p>
        <div class="c-options c-options--large" role="radiogroup" aria-label="Pago previsto">
            <button
                v-for="p in plans"
                :key="p.value"
                type="button"
                role="radio"
                :aria-checked="plan === p.value"
                :class="['c-option', { 'c-option--selected': plan === p.value }]"
                @click="setPlan(p.value)"
            >{{ p.label }}</button>
        </div>

        <!-- Efectivo: "cambio para" con el teclado de importes y billetes -->
        <div v-if="plan === 'efectivo'" class="c-payment-picker__extra">
            <p class="v-config__question">Cambio para <span class="c-option__extra">(opcional)</span></p>
            <AmountKeypad :model-value="modelValue.cash_change_for ?? 0" label="Paga con" :quick="[1000, 2000, 5000, 10000]" :exact="total || null" split @update:model-value="setChange" />
            <p v-if="modelValue.cash_change_for && total" class="c-payment-picker__change">
                Devolver <strong>{{ money(Math.max(0, modelValue.cash_change_for - total)) }}</strong>
            </p>
        </div>

        <!-- Ya pagado: con qué -->
        <div v-if="plan === 'pagado'" class="c-payment-picker__extra">
            <p class="v-config__question">¿Con qué se ha pagado?</p>
            <div class="c-options">
                <button
                    v-for="m in prepaidMethods"
                    :key="m.value"
                    type="button"
                    :class="['c-option', { 'c-option--selected': prepaid === m.value }]"
                    @click="$emit('update:modelValue', { payment: `pagado:${m.value}`, cash_change_for: null })"
                >{{ m.label }}</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import AmountKeypad from './AmountKeypad.vue';
import { money } from '@/utils/format';

/**
 * Pago previsto: efectivo (con "cambio para"), tarjeta (datáfono del
 * repartidor) o ya pagado (tarjeta, Bizum, online u otro).
 * v-model: { payment: 'efectivo' | 'tarjeta' | 'pagado:<método>', cash_change_for }
 */
const props = defineProps({
    modelValue: { type: Object, default: () => ({ payment: 'efectivo', cash_change_for: null }) },
    total: { type: Number, default: 0 },
    cardLabel: { type: String, default: 'Tarjeta' },
    // Al enviar un domicilio: solo efectivo o tarjeta (sin «Ya pagado»).
    allowPrepaid: { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

const plans = computed(() => [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: props.cardLabel },
    { value: 'pagado', label: 'Ya pagado' },
].filter((p) => props.allowPrepaid || p.value !== 'pagado'));
const prepaidMethods = [
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'bizum', label: 'Bizum' },
    { value: 'online', label: 'Online' },
    { value: 'otro', label: 'Otro' },
];
const plan = computed(() => (props.modelValue.payment ?? '').split(':')[0]);
const prepaid = computed(() => (props.modelValue.payment ?? '').split(':')[1] ?? null);

function setPlan(value) {
    emit('update:modelValue', value === 'pagado'
        ? { payment: 'pagado:tarjeta', cash_change_for: null }
        : { payment: value, cash_change_for: value === 'efectivo' ? props.modelValue.cash_change_for : null });
}
function setChange(cents) {
    emit('update:modelValue', { ...props.modelValue, cash_change_for: cents || null });
}
</script>
