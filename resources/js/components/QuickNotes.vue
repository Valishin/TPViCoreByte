<template>
    <div class="c-quick-notes">
        <div v-if="notes.length" class="c-chips">
            <button
                v-for="n in notes"
                :key="n"
                type="button"
                :class="['c-option', 'c-quick-notes__chip', { 'c-option--selected': selected.includes(n) }]"
                :aria-pressed="selected.includes(n)"
                @click="toggle(n)"
            >{{ n }}</button>
        </div>
        <TextField
            :model-value="free"
            label="Nota libre"
            :maxlength="120"
            placeholder="Escribe una nota para cocina…"
            @update:model-value="setFree"
            @change="$emit('commit', modelValue)"
        />
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import TextField from './TextField.vue';
import { joinNotes, splitNotes } from '@/utils/notes';

/**
 * Notas para cocina de una línea: botones de notas rápidas (un toque añade o
 * quita) y "Nota libre". "commit" avisa de que hay que guardar: al tocar un
 * botón o al terminar la nota libre.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    notes: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue', 'commit']);

const selected = computed(() => splitNotes(props.modelValue, props.notes).selected);

// La nota libre se edita en local: recalcularla del texto completo a cada
// pulsación se comería el espacio final mientras se escribe.
const free = ref(splitNotes(props.modelValue, props.notes).free);
let emitted = null;
watch(() => props.modelValue, (value) => {
    if (value !== emitted) free.value = splitNotes(value, props.notes).free;
});

function update(value) {
    emitted = value;
    emit('update:modelValue', value);
    return value;
}
function toggle(note) {
    const next = selected.value.includes(note)
        ? selected.value.filter((n) => n !== note)
        : props.notes.filter((n) => n === note || selected.value.includes(n));
    emit('commit', update(joinNotes(next, free.value)));
}
function setFree(value) {
    free.value = value;
    update(joinNotes(selected.value, value));
}
</script>
