<template>
    <header class="c-topbar">
        <slot name="start" />
        <div class="c-topbar__title"><slot>{{ title }}</slot></div>
        <span
            :class="['c-topbar__conn', { 'c-topbar__conn--connected': realtime.connected, 'c-topbar__conn--offline': offline }]"
            :title="connLabel"
            role="status"
            :aria-label="connLabel"
        ></span>
        <TpvButton variant="secondary" aria-label="Caja" @click="router.push('/caja')">Caja</TpvButton>
        <TpvButton variant="ghost" icon :aria-label="ui.theme === 'dark' ? 'Tema claro' : 'Tema oscuro'" @click="ui.toggleTheme()">
            {{ ui.theme === 'dark' ? '☀' : '☾' }}
        </TpvButton>
        <span v-if="session.user" class="c-topbar__user">
            <span class="c-topbar__avatar" :style="{ background: session.user.color }">{{ session.user.initials }}</span>
            <span class="c-topbar__user-name">{{ session.user.name }}</span>
        </span>
        <TpvButton variant="secondary" aria-label="Bloquear" @click="$emit('lock')">Bloquear</TpvButton>
    </header>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import TpvButton from './TpvButton.vue';
import { useRealtimeStore } from '@/stores/realtime';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';

defineProps({ title: { type: String, default: '' } });
defineEmits(['lock']);

const router = useRouter();
const realtime = useRealtimeStore();
const session = useSessionStore();
const ui = useUiStore();

const offline = computed(() => ['unavailable', 'failed', 'disconnected'].includes(realtime.state));
const connLabel = computed(() =>
    realtime.connected ? 'Conectado en tiempo real' : offline.value ? 'Sin conexión en tiempo real' : 'Conectando…',
);
</script>
