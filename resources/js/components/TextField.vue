<template>
    <label class="c-field">
        <span v-if="label" class="c-field__label">{{ label }}</span>
        <component
            :is="multiline ? 'textarea' : 'input'"
            v-keyboard="keyboard"
            v-bind="$attrs"
            class="c-field__input"
            :class="{ 'c-field__input--multiline': multiline }"
            :type="multiline ? undefined : type"
            :value="modelValue"
            :maxlength="maxlength"
            :placeholder="placeholder"
            @input="$emit('update:modelValue', $event.target.value)"
            @change="$emit('change', $event.target.value)"
        />
    </label>
</template>

<script setup>
/**
 * Campo de texto del TPV: en caja usa el teclado en pantalla (y nunca el del
 * sistema); en móvil, el del teléfono. keyboard="numeric" para teléfonos,
 * códigos postales…
 */
defineOptions({ inheritAttrs: false });
defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
    type: { type: String, default: 'text' },
    keyboard: { type: String, default: 'text' },
    maxlength: { type: [Number, String], default: undefined },
    placeholder: { type: String, default: undefined },
    multiline: { type: Boolean, default: false },
});
defineEmits(['update:modelValue', 'change']);
</script>
