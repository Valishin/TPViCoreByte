<template>
    <div ref="root" class="c-amount">
        <div class="c-amount__display" :aria-label="label">
            <span class="c-amount__label">{{ label }}</span>
            <span class="c-amount__value">{{ text || '0' }} €</span>
        </div>
        <div v-if="quick.length || exact !== null" class="c-amount__quick">
            <button v-if="exact !== null" type="button" class="c-option c-amount__quick-btn" @click="set(exact)">Importe exacto</button>
            <button v-for="q in quick" :key="q" type="button" class="c-option c-amount__quick-btn" @click="set(q)">{{ money(q) }}</button>
        </div>
        <div class="c-keypad">
            <button v-for="n in [1, 2, 3, 4, 5, 6, 7, 8, 9]" :key="n" type="button" class="c-keypad__key" @click="press(String(n))">{{ n }}</button>
            <button type="button" class="c-keypad__key" aria-label="Coma decimal" @click="press(',')">,</button>
            <button type="button" class="c-keypad__key" @click="press('0')">0</button>
            <button type="button" class="c-keypad__key c-keypad__key--action" aria-label="Borrar" @click="back">⌫</button>
        </div>
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { usePhysicalKeypad } from '@/composables/usePhysicalKeypad';
import { money } from '@/utils/format';

/**
 * Teclado de importes en euros: "20" son 20,00 €; la tecla "," abre los
 * céntimos (máx. 2). v-model en céntimos (entero).
 */
const props = defineProps({
    modelValue: { type: Number, default: 0 },
    label: { type: String, default: 'Importe' },
    quick: { type: Array, default: () => [] }, // céntimos: [500, 1000, 2000, 5000]
    exact: { type: Number, default: null }, // céntimos para "Importe exacto"
});
const emit = defineEmits(['update:modelValue']);

const text = ref(toText(props.modelValue));

function toText(cents) {
    if (!cents) return '';
    const units = Math.floor(cents / 100);
    const dec = cents % 100;
    return dec ? `${units},${String(dec).padStart(2, '0')}` : String(units);
}
function toCents(value) {
    if (!value) return 0;
    const [units, dec = ''] = value.split(',');
    return Number(units || 0) * 100 + Number(dec.padEnd(2, '0').slice(0, 2));
}

watch(() => props.modelValue, (cents) => {
    if (toCents(text.value) !== cents) text.value = toText(cents);
});

function press(key) {
    const value = text.value;
    if (key === ',') {
        if (!value.includes(',')) text.value = (value || '0') + ',';
    } else if (value.includes(',')) {
        if (value.split(',')[1].length < 2) text.value = value + key;
    } else if (value.length < 6) {
        text.value = value === '0' ? key : value + key;
    }
    emit('update:modelValue', toCents(text.value));
}
function back() {
    text.value = text.value.slice(0, -1);
    emit('update:modelValue', toCents(text.value));
}
function set(cents) {
    text.value = toText(cents);
    emit('update:modelValue', cents);
}

// También con el teclado físico (USB): cifras, «,» o «.» para los céntimos y Retroceso.
const root = ref(null);
usePhysicalKeypad(root, (key) => {
    if (/^\d$/.test(key)) press(key);
    else if (key === ',' || key === '.') press(',');
    else if (key === 'Backspace') back();
    else if (key === 'Delete') set(0);
    else return false;
    return true;
});
</script>
