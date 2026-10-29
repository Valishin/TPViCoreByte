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

        <p v-if="floor.loaded && !floor.rooms.length" class="v-floor__empty">No hay salas. Créalas en el backoffice (/admin → Sala).</p>

        <!-- Caja: plano según pos_x / pos_y -->
        <section v-if="ui.isPos && floor.currentRoom" class="v-floor__plan" :style="{ '--cols': cols }">
            <TableCard
                v-for="t in floor.currentRoom.tables"
                :key="t.id"
                :table="t"
                :now="now"
                :style="planStyle(t)"
                @select="select"
            />
        </section>

        <!-- Móvil: rejilla compacta -->
        <section v-else-if="floor.currentRoom" class="v-floor__grid">
            <TableCard v-for="t in sortedTables" :key="t.id" :table="t" :now="now" @select="select" />
        </section>

        <div class="v-floor__legend">
            <span><i class="v-floor__swatch" style="background: var(--c-table-free)"></i>Libre</span>
            <span><i class="v-floor__swatch" style="background: var(--c-table-occupied)"></i>Ocupada</span>
            <span><i class="v-floor__swatch" style="background: var(--c-table-bill)"></i>Cuenta pedida</span>
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
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import TableCard from '@/components/TableCard.vue';
import GuestsDialog from '@/components/GuestsDialog.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { money } from '@/utils/format';
import { useFloorStore } from '@/stores/floor';
import { useNoticesStore } from '@/stores/notices';
import { useUiStore } from '@/stores/ui';

const router = useRouter();
const floor = useFloorStore();
const notices = useNoticesStore();
const ui = useUiStore();

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
const sortedTables = computed(() =>
    [...(floor.currentRoom?.tables ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'es', { numeric: true })),
);

// Cuadrícula del plano: cada mesa ocupa 2×2 celdas por unidad de tamaño.
const cols = computed(() => Math.max(6, ...(floor.currentRoom?.tables ?? []).map((t) => t.x + t.w * 2 + 1)));
function planStyle(t) {
    return {
        gridColumn: `${t.x + 1} / span ${t.w * 2}`,
        gridRow: `${t.y + 1} / span ${t.h * 2}`,
    };
}

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
