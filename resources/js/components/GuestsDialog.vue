<template>
    <ModalDialog :open="open" :title="title" @close="$emit('close')">
        <!-- 1 a 8 con un toque; «Más de 8» abre el teclado (y «8 o menos» vuelve). Sin scroll. -->
        <div v-if="!typing" class="c-options">
            <button
                v-for="n in 8"
                :key="n"
                type="button"
                :class="['c-option', { 'c-option--selected': current === n }]"
                :aria-label="`${n} ${n === 1 ? 'persona' : 'personas'}`"
                @click="$emit('confirm', n)"
            >
                <strong>{{ n }}</strong> <span class="c-option__extra">{{ n === 1 ? 'persona' : 'personas' }}</span>
            </button>
            <button type="button" :class="['c-option', 'c-guests__more', { 'c-option--selected': current > 8 }]" @click="showKeypad">
                <strong>Más de 8</strong><span v-if="current > 8" class="c-option__extra">ahora {{ current }}</span>
            </button>
        </div>
        <template v-else>
            <NumericKeypad v-model="value" :length="2" placeholder="Comensales" ok-label="OK" @submit="submit" />
            <TpvButton variant="secondary" block class="c-guests__back" @click="typing = false">← 8 o menos</TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import ModalDialog from './ModalDialog.vue';
import NumericKeypad from './NumericKeypad.vue';
import TpvButton from './TpvButton.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '¿Cuántos comensales?' },
    current: { type: Number, default: null },
});
const emit = defineEmits(['confirm', 'close']);

const value = ref('');
const typing = ref(false);
watch(() => props.open, (open) => {
    if (!open) return;
    // Mesa que ya tenía más de 8: directamente al teclado con su número.
    typing.value = props.current > 8;
    value.value = props.current > 8 ? String(props.current) : '';
});

function showKeypad() {
    value.value = props.current > 8 ? String(props.current) : '';
    typing.value = true;
}
function submit(v) {
    if (Number(v) > 0) emit('confirm', Number(v));
}
</script>
