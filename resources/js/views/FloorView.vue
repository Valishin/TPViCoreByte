<template>
    <main class="v-floor">
        <div class="v-floor__bar">
        <nav v-if="floor.rooms.length > 1" class="c-tabs" aria-label="Salas">
            <button
                v-for="room in floor.rooms"
                :key="room.id"
                type="button"
                :class="['c-tabs__tab', { 'c-tabs__tab--active': room.id === floor.currentRoom?.id }]"
                @click="floor.roomId = room.id"
            >
                {{ room.name }} · {{ busyCount(room) }}/{{ room.tables.length }}
            </button>
        </nav>
        <TpvButton variant="primary" :loading="quick" @click="quickSale">Venta rápida</TpvButton>
        </div>

        <!-- Pedidos abiertos que el plano no enseña (venta rápida sin cobrar, sala desactivada…): nunca se pierden -->
        <section v-if="floor.unplaced.length" class="v-floor__unplaced" aria-label="Otros pedidos abiertos">
            <p class="v-floor__unplaced-title">Otros pedidos abiertos ({{ floor.unplaced.length }})</p>
            <div class="v-floor__unplaced-list">
                <button v-for="o in floor.unplaced" :key="o.id" type="button" class="c-option v-floor__unplaced-item" @click="openOrder(o.id)">
                    <span>
                        <strong>{{ o.label }}</strong>
                        <span class="c-option__extra">{{ o.reason }} · {{ o.summary || 'sin productos' }} · {{ duration(minutesSince(o.opened_at, now)) }}<template v-if="o.waiter"> · {{ o.waiter.name }}</template></span>
                    </span>
                    <strong>{{ money(o.total) }}</strong>
                </button>
            </div>
        </section>

        <p v-if="floor.loaded && !floor.rooms.length" class="v-floor__empty">No hay salas. Créalas en el backoffice (/admin → Sala).</p>

        <FloorPlan v-if="floor.currentRoom" :tables="floor.currentRoom.tables" :all-tables="floor.tables" :now="now" @select="select" />

        <div class="v-floor__legend">
            <span><i class="v-floor__swatch" style="background: var(--c-table-free)"></i>Libre</span>
            <span><i class="v-floor__swatch" style="background: var(--c-table-occupied)"></i>Ocupada</span>
            <span><i class="v-floor__swatch" style="background: var(--c-table-bill)"></i>Cuenta pedida</span>
            <span><i class="v-floor__swatch v-floor__swatch--group"></i>Unidas: «3+4»</span>
        </div>

        <!-- Mesa con varias cuentas: elegir cuál abrir -->
        <ModalDialog :open="!!choosing" :title="choosing ? `Mesa ${choosing.name}: ${choosing.orders.length} cuentas` : ''" @close="choosing = null">
            <div class="v-floor__accounts">
                <button
                    v-for="(acc, i) in choosing?.orders ?? []"
                    :key="acc.id"
                    type="button"
                    class="c-option v-floor__account"
                    @click="openOrder(acc.id)"
                >
                    <span>
                        <strong>Cuenta {{ i + 1 }}</strong>
                        <span class="c-option__extra">{{ acc.summary || 'Sin líneas' }}</span>
                    </span>
                    <strong>{{ money(acc.total) }}</strong>
                </button>
            </div>
            <template #actions>
                <TpvButton variant="ghost" @click="choosing = null">Cancelar</TpvButton>
            </template>
        </ModalDialog>

        <GuestsDialog :open="!!opening" :title="opening ? `Mesa ${opening.name}: ¿cuántos son?` : ''" @confirm="open" @close="opening = null" />
    </main>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import FloorPlan from '@/components/FloorPlan.vue';
import GuestsDialog from '@/components/GuestsDialog.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { duration, minutesSince, money } from '@/utils/format';
import { useFloorStore } from '@/stores/floor';
import { useNoticesStore } from '@/stores/notices';

const router = useRouter();
const floor = useFloorStore();
const notices = useNoticesStore();

const opening = ref(null);
const choosing = ref(null);

function openOrder(id) {
    choosing.value = null;
    router.push(`/mesas/pedido/${id}`);
}
const quick = ref(false);

/** Venta rápida de barra (sin mesa): comanda y cobro. */
async function quickSale() {
    quick.value = true;
    try {
        const order = await pos.quickSale();
        router.push(`/mesas/pedido/${order.id}`);
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        quick.value = false;
    }
}
const now = ref(Date.now());
let ticker;

onMounted(() => {
    floor.load().catch((e) => notices.error(errorMessage(e)));
    ticker = setInterval(() => (now.value = Date.now()), 30000);
});
onUnmounted(() => clearInterval(ticker));

const busyCount = (room) => room.tables.filter((t) => t.status !== 'libre').length;

function select(table) {
    if (table.status === 'fuera_servicio') return;
    if ((table.orders?.length ?? 0) > 1) choosing.value = table;
    else if (table.order) router.push(`/mesas/pedido/${table.order.id}`);
    else opening.value = table;
}

async function open(guests) {
    const table = opening.value;
    opening.value = null;
    try {
        const order = await pos.openTable(table.id, guests);
        router.push(`/mesas/pedido/${order.id}`);
    } catch (e) {
        notices.error(errorMessage(e));
        floor.load();
    }
}
</script>
