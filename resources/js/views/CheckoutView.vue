<template>
    <main class="v-checkout">
        <header class="v-order__header">
            <TpvButton variant="secondary" @click="back">← Volver</TpvButton>
            <div class="v-order__where">Cobrar · {{ where }}</div>
            <TpvButton v-if="o && !done && !takeaway" variant="secondary" @click="splitOpen = true">Dividir por productos</TpvButton>
        </header>

        <!-- Cobrado -->
        <section v-if="done" class="v-checkout__done">
            <p class="v-checkout__done-title">Cobrado · {{ done.ticket.number }}</p>
            <p v-if="done.change" class="v-checkout__change">Cambio <strong>{{ money(done.change) }}</strong></p>
            <div class="v-checkout__done-actions">
                <TpvButton variant="secondary" size="lg" @click="reprint">Imprimir copia</TpvButton>
                <TpvButton variant="primary" size="lg" @click="router.replace(home)">{{ takeaway ? 'Volver a pedidos' : 'Volver a mesas' }}</TpvButton>
            </div>
        </section>

        <div v-else-if="o" class="v-checkout__body">
            <!-- Resumen -->
            <section class="v-checkout__summary" aria-label="Resumen">
                <div class="v-checkout__lines">
                    <button
                        v-for="line in activeLines"
                        :key="line.id"
                        type="button"
                        class="c-order-line"
                        @click="discountTarget = { line }"
                    >
                        <span class="c-order-line__qty">{{ line.quantity }}</span>
                        <span>
                            <span class="c-order-line__name">{{ line.name }}<template v-if="line.variant_name"> ({{ line.variant_name }})</template></span>
                            <span v-for="c in line.children" :key="c.id" class="c-order-line__detail">· {{ c.name }}</span>
                            <span v-if="line.is_invitation" class="c-order-line__note">Invitación</span>
                            <span v-else-if="line.discount_amount" class="c-order-line__detail">Descuento −{{ money(line.discount_amount) }}</span>
                        </span>
                        <span class="c-order-line__total">{{ money(line.line_total - line.discount_amount) }}</span>
                    </button>
                </div>
                <div class="v-checkout__totals">
                    <div v-if="o.lines_discount || o.discount_amount" class="v-checkout__row"><span>Suma</span><span>{{ money(o.lines_gross) }}</span></div>
                    <div v-if="o.lines_discount" class="v-checkout__row"><span>Descuentos e invitaciones</span><span>−{{ money(o.lines_discount) }}</span></div>
                    <div v-if="o.discount_amount" class="v-checkout__row"><span>Descuento del pedido</span><span>−{{ money(o.discount_amount) }}</span></div>
                    <div v-for="v in o.vat_breakdown" :key="v.rate" class="v-checkout__row v-checkout__row--vat">
                        <span>IVA {{ v.rate / 100 }} % · base {{ money(v.base) }}</span><span>{{ money(v.tax) }}</span>
                    </div>
                    <TpvButton variant="ghost" size="sm" @click="discountTarget = { order: true }">Descuento al pedido</TpvButton>
                </div>
                <div class="v-checkout__total">
                    <span>TOTAL</span>
                    <span>{{ money(o.total) }}</span>
                </div>
            </section>

            <!-- Pago -->
            <section class="v-checkout__pay" aria-label="Pago">
                <div class="v-checkout__parts">
                    <span>Dividir a partes iguales</span>
                    <span class="c-stepper">
                        <TpvButton variant="secondary" icon size="sm" aria-label="Menos partes" :disabled="parts <= 1 || payments.length > 0" @click="parts--">−</TpvButton>
                        <span class="c-stepper__value">{{ parts }}</span>
                        <TpvButton variant="secondary" icon size="sm" aria-label="Más partes" :disabled="payments.length > 0 || parts >= 20" @click="parts++">+</TpvButton>
                    </span>
                </div>

                <ul v-if="payments.length" class="v-checkout__payments">
                    <li v-for="(p, i) in payments" :key="i" class="v-checkout__payment">
                        <span>{{ parts > 1 ? `Parte ${i + 1} · ` : '' }}{{ methodLabel(p.method) }}<template v-if="p.tip"> (+{{ money(p.tip) }} propina)</template></span>
                        <span>{{ money(p.amount) }}<template v-if="p.tendered > p.amount"> · cambio {{ money(p.tendered - p.amount) }}</template></span>
                        <TpvButton variant="ghost" size="sm" :aria-label="`Quitar pago ${i + 1}`" :disabled="busy" @click="payments.splice(i, 1)">✕</TpvButton>
                    </li>
                </ul>

                <div class="v-checkout__due">
                    <span>{{ remaining ? (parts > 1 ? `Parte ${payments.length + 1} de ${parts}` : 'Pendiente') : 'Todo cobrado' }}</span>
                    <strong>{{ money(remaining ? due : 0) }}</strong>
                </div>

                <template v-if="remaining">
                    <div class="v-checkout__methods">
                        <button
                            v-for="m in methods"
                            :key="m.value"
                            type="button"
                            :class="['v-checkout__method', { 'v-checkout__method--active': method === m.value }]"
                            @click="selectMethod(m.value)"
                        >{{ m.label }}</button>
                    </div>

                    <template v-if="method === 'efectivo'">
                        <AmountKeypad v-model="tendered" label="Entregado" :quick="[500, 1000, 2000, 5000]" :exact="due" />
                        <p class="v-checkout__change" :class="{ 'v-checkout__change--short': tendered && tendered < due }">
                            <template v-if="tendered >= due">Cambio <strong>{{ money(tendered - due) }}</strong></template>
                            <template v-else-if="tendered">Se cobran {{ money(tendered) }}, falta {{ money(due - tendered) }}</template>
                        </p>
                    </template>
                    <template v-else-if="method">
                        <AmountKeypad v-model="amount" :label="`Importe ${methodLabel(method).toLowerCase()}`" :exact="due" />
                        <div v-if="method === 'tarjeta'" class="v-checkout__tip">
                            <span>Propina (no entra en el ticket)</span>
                            <span class="c-chips">
                                <button v-for="t in [0, 100, 200, 500]" :key="t" type="button" :class="['c-option', { 'c-option--selected': tip === t }]" @click="tip = t">
                                    {{ t ? money(t) : 'Sin propina' }}
                                </button>
                            </span>
                        </div>
                        <p class="v-checkout__hint">Cobra en el datáfono y confirma.</p>
                    </template>

                    <TpvButton v-if="method" variant="secondary" size="lg" block :disabled="!canAdd" @click="addPayment">
                        Añadir pago {{ money(paymentAmount) }}
                    </TpvButton>
                </template>

                <label class="v-checkout__toggle">
                    <input v-model="printTicket" type="checkbox" /> Imprimir ticket
                </label>
                <label v-if="parts > 1" class="v-checkout__toggle">
                    <input v-model="receipts" type="checkbox" /> Justificante de pago para cada parte
                </label>

                <TpvButton variant="success" size="lg" block :disabled="remaining !== 0 || busy" :loading="busy" @click="charge">
                    Cobrar {{ money(o.total) }}
                </TpvButton>
            </section>
        </div>

        <DiscountSheet :target="discountTarget" @close="discountTarget = null" />
        <OpenCashDialog :open="openCash" @close="openCash = false" @opened="openCash = false; charge()" />
        <SplitSheet :open="splitOpen" @close="splitOpen = false" @split="onSplit" />
    </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AmountKeypad from '@/components/AmountKeypad.vue';
import DiscountSheet from '@/components/DiscountSheet.vue';
import OpenCashDialog from '@/components/OpenCashDialog.vue';
import SplitSheet from '@/components/SplitSheet.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorCode, errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { useOrderStore } from '@/stores/order';
import { useSessionStore } from '@/stores/session';
import { money, uid } from '@/utils/format';

const route = useRoute();
// Para llevar: se cobra desde "Pedidos" y se vuelve allí.
const takeaway = computed(() => route.name === 'takeaway-checkout');
const home = computed(() => (takeaway.value ? '/pedidos' : '/mesas'));
const router = useRouter();
const order = useOrderStore();
const notices = useNoticesStore();
const session = useSessionStore();
const authorize = useAuthorizeStore();

const methods = [
    { value: 'efectivo', label: 'Efectivo' },
    { value: 'tarjeta', label: 'Tarjeta' },
    { value: 'bizum', label: 'Bizum' },
    { value: 'otro', label: 'Otro' },
];
const methodLabel = (v) => methods.find((m) => m.value === v)?.label ?? v;

const payments = ref([]);
const parts = ref(1);
const method = ref(null);
const tendered = ref(0);
const amount = ref(0);
const tip = ref(0);
const printTicket = ref(session.settings?.print_ticket_on_checkout ?? true);
const receipts = ref(false);
const busy = ref(false);
const done = ref(null);
const openCash = ref(false);
const discountTarget = ref(null);
const splitOpen = ref(false);
// Un identificador por intento de cobro: si la petición llega dos veces, un solo ticket.
let idempotencyKey = uid();

const o = computed(() => (order.order?.id === Number(route.params.orderId) ? order.order : null));
const activeLines = computed(() => (o.value?.lines ?? []).filter((l) => !l.voided_at));
const where = computed(() => (o.value?.table ? `Mesa ${o.value.table.name}` : 'Venta rápida'));
const paid = computed(() => payments.value.reduce((t, p) => t + p.amount, 0));
const remaining = computed(() => (o.value?.total ?? 0) - paid.value);

/** Importe de la parte actual: total / N, el resto de céntimos a la última. */
const due = computed(() => {
    if (parts.value <= 1) return remaining.value;
    const base = Math.floor(o.value.total / parts.value);
    const isLast = payments.value.length >= parts.value - 1;
    return Math.min(remaining.value, isLast ? remaining.value : base);
});
const paymentAmount = computed(() => (method.value === 'efectivo' ? Math.min(tendered.value, due.value) : Math.min(amount.value, remaining.value)));
const canAdd = computed(() => paymentAmount.value > 0);

onMounted(async () => {
    if (!o.value) {
        try {
            await order.load(Number(route.params.orderId));
        } catch (e) {
            notices.error(errorMessage(e));
            router.replace(home.value);
        }
    }
});
// Si cambia el pedido (descuentos) se reinician los pagos pendientes.
watch(() => o.value?.total, () => {
    payments.value = [];
    resetEntry();
});

function selectMethod(value) {
    method.value = value;
    tendered.value = 0;
    amount.value = value === 'efectivo' ? 0 : due.value; // tarjeta/Bizum: se propone lo pendiente
    tip.value = 0;
}
function resetEntry() {
    method.value = null;
    tendered.value = 0;
    amount.value = 0;
    tip.value = 0;
}
function addPayment() {
    const p = { method: method.value, amount: paymentAmount.value };
    if (method.value === 'efectivo') p.tendered = Math.max(tendered.value, paymentAmount.value);
    if (method.value === 'tarjeta' && tip.value) p.tip = tip.value;
    payments.value.push(p);
    resetEntry();
}

async function charge() {
    busy.value = true;
    try {
        done.value = await pos.checkout(o.value.id, {
            idempotency_key: idempotencyKey,
            payments: payments.value,
            print: printTicket.value,
            payment_receipts: receipts.value,
        });
        idempotencyKey = uid();
        order.clear();
    } catch (e) {
        if (errorCode(e) === 'cash_session_required') openCash.value = true;
        else notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}

async function reprint() {
    try {
        await authorize.withAuthorization('reimprimir', (token) => pos.reprint(done.value.ticket.id, { authorization_token: token }));
        notices.success('Copia enviada a la impresora');
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    }
}
function onSplit(newOrder) {
    splitOpen.value = false;
    order.order = newOrder;
    router.replace(`/mesas/pedido/${newOrder.id}/cobrar`);
    notices.toast('Cuenta separada: cobra esta parte y vuelve a la mesa para el resto.');
}
function back() {
    if (!o.value) return router.push(home.value);
    router.push(takeaway.value ? `/pedidos/${o.value.id}` : `/mesas/pedido/${o.value.id}`);
}
</script>
