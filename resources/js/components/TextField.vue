<template>
    <label :class="['c-field', $attrs.class]">
        <span v-if="label" class="c-field__label">{{ label }}</span>
        <component
            :is="multiline ? 'textarea' : 'input'"
            v-keyboard="keyboard"
            v-bind="inputAttrs"
            class="c-field__input"
            :class="{ 'c-field__input--multiline': multiline }"
            :type="multiline ? undefined : type"
            :value="modelValue"
            :maxlength="maxlength"
            :placeholder="placeholder"
            :aria-invalid="error ? 'true' : undefined"
            :data-osk-hint="error || hint || undefined"
            @input="$emit('update:modelValue', $event.target.value)"
            @change="$emit('change', $event.target.value)"
        />
        <span v-if="error || hint" :class="['c-field__hint', { 'c-field__hint--error': error }]" aria-live="polite">{{ error || hint }}</span>
    </label>
</template>

<script setup>
/**
 * Campo de texto del TPV: en caja usa el teclado en pantalla (y nunca el del
 * sistema); en móvil, el del teléfono. keyboard="numeric" para teléfonos,
 * códigos postales… error / hint: aviso bajo el campo, que también sale en el
 * teclado en pantalla mientras se escribe.
 */
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });
// class va al contenedor (para colocar el campo entero); el resto, al <input>.
const attrs = useAttrs();
const inputAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;
    return rest;
});
defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
    type: { type: String, default: 'text' },
    keyboard: { type: String, default: 'text' },
    maxlength: { type: [Number, String], default: undefined },
    placeholder: { type: String, default: undefined },
    multiline: { type: Boolean, default: false },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
});
defineEmits(['update:modelValue', 'change']);
</script>
