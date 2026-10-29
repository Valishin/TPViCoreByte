<template>
    <div class="c-options c-options--large" role="radiogroup" aria-label="Hora prometida">
        <button
            type="button"
            role="radio"
            :aria-checked="modelValue.asap === true"
            :class="['c-option', { 'c-option--selected': modelValue.asap === true }]"
            @click="$emit('update:modelValue', { asap: true })"
        >Lo antes posible <span class="c-option__extra">{{ asapAt }}</span></button>
        <button
            v-for="m in [20, 30, 45, 60]"
            :key="m"
            type="button"
            role="radio"
            :aria-checked="modelValue.promised_in === m"
            :class="['c-option', { 'c-option--selected': modelValue.promised_in === m }]"
            @click="$emit('update:modelValue', { promised_in: m })"
        >+{{ m }} min <span class="c-option__extra">{{ at(m) }}</span></button>
    </div>
</template>

<script setup>
import { computed } from 'vue';

/** Hora prometida: "Lo antes posible" (según el tiempo estimado de Ajustes) o +20/+30/+45/+60 min. */
const props = defineProps({
    modelValue: { type: Object, default: () => ({ asap: true }) },
    minutes: { type: Number, default: 30 }, // tiempo estimado de "Lo antes posible"
    now: { type: Number, default: () => Date.now() },
});
defineEmits(['update:modelValue']);

function at(minutes) {
    return new Date(props.now + minutes * 60000).toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
}
const asapAt = computed(() => at(props.minutes));
</script>
