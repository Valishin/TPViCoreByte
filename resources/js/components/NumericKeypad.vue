<template>
    <div :class="['c-keypad', { 'c-keypad--error': error, 'c-keypad--disabled': disabled }]" :aria-disabled="disabled">
        <div class="c-keypad__display" :aria-label="masked ? 'PIN' : 'Número'">
            <template v-if="masked">
                <span v-for="i in length" :key="i" :class="['c-keypad__dot', { 'c-keypad__dot--filled': i <= value.length }]"></span>
            </template>
            <template v-else>{{ value || placeholder }}</template>
        </div>
        <button v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]" :key="n" type="button" class="c-keypad__key" :disabled="disabled" @click="press(String(n))">
            {{ n }}
        </button>
        <button type="button" class="c-keypad__key c-keypad__key--action" :disabled="disabled" @click="clear">Borrar</button>
        <button type="button" class="c-keypad__key" :disabled="disabled" @click="press('0')">0</button>
        <button v-if="okLabel" type="button" class="c-keypad__key c-keypad__key--ok" :disabled="!value" @click="$emit('submit', value)">
            {{ okLabel }}
        </button>
        <button v-else type="button" class="c-keypad__key c-keypad__key--action" aria-label="Borrar último" :disabled="disabled" @click="back">⌫</button>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    length: { type: Number, default: 4 }, // máximo de dígitos
    masked: { type: Boolean, default: false },
    error: { type: Boolean, default: false },
    okLabel: { type: String, default: null },
    placeholder: { type: String, default: '0' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'complete', 'submit']);

const value = computed(() => props.modelValue);

function press(digit) {
    if (props.modelValue.length >= props.length) return;
    const next = props.modelValue + digit;
    emit('update:modelValue', next);
    if (next.length === props.length) emit('complete', next);
}
function back() {
    emit('update:modelValue', props.modelValue.slice(0, -1));
}
function clear() {
    emit('update:modelValue', '');
}
</script>
