<template>
    <!-- Avisos de tiempo: pequeños, arriba a la derecha; ✕ los quita; tocarlos abre el pedido. -->
    <aside v-if="visible.length" class="c-time-alerts" aria-label="Avisos de tiempo" aria-live="polite">
        <div v-for="a in visible" :key="a.key" :class="['c-time-alerts__item', `c-time-alerts__item--${a.level}`]">
            <button type="button" class="c-time-alerts__open" @click="open(a)">
                <span aria-hidden="true">{{ a.level === 'late' ? '⚠' : '⏱' }}</span> {{ a.text }}
            </button>
            <button type="button" class="c-time-alerts__close" :aria-label="`Quitar aviso: ${a.text}`" @click="dismiss(a)">✕</button>
        </div>
        <p v-if="hidden" class="c-time-alerts__more">+{{ hidden }} {{ hidden === 1 ? 'aviso más' : 'avisos más' }}</p>
    </aside>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useFloorStore } from '@/stores/floor';
import { useSessionStore } from '@/stores/session';
import { useTakeawayStore } from '@/stores/takeaway';
import { timeAlerts } from '@/utils/timeAlerts';

/**
 * Avisos de tiempo discretos (/admin → Ajustes del local → Avisos de tiempo):
 * pedidos cerca de la hora prometida o tarde y mesas abiertas demasiado rato.
 * Se recalculan cada 30 s con lo que ya tiene la pantalla (tiempo real). Un
 * aviso quitado no vuelve, salvo que empeore (de «quedan» a «tarde").
 */
const MAX = 3;
const STORAGE = 'tpv.timeAlerts.dismissed';

const router = useRouter();
const session = useSessionStore();
const floor = useFloorStore();
const takeaway = useTakeawayStore();

const now = ref(Date.now());
const dismissed = ref(read());
let timer = null;

onMounted(() => {
    // Con datos aunque no se haya pasado por Mesas o Pedidos.
    if (session.hasFloor && !floor.loaded) floor.load().catch(() => {});
    if (session.hasTakeaway && !takeaway.loaded) takeaway.load().catch(() => {});
    timer = setInterval(() => (now.value = Date.now()), 30_000);
});
onBeforeUnmount(() => clearInterval(timer));

const alerts = computed(() => timeAlerts({
    tables: floor.tables,
    takeaways: takeaway.orders,
    settings: session.settings?.time_alerts,
    now: now.value,
}).filter((a) => !dismissed.value[a.key]));
const visible = computed(() => alerts.value.slice(0, MAX));
const hidden = computed(() => Math.max(0, alerts.value.length - MAX));

function open(a) {
    dismiss(a);
    router.push(a.path);
}
function dismiss(a) {
    dismissed.value = { ...dismissed.value, [a.key]: Date.now() };
    try {
        localStorage.setItem(STORAGE, JSON.stringify(dismissed.value));
    } catch {
        // Sin almacenamiento: dura mientras la pantalla esté abierta.
    }
}
/** Quitados en las últimas 12 h (los más viejos se olvidan). */
function read() {
    try {
        const all = JSON.parse(localStorage.getItem(STORAGE) || '{}');
        const since = Date.now() - 12 * 3600_000;
        return Object.fromEntries(Object.entries(all).filter(([, at]) => at > since));
    } catch {
        return {};
    }
}
</script>
