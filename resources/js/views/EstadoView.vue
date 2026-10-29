<template>
    <main class="v-estado">
        <header class="v-estado__header">
            <h1 class="v-estado__title">Estado del sistema</h1>
            <p class="v-estado__host">Conectado a <strong>{{ host }}</strong> · versión <strong>{{ version }}</strong></p>
        </header>

        <div class="v-estado__grid">
            <StatusCard title="API y base de datos" v-bind="store.api">
                <TpvButton block :loading="store.api.status === 'loading'" @click="store.checkApi()">
                    Comprobar API
                </TpvButton>
            </StatusCard>

            <StatusCard
                title="Tiempo real (Reverb)"
                :status="store.reverb.status"
                :message="store.reverb.message"
                :detail="store.reverb.last"
            >
                <p class="v-estado__socket">
                    Socket: <span :class="['v-estado__badge', `v-estado__badge--${store.reverb.connection}`]">{{ store.reverb.connection }}</span>
                </p>
                <TpvButton block variant="secondary" :loading="store.reverb.status === 'loading'" @click="store.sendPing()">
                    Enviar evento a todas las pantallas
                </TpvButton>
            </StatusCard>

            <StatusCard title="Impresora" v-bind="store.printer">
                <TpvButton block variant="secondary" :loading="store.printer.status === 'loading'" @click="store.printTest()">
                    Imprimir prueba
                </TpvButton>
            </StatusCard>
        </div>
    </main>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import StatusCard from '@/components/StatusCard.vue';
import TpvButton from '@/components/TpvButton.vue';
import { useDiagnosticsStore } from '@/stores/diagnostics';

const store = useDiagnosticsStore();
const version = document.querySelector('meta[name="app-version"]')?.content || 'dev';
const host = `${window.location.hostname}:${window.location.port || 80}`;

onMounted(() => {
    store.listen();
    store.checkApi();
});
onUnmounted(() => store.stop());
</script>
