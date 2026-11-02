<template>
    <main class="v-about">
        <header class="v-order__header">
            <TpvButton variant="secondary" @click="router.back()">← Volver</TpvButton>
            <div class="v-order__where">Acerca de</div>
        </header>
        <section v-if="data" class="v-about__body">
            <dl class="v-about__facts">
                <dt>Obligado tributario</dt><dd>{{ data.obliged.name }} · NIF {{ data.obliged.nif }}</dd>
                <dt>Sistema</dt><dd>{{ data.system.name }} ({{ data.system.id }}) · versión {{ data.system.version }}</dd>
                <dt>Instalación</dt><dd>{{ data.system.installation }}</dd>
                <dt>Productor</dt><dd>{{ data.system.producer }} · NIF {{ data.system.producer_nif }}<br />{{ data.system.producer_address }}</dd>
                <dt>Soporte técnico</dt><dd><SupportContact /></dd>
                <dt>VERI*FACTU</dt><dd>{{ data.verifactu.active ? 'Activo' : 'No activo' }} · entorno de {{ data.verifactu.environment }}</dd>
            </dl>
            <!-- Declaración responsable (RRSIF 13.2; OM 15.3). Texto propio del producto, no del usuario. -->
            <article class="v-about__declaration" v-html="data.declaration_html"></article>
        </section>
    </main>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import SupportContact from '@/components/SupportContact.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos from '@/services/pos';

const router = useRouter();
const data = ref(null);
onMounted(async () => {
    data.value = await pos.about();
});
</script>
