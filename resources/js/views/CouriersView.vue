<template>
    <main class="v-couriers">
        <div class="v-board__bar">
            <h1 class="v-board__title">Repartidores</h1>
        </div>

        <p v-if="loaded && !rows.length" class="v-board__empty">No hay repartidores activos.</p>

        <div class="v-couriers__grid">
            <article v-for="row in rows" :key="row.courier.id" class="v-couriers__card">
                <header class="v-couriers__head">
                    <span class="c-option__avatar" :style="{ background: row.courier.color }">{{ row.courier.initials }}</span>
                    <strong class="v-couriers__name">{{ row.courier.name }}</strong>
                    <span class="v-couriers__float">Fondo: <strong>{{ money(row.float) }}</strong></span>
                    <TpvButton variant="secondary" @click="floatFor = row">Entregar fondo</TpvButton>
                </header>

                <ul class="v-couriers__orders">
                    <li v-for="o in row.orders" :key="o.id" class="v-couriers__order">
                        <span class="v-couriers__order-main">
                            <strong>{{ o.ticket?.number }} · {{ o.customer_name }}</strong>
                            <span class="c-takeaway-card__meta">{{ o.address_line }}</span>
                        </span>
                        <span :class="['c-badge', o.delivery_status === 'entregado' ? 'c-badge--ok' : 'c-badge--pending']">
                            {{ o.delivery_status === 'entregado' ? 'Entregado' : 'En reparto' }}
                        </span>
                        <span class="v-couriers__amount">
                            {{ o.pending_amount ? money(o.pending_amount) : 'Pagado' }}
                            <span class="c-takeaway-card__meta">{{ paymentLabel(o) }}<template v-if="o.cash_change_for"> · cambio {{ money(o.cash_change_for) }}</template></span>
                        </span>
                    </li>
                    <li v-if="!row.orders.length" class="v-board__empty">Nada pendiente.</li>
                </ul>

                <footer class="v-couriers__foot">
                    <span>Debe entregar <strong class="v-couriers__expected">{{ money(row.expected_cash) }}</strong> en efectivo</span>
                    <span v-if="row.expected_other" class="c-takeaway-card__meta">+ {{ money(row.expected_other) }} con tarjeta</span>
                    <TpvButton variant="primary" size="lg" :disabled="!row.orders.length && !row.float" @click="startSettle(row)">Liquidar</TpvButton>
                </footer>
            </article>
        </div>

        <!-- Fondo de cambio -->
        <ModalDialog :open="!!floatFor" :title="floatFor ? `Fondo para ${floatFor.courier.name}` : ''" @close="floatFor = null">
            <AmountKeypad v-model="floatAmount" label="Fondo de cambio" :quick="[1000, 2000, 3000, 5000]" />
            <p class="v-config__rule">Sale del cajón; no es una venta. Lo devuelve al liquidar.</p>
            <template #actions>
                <TpvButton variant="ghost" @click="floatFor = null">Cancelar</TpvButton>
                <TpvButton variant="primary" :disabled="!floatAmount" :loading="busy" @click="giveFloat">Entregar {{ money(floatAmount) }}</TpvButton>
            </template>
        </ModalDialog>

        <!-- Liquidar -->
        <BottomSheet :open="!!settling" :title="settling ? `Liquidar · ${settling.courier.name}` : ''" @close="settling = null">
            <template v-if="settling">
                <div v-for="o in settling.orders" :key="o.id" class="v-couriers__settle-row">
                    <div class="v-couriers__settle-head">
                        <strong>{{ o.ticket?.number }} · {{ o.customer_name }}</strong>
                        <span>{{ o.pending_amount ? money(o.pending_amount) : 'Ya pagado' }}</span>
                    </div>
                    <template v-if="o.pending_amount">
                        <div class="c-options">
                            <button
                                v-for="m in methods"
                                :key="m.value"
                                type="button"
                                :class="['c-option', { 'c-option--selected': choice[o.id].method === m.value }]"
                                @click="choice[o.id].method = m.value"
                            >{{ m.label }}</button>
                        </div>
                        <div v-if="choice[o.id].method === 'mixto'" class="v-couriers__mixed">
                            <AmountKeypad v-model="choice[o.id].cash" label="En efectivo" :exact="o.pending_amount" />
                            <p>Con tarjeta: <strong>{{ money(Math.max(0, o.pending_amount - choice[o.id].cash)) }}</strong></p>
                        </div>
                    </template>
                    <TpvButton v-if="o.delivery_status === 'en_reparto'" variant="ghost" @click="failing = o">No entregado…</TpvButton>
                </div>

                <div class="v-couriers__summary">
                    <p>Efectivo cobrado <strong>{{ money(summary.cash) }}</strong></p>
                    <p v-if="settling.float">+ Fondo <strong>{{ money(settling.float) }}</strong></p>
                    <p class="v-couriers__expected">Debe entregar {{ money(summary.expected) }}</p>
                    <AmountKeypad v-model="handed" label="Entrega" :exact="summary.expected" />
                    <p :class="['v-couriers__difference', { 'v-couriers__difference--bad': handed !== summary.expected }]">
                        Diferencia {{ money(handed - summary.expected) }}
                    </p>
                </div>
            </template>
            <template #footer>
                <TpvButton variant="ghost" @click="settling = null">Cancelar</TpvButton>
                <TpvButton variant="primary" block :disabled="!valid" :loading="busy" @click="settle">Liquidar</TpvButton>
            </template>
        </BottomSheet>

        <NotDeliveredSheet :order="failing" @close="failing = null" @done="afterFailed" />
    </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AmountKeypad from '@/components/AmountKeypad.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import NotDeliveredSheet from '@/components/NotDeliveredSheet.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { paymentLabel, useTakeawayStore } from '@/stores/takeaway';
import { money } from '@/utils/format';

const notices = useNoticesStore();
const rows = ref([]);
const loaded = ref(false);
const busy = ref(false);
const floatFor = ref(null);
const floatAmount = ref(0);
const settling = ref(null);
const choice = reactive({});
const handed = ref(0);
const failing = ref(null);

const methods = [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'bizum', label: 'Bizum' },
    { value: 'mixto', label: 'Mixto' },
];

async function load() {
    try {
        rows.value = await pos.courierSettlement();
        loaded.value = true;
        if (settling.value) {
            const fresh = rows.value.find((r) => r.courier.id === settling.value.courier.id);
            fresh ? startSettle(fresh, true) : (settling.value = null);
        }
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
const board = useTakeawayStore();
onMounted(load);
// Tiempo real: salidas, entregas y liquidaciones desde otros dispositivos.
let pendingReload = null;
watch(() => board.version, () => {
    clearTimeout(pendingReload);
    pendingReload = setTimeout(() => !busy.value && load(), 200);
});

async function giveFloat() {
    busy.value = true;
    try {
        await pos.giveFloat(floatFor.value.courier.id, floatAmount.value);
        notices.success(`Fondo de ${money(floatAmount.value)} para ${floatFor.value.courier.name}`);
        floatFor.value = null;
        floatAmount.value = 0;
        await load();
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}

/** Por defecto, cada ticket con el método previsto. */
function startSettle(row, keepChoices = false) {
    for (const o of row.orders) {
        if (!keepChoices || !choice[o.id]) choice[o.id] = { method: o.planned_payment_method === 'tarjeta' ? 'tarjeta' : 'efectivo', cash: 0 };
    }
    settling.value = row;
    if (!keepChoices) handed.value = summary.value.expected;
}

function rowsFor(o) {
    const c = choice[o.id];
    if (!o.pending_amount || !c) return [];
    if (c.method === 'mixto') {
        const cash = Math.min(c.cash, o.pending_amount);
        return [
            cash && { method: 'efectivo', amount: cash },
            o.pending_amount - cash && { method: 'tarjeta', amount: o.pending_amount - cash },
        ].filter(Boolean);
    }
    return [{ method: c.method, amount: o.pending_amount }];
}

const summary = computed(() => {
    if (!settling.value) return { cash: 0, expected: 0 };
    const cash = settling.value.orders.flatMap(rowsFor).filter((r) => r.method === 'efectivo').reduce((a, r) => a + r.amount, 0);
    return { cash, expected: cash + settling.value.float };
});
const valid = computed(() => settling.value && settling.value.orders.every((o) => !o.pending_amount || rowsFor(o).length));

async function settle() {
    busy.value = true;
    try {
        const collected = {};
        for (const o of settling.value.orders) if (o.pending_amount) collected[o.id] = rowsFor(o);
        const result = await pos.settleCourier(settling.value.courier.id, {
            order_ids: settling.value.orders.map((o) => o.id),
            collected,
            handed_cash: handed.value,
        });
        notices.success(result.difference ? `Liquidado con diferencia de ${money(result.difference)}` : 'Liquidado');
        settling.value = null;
        await load();
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}

function afterFailed() {
    failing.value = null;
    load();
}
</script>
