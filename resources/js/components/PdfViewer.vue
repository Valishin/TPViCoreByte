<template>
    <Teleport to="body">
        <div v-if="url" ref="el" v-bind="layer.attrs.value" class="c-pdf-viewer" role="dialog" aria-modal="true" :aria-label="title" @keydown.esc="$emit('close')">
            <header class="c-pdf-viewer__bar">
                <strong class="c-pdf-viewer__title">{{ title }}</strong>
                <TpvButton variant="primary" size="lg" @click="$emit('close')">✕ Cerrar y volver</TpvButton>
            </header>
            <iframe class="c-pdf-viewer__frame" :src="url" :title="title"></iframe>
        </div>
    </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import TpvButton from './TpvButton.vue';
import { useLayer } from '@/composables/useLayer';

/**
 * PDF (factura) dentro de la TPV, a pantalla completa y con «Cerrar y volver»
 * siempre a la vista. En el kiosko una pestaña nueva no tiene forma de volver
 * al programa; aquí el visor de PDF del navegador va dentro de la ventana
 * (con su zoom, imprimir y descargar).
 */
const props = defineProps({
    url: { type: String, default: null },
    title: { type: String, default: 'Factura' },
});
defineEmits(['close']);

const layer = useLayer(computed(() => !!props.url));
const el = layer.el;
</script>
