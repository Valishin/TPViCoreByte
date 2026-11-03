<template>
    <Teleport to="body">
        <div v-if="open" ref="el" v-bind="layer.attrs.value" :class="['c-sheet', { 'c-sheet--narrow': narrow }]" role="dialog" aria-modal="true" :aria-label="title">
            <div class="c-sheet__backdrop" @click="$emit('close')"></div>
            <div class="c-sheet__panel">
                <header class="c-sheet__header">
                    <h2 class="c-sheet__title">{{ title }}</h2>
                    <slot name="header" />
                    <TpvButton variant="ghost" icon aria-label="Cerrar" @click="$emit('close')">✕</TpvButton>
                </header>
                <div class="c-sheet__body"><slot /></div>
                <footer v-if="$slots.footer" class="c-sheet__footer"><slot name="footer" /></footer>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { toRef } from 'vue';
import TpvButton from './TpvButton.vue';
import { useLayer } from '@/composables/useLayer';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    // Estrecha (menús de pocos botones, p. ej. Ajustes).
    narrow: { type: Boolean, default: false },
});
defineEmits(['close']);

const layer = useLayer(toRef(props, 'open'));
const el = layer.el;
</script>
