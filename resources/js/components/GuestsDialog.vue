<template>
    <ModalDialog :open="open" :title="title" @close="$emit('close')">
        <div class="c-options">
            <button v-for="n in 8" :key="n" type="button" :class="['c-option', { 'c-option--selected': Number(value) === n }]" @click="$emit('confirm', n)">
                <strong>{{ n }}</strong> <span class="c-option__extra">{{ n === 1 ? 'persona' : 'personas' }}</span>
            </button>
        </div>
        <p class="c-modal__text" style="margin-top: 1rem">¿Más de 8?</p>
        <NumericKeypad v-model="value" :length="2" ok-label="OK" @submit="(v) => Number(v) > 0 && $emit('confirm', Number(v))" />
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import ModalDialog from './ModalDialog.vue';
import NumericKeypad from './NumericKeypad.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '¿Cuántos comensales?' },
    current: { type: Number, default: null },
});
defineEmits(['confirm', 'close']);

const value = ref('');
watch(() => props.open, (open) => {
    if (open) value.value = props.current ? String(props.current) : '';
});
</script>
