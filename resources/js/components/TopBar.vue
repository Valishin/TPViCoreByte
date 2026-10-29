<template>
    <header class="c-topbar">
        <slot name="start" />
        <!-- El nombre del local solo en caja: en móvil no cabe y no aporta. -->
        <div v-if="ui.isPos" class="c-topbar__title"><slot>{{ title }}</slot></div>

        <span v-if="session.user" class="c-topbar__user">
            <span class="c-topbar__avatar" :style="{ background: session.user.color }">{{ session.user.initials }}</span>
            <span class="c-topbar__user-name">{{ session.user.name }}</span>
        </span>
        <span
            :class="['c-topbar__conn', { 'c-topbar__conn--connected': realtime.connected, 'c-topbar__conn--offline': offline }]"
            :title="connLabel"
            role="status"
            :aria-label="connLabel"
        ></span>
        <span v-if="alerts" class="c-topbar__alerts" role="status" :aria-label="`${alerts} avisos de impresión`">⚠ {{ alerts }}</span>

        <span v-if="!ui.isPos" class="c-topbar__spacer"></span>

        <template v-if="ui.isPos">
            <TpvButton
                v-for="item in nav"
                :key="item.path"
                :variant="isActive(item.path) ? 'primary' : 'secondary'"
                :aria-current="isActive(item.path) ? 'page' : null"
                @click="router.push(item.path)"
            >{{ item.label }}</TpvButton>
            <TpvButton variant="ghost" icon :aria-label="ui.theme === 'dark' ? 'Tema claro' : 'Tema oscuro'" @click="ui.toggleTheme()">
                {{ ui.theme === 'dark' ? '☀' : '☾' }}
            </TpvButton>
            <TpvButton variant="secondary" aria-label="Bloquear" @click="$emit('lock')">Bloquear</TpvButton>
        </template>
        <TpvButton v-else variant="ghost" icon aria-label="Más opciones" @click="menu = true">⋯</TpvButton>

        <BottomSheet v-if="!ui.isPos" :open="menu" title="Opciones" @close="menu = false">
            <div class="c-topbar__menu">
                <TpvButton v-for="item in nav" :key="item.path" :variant="isActive(item.path) ? 'primary' : 'secondary'" size="lg" block @click="go(item.path)">
                    {{ item.label }}
                </TpvButton>
                <TpvButton variant="secondary" size="lg" block @click="ui.toggleTheme()">
                    {{ ui.theme === 'dark' ? '☀ Tema claro' : '☾ Tema oscuro' }}
                </TpvButton>
                <TpvButton variant="secondary" size="lg" block @click="menu = false; $emit('lock')">Bloquear</TpvButton>
            </div>
        </BottomSheet>
    </header>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import { useNoticesStore } from '@/stores/notices';
import { useRealtimeStore } from '@/stores/realtime';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

defineProps({ title: { type: String, default: '' } });
defineEmits(['lock']);

const router = useRouter();
const route = useRoute();
const realtime = useRealtimeStore();
const session = useSessionStore();
const notices = useNoticesStore();
const ui = useUiStore();
const menu = ref(false);

const offline = computed(() => ['unavailable', 'failed', 'disconnected'].includes(realtime.state));
const connLabel = computed(() =>
    realtime.connected ? 'Conectado en tiempo real' : offline.value ? 'Sin conexión en tiempo real' : 'Conectando…',
);
// Avisos de impresión: envíos fallidos + impresoras con error sin envío fallido.
const alerts = computed(() => notices.failedJobs.length + notices.printersInError.filter((p) => !notices.failedJobs.some((j) => j.printer?.id === p.id)).length);

// Secciones según los tipos de pedido activos (sala, llevar / domicilio). El repartidor solo ve sus repartos.
const nav = computed(() => {
    if (session.isCourier) return [{ path: '/repartos', label: 'Mis repartos' }];
    const items = [];
    if (session.hasFloor) items.push({ path: '/mesas', label: 'Mesas' });
    if (session.hasTakeaway) items.push({ path: '/pedidos', label: 'Pedidos' });
    if (session.hasType('domicilio')) items.push({ path: '/repartidores', label: 'Repartidores' });
    items.push({ path: '/caja', label: 'Caja' });
    return items;
});
function isActive(path) {
    return route.path === path || route.path.startsWith(`${path}/`);
}

function go(path) {
    menu.value = false;
    router.push(path);
}
</script>
