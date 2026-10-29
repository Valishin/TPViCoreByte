<template>
    <template v-if="showShell">
        <TopBar :title="title" @lock="lock" />
        <PrintAlerts v-if="!session.isCourier" />
    </template>
    <RouterView />
    <AuthorizeModal />
    <ToastHost />
    <OnScreenKeyboard />
</template>

<script setup>
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthorizeModal from '@/components/AuthorizeModal.vue';
import OnScreenKeyboard from '@/components/OnScreenKeyboard.vue';
import PrintAlerts from '@/components/PrintAlerts.vue';
import ToastHost from '@/components/ToastHost.vue';
import TopBar from '@/components/TopBar.vue';
import { useInactivity } from '@/composables/useInactivity';
import { useCatalogStore } from '@/stores/catalog';
import { useNoticesStore } from '@/stores/notices';
import { useRealtimeStore } from '@/stores/realtime';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const realtime = useRealtimeStore();
const notices = useNoticesStore();
const catalog = useCatalogStore();
const ui = useUiStore();
ui.applyTheme();

const showShell = computed(() => session.isLoggedIn && !route.meta.public);
const title = computed(() => session.settings?.trade_name ?? 'TPV');

// Al entrar: tiempo real, avisos de impresión y carta (desde la caché si no ha cambiado).
watch(
    () => session.user?.id,
    (id) => {
        if (id) {
            realtime.start();
            // El repartidor solo usa "Mis repartos": sin carta ni avisos de impresión.
            if (!session.isCourier) {
                notices.loadPrinting().catch(() => {});
                catalog.load().catch(() => {});
            }
        } else {
            realtime.stop();
        }
    },
    { immediate: true },
);

async function lock() {
    await session.lock(route.fullPath);
    router.replace('/entrar');
}

useInactivity(() => session.lockMinutes, lock, () => session.isLoggedIn && !route.meta.public);
</script>
