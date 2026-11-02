<template>
    <Teleport to="body">
        <div v-if="url" ref="el" v-bind="layer.attrs.value" class="c-pdf-viewer" role="dialog" aria-modal="true" :aria-label="title" @keydown.esc="$emit('close')">
            <header class="c-pdf-viewer__bar">
                <strong class="c-pdf-viewer__title">{{ title }}</strong>
                <span class="c-pdf-viewer__actions">
                    <!-- Por si falla el email: el fichero para enviarlo a mano. -->
                    <a class="c-button c-button--secondary c-button--lg c-button--link" :href="downloadUrl" download>⤓ Descargar</a>
                    <TpvButton variant="primary" size="lg" @click="$emit('close')">✕ Cerrar y volver</TpvButton>
                </span>
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
 * (con su zoom e imprimir). «Descargar» guarda el fichero, por si falla el
 * envío por email y hay que mandarlo a mano.
 */
const props = defineProps({
    url: { type: String, default: null },
    title: { type: String, default: 'Factura' },
});
defineEmits(['close']);

const layer = useLayer(computed(() => !!props.url));
const downloadUrl = computed(() => (props.url ? `${props.url}${props.url.includes('?') ? '&' : '?'}descargar=1` : null));
const el = layer.el;
</script>
