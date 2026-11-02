<template>
    <p v-if="support" class="c-support">
        <span class="c-support__title">Soporte técnico · {{ support.name }}</span>
        <!-- En el kiosko, solo texto: un enlace abriría la web sin forma de volver a la TPV. -->
        <template v-if="kiosk">
            <span>Tel. y WhatsApp {{ support.phone }}</span>
            <span>{{ support.web }}</span>
            <span>{{ support.email }}</span>
        </template>
        <template v-else>
            <a :href="`tel:+34${digits}`">Tel. {{ support.phone }}</a>
            <a :href="`https://wa.me/34${digits}`" target="_blank" rel="noopener">WhatsApp</a>
            <a :href="`https://${support.web}`" target="_blank" rel="noopener">{{ support.web }}</a>
            <a :href="`mailto:${support.email}`">{{ support.email }}</a>
        </template>
    </p>
</template>

<script setup>
import { computed } from 'vue';

/** Contacto del soporte técnico (config tpv.support, en la página): teléfono, WhatsApp, web y email. */
function read() {
    try {
        return JSON.parse(document.querySelector('meta[name="tpv-support"]')?.content || 'null');
    } catch {
        return null;
    }
}
const support = read();
const digits = computed(() => (support?.phone ?? '').replace(/\D/g, ''));
const kiosk = document.documentElement.dataset.kiosk === '1';
</script>
