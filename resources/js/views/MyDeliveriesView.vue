<template>
    <main class="v-deliveries">
        <h1 class="v-board__title">Mis repartos</h1>
        <p v-if="loaded && !pending.length" class="v-board__empty">No tienes repartos pendientes.</p>

        <article v-for="d in pending" :key="d.id" class="v-deliveries__card">
            <p class="v-deliveries__street">{{ d.address?.street }} {{ d.address?.number }}</p>
            <p v-if="d.address?.floor_door" class="v-deliveries__floor">{{ d.address.floor_door }}</p>
            <p class="v-deliveries__place">{{ d.address?.postal_code }} {{ d.address?.city }} · {{ d.zone?.name }}</p>
            <p v-if="d.address?.instructions" class="c-alert c-alert--warning v-deliveries__note"><span class="c-alert__text">{{ d.address.instructions }}</span></p>
            <p v-if="d.customer_notes" class="c-alert c-alert--warning v-deliveries__note"><span class="c-alert__text">⚠ {{ d.customer_notes }}</span></p>
            <p class="v-deliveries__who">{{ d.customer_name }} · {{ d.customer_phone }}</p>

            <div class="v-deliveries__links">
                <a v-if="d.maps_url" class="c-button c-button--secondary c-button--lg" :href="d.maps_url" target="_blank" rel="noopener">📍 Abrir en Maps</a>
                <a v-if="d.phone_url" class="c-button c-button--secondary c-button--lg" :href="d.phone_url">📞 Llamar</a>
            </div>

            <div class="v-deliveries__money">
                <template v-if="d.pending_amount">
                    <span>Cobrar <strong class="v-deliveries__amount">{{ money(d.pending_amount) }}</strong> · {{ paymentLabel(d) }}</span>
                    <span v-if="d.cash_change_for">Cambio para {{ money(d.cash_change_for) }} → devolver <strong>{{ money(Math.max(0, d.cash_change_for - d.pending_amount)) }}</strong></span>
                </template>
                <strong v-else class="v-deliveries__amount">Ya pagado · no cobrar</strong>
                <span class="c-takeaway-card__meta">{{ promisedLabel(d) }} · ticket {{ d.ticket?.number }}</span>
            </div>

            <div class="v-deliveries__actions">
                <TpvButton variant="success" size="lg" block @click="startDelivered(d)">Entregado</TpvButton>
                <TpvButton variant="ghost" @click="failing = d">No entregado…</TpvButton>
            </div>
        </article>

        <section v-if="delivered.length" class="v-deliveries__done">
            <h2 class="v-board__column-title">Entregados · se liquidan en caja</h2>
            <p v-for="d in delivered" :key="d.id" class="v-deliveries__done-row">
                {{ d.customer_name }} · {{ d.pending_amount ? money(d.pending_amount) : 'pagado' }}
                <span class="c-takeaway-card__meta">{{ reported(d) }}</span>
            </p>
        </section>

        <!-- Cobro real al entregar -->
        <BottomSheet :open="!!delivering" :title="delivering ? `Entregado · ${delivering.customer_name}` : ''" @close="delivering = null">
            <template v-if="delivering">
                <template v-if="delivering.pending_amount">
                    <p class="v-config__question">¿Cómo ha pagado? {{ money(delivering.pending_amount) }}</p>
                    <div class="c-options c-options--large">
                        <button v-for="m in methods" :key="m.value" type="button" :class="['c-option', { 'c-option--selected': method === m.value }]" @click="method = m.value">{{ m.label }}</button>
                    </div>
                    <div v-if="method === 'mixto'">
                        <AmountKeypad v-model="cashPart" label="En efectivo" :exact="delivering.pending_amount" />
                        <p>Con tarjeta: <strong>{{ money(Math.max(0, delivering.pending_amount - cashPart)) }}</strong></p>
                    </div>
                </template>
                <p v-else class="v-config__rule">Ya estaba pagado: solo se marca como entregado.</p>
            </template>
            <template #footer>
                <TpvButton variant="ghost" @click="delivering = null">Volver</TpvButton>
                <TpvButton variant="success" block :disabled="!validPayment" :loading="busy" @click="confirmDelivered">Confirmar entrega</TpvButton>
            </template>
        </BottomSheet>

        <NotDeliveredSheet :order="failing" courier @close="failing = null" @done="afterFailed" />
    </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AmountKeypad from '@/components/AmountKeypad.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import NotDeliveredSheet from '@/components/NotDeliveredSheet.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { PAYMENT_LABELS, paymentLabel, promisedLabel, useTakeawayStore } from '@/stores/takeaway';
import { money } from '@/utils/format';

const notices = useNoticesStore();
const board = useTakeawayStore();
const items = ref([]);
const loaded = ref(false);
const busy = ref(false);
const delivering = ref(null);
const failing = ref(null);
const method = ref('efectivo');
const cashPart = ref(0);

const methods = [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'mixto', label: 'Mixto' },
];

const pending = computed(() => items.value.filter((d) => d.delivery_status === 'en_reparto'));
const delivered = computed(() => items.value.filter((d) => d.delivery_status === 'entregado'));

async function load() {
    try {
        items.value = await pos.myDeliveries();
        loaded.value = true;
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
onMounted(load);
watch(() => board.version, () => !busy.value && load()); // tiempo real: nuevas salidas o liquidación en caja

function startDelivered(d) {
    delivering.value = d;
    method.value = d.planned_payment_method === 'tarjeta' ? 'tarjeta' : 'efectivo';
    cashPart.value = 0;
}

const payments = computed(() => {
    const d = delivering.value;
    if (!d?.pending_amount) return [];
    if (method.value !== 'mixto') return [{ method: method.value, amount: d.pending_amount }];
    const cash = Math.min(cashPart.value, d.pending_amount);
    return [{ method: 'efectivo', amount: cash }, { method: 'tarjeta', amount: d.pending_amount - cash }].filter((p) => p.amount > 0);
});
const validPayment = computed(() => !delivering.value?.pending_amount || payments.value.length > 0);

async function confirmDelivered() {
    busy.value = true;
    try {
        await pos.myDelivered(delivering.value.id, payments.value);
        notices.success('Entregado');
        delivering.value = null;
        await load();
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}

function reported(d) {
    return (d.reported_payments ?? []).map((p) => `${PAYMENT_LABELS[p.method]} ${money(p.amount)}`).join(' + ');
}

function afterFailed() {
    failing.value = null;
    load();
}
</script>
