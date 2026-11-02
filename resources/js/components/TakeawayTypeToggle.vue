<template>
    <div class="v-new-order__types" role="radiogroup" aria-label="Tipo de pedido">
        <button
            v-for="t in types"
            :key="t.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === t.value"
            :class="['v-new-order__type', { 'v-new-order__type--active': modelValue === t.value }]"
            @click="$emit('update:modelValue', t.value)"
        >{{ t.label }}</button>
    </div>
</template>

<script setup>
/** Domicilio / Para llevar (solo si el local tiene los dos). v-model: tipo. */
defineProps({
    modelValue: { type: String, default: null },
    types: { type: Array, required: true },
});
defineEmits(['update:modelValue']);
</script>
