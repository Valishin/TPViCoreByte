<template>
    <Teleport to="body">
        <div v-if="open" ref="el" v-bind="layer.attrs.value" :class="['c-modal', { 'c-modal--danger': danger, 'c-modal--wide': wide }]" role="dialog" aria-modal="true" :aria-label="title">
            <div class="c-modal__backdrop" @click="$emit('close')"></div>
            <div class="c-modal__panel">
                <h2 class="c-modal__title">{{ title }}</h2>
                <p v-if="text" class="c-modal__text">{{ text }}</p>
                <slot />
                <div v-if="$slots.actions" class="c-modal__actions"><slot name="actions" /></div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { toRef } from 'vue';
import { useLayer } from '@/composables/useLayer';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    text: { type: String, default: '' },
    danger: { type: Boolean, default: false },
    // Más ancha (p. ej. forma de pago con el teclado al lado).
    wide: { type: Boolean, default: false },
});
defineEmits(['close']);

// Pila de ventanas: siempre encima de la que la abrió y la única que recibe toques y teclas.
const layer = useLayer(toRef(props, 'open'));
const el = layer.el;
</script>
