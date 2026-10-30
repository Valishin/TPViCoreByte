<template>
    <main class="v-cash">
        <header class="v-order__header">
            <TpvButton variant="secondary" @click="router.push(session.home)">{{ session.hasFloor ? '← Mesas' : '← Pedidos' }}</TpvButton>
            <div class="v-order__where">Caja</div>
        </header>

        <section v-if="loaded && !cash" class="v-cash__closed">
            <p class="v-cash__title">No hay ninguna caja abierta</p>
            <p class="v-config__rule">Para cobrar hay que abrir la caja con el fondo inicial.</p>
            <TpvButton variant="primary" size="lg" @click="openOpen = true">Abrir caja</TpvButton>
        </section>

        <template v-else-if="cash">
            <section class="v-cash__stats">
                <div class="v-cash__stat"><span>Ventas</span><strong>{{ money(report.sales_total) }}</strong><small>{{ report.tickets.count }} tickets<template v-if="report.invoices?.count"> · {{ report.invoices.count }} facturas</template> · {{ report.rectifications.count }} rectif.</small></div>
                <div v-if="report.exchanges?.count" class="v-cash__stat"><span>Canjes por factura</span><strong>{{ report.exchanges.count }}</strong><small>{{ money(report.exchanges.total) }} · no suman (el ticket ya se contó)</small></div>
                <div v-for="m in report.by_method" :key="m.method" class="v-cash__stat">
                    <span>{{ m.label }}{{ m.refunds_count ? ' (neto)' : '' }}</span>
                    <strong>{{ money(m.amount) }}</strong>
                    <small>{{ m.count }} cobros {{ money(m.charges) }}<template v-if="m.refunds_count"> · devoluciones {{ money(m.refunds) }}</template></small>
                </div>
                <div v-if="report.card_terminal_total" class="v-cash__stat"><span>Total datáfono</span><strong>{{ money(report.card_terminal_total) }}</strong><small>tarjeta neto + propinas</small></div>
                <div class="v-cash__stat"><span>Efectivo esperado</span><strong>{{ money(report.cash.expected) }}</strong><small>fondo {{ money(report.cash.opening_float) }}</small></div>
                <div v-if="report.tips.total" class="v-cash__stat"><span>Propinas</span><strong>{{ money(report.tips.total) }}</strong><small>fuera de ventas</small></div>
            </section>

            <section class="v-cash__actions">
                <TpvButton variant="secondary" :loading="busy === 'x'" @click="printX">Imprimir X</TpvButton>
                <TpvButton variant="secondary" :loading="busy === 'drawer'" @click="drawer">Abrir cajón</TpvButton>
                <TpvButton variant="danger" @click="closeOpen = true">Cerrar caja (Z)</TpvButton>
            </section>

            <section class="v-cash__tickets">
                <div class="v-cash__tickets-head">
                    <h2 class="v-cash__subtitle">{{ searched ? `Resultado de «${searched}»` : 'Tickets de esta caja' }}</h2>
                    <form class="v-cash__search" @submit.prevent="search">
                        <TextField v-model="q" placeholder="Nº de ticket (T-000123)" autocomplete="off" />
                        <TpvButton type="submit" variant="secondary" :disabled="!q.trim()">Buscar</TpvButton>
                        <TpvButton v-if="searched" variant="ghost" @click="q = ''; searched = ''; load()">✕</TpvButton>
                    </form>
                </div>
                <p v-if="!tickets.length" class="v-config__rule">{{ searched ? 'No hay ningún ticket con ese número.' : 'Aún no hay tickets.' }}</p>
                <div v-for="t in tickets" :key="t.id" :class="['v-cash__ticket', { 'v-cash__ticket--void': t.status !== 'emitido', 'v-cash__ticket--rect': t.type.startsWith('rectificativa') }]">
                    <span class="v-cash__ticket-main">
                        <strong>{{ t.number }}</strong><template v-if="t.type !== 'simplificada'"> · {{ t.type_label }}</template> · {{ time(t.issued_at) }} · {{ t.table ? `Mesa ${t.table}` : typeLabel(t.order_type) }}
                        <small>
                            <template v-if="t.recipient">{{ t.recipient.name }} ({{ t.recipient.tax_id }}) · </template>{{ t.issued_by }} · {{ t.payment_methods.join(', ') }}<template v-if="t.rectifies"> · rectifica {{ t.rectifies }}</template><template v-if="t.replaces"> · sustituye a {{ t.replaces }}</template>
                            <template v-if="t.status === 'rectificado'"> · RECTIFICADO</template><template v-if="t.exchanged_by"> · CANJEADO por {{ t.exchanged_by }}</template>
                            <template v-if="t.email"> · email {{ emailStatus(t.email) }}</template>
                        </small>
                    </span>
                    <strong>{{ money(t.total) }}</strong>
                    <span class="v-cash__ticket-actions">
                        <TpvButton variant="secondary" size="sm" @click="reprint(t)">Copia</TpvButton>
                        <TpvButton v-if="t.can_exchange" variant="primary" size="sm" @click="exchanging = t">Hacer factura</TpvButton>
                        <template v-if="isInvoice(t)">
                            <TpvButton variant="secondary" size="sm" @click="openPdf(t)">PDF</TpvButton>
                            <TpvButton variant="secondary" size="sm" :loading="busy === `mail-${t.id}`" @click="sendEmail(t)">Email</TpvButton>
                        </template>
                        <TpvButton v-if="t.status !== 'canjeado' && (t.type === 'simplificada' || isInvoice(t))" variant="danger" size="sm" @click="voiding = t">Anular</TpvButton>
                    </span>
                </div>
            </section>
        </template>

        <OpenCashDialog :open="openOpen" @close="openOpen = false" @opened="openOpen = false; load()" />
        <VoidTicketSheet :ticket="voiding" @close="voiding = null" @done="voiding = null; load()" />
        <InvoiceRecipientSheet :open="!!exchanging" :title="exchanging ? `Factura del ticket ${exchanging.number}` : ''" @close="exchanging = null" @select="(c) => exchange(c, false)" />

        <!-- Canje de un ticket de otro año (aviso, sin límite de plazo) -->
        <ModalDialog :open="!!otherYear" title="Ticket de otro año" @close="otherYear = null">
            <p class="c-modal__text">{{ otherYear?.message }}</p>
            <template #actions>
                <TpvButton variant="ghost" @click="otherYear = null">Cancelar</TpvButton>
                <TpvButton variant="primary" :loading="busy === 'exchange'" @click="exchange(otherYear.customer, true)">Hacer la factura igualmente</TpvButton>
            </template>
        </ModalDialog>

        <!-- Cierre Z -->
        <ModalDialog :open="closeOpen" title="Cierre de caja (Z)" @close="closeOpen = false">
            <template v-if="!closed">
                <p class="c-modal__text">Efectivo esperado: <strong>{{ money(report?.cash.expected) }}</strong>. Cuenta el cajón:</p>
                <div class="c-tabs" style="margin-bottom: 1rem">
                    <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': countMode === 'amount' }]" @click="countMode = 'amount'">Importe</button>
                    <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': countMode === 'count' }]" @click="countMode = 'count'">Billetes y monedas</button>
                </div>
                <AmountKeypad v-if="countMode === 'amount'" v-model="counted" label="Contado" :exact="report?.cash.expected" />
                <DenominationCounter v-else @change="({ breakdown: b, total }) => { breakdown = b; counted = total; }" />
                <p class="v-checkout__change" :class="{ 'v-checkout__change--short': difference < 0 }">Diferencia <strong>{{ money(difference) }}</strong></p>
                <TextField v-model="notes" label="Notas" :maxlength="500" />
                <div v-if="openOrders.length" class="c-alert c-alert--warning" style="margin-top: 1rem">
                    <span class="c-alert__text">Hay pedidos abiertos o sin cobrar: {{ openOrders.join(', ') }}. Si cierras, quedarán pendientes para la próxima caja.</span>
                </div>
            </template>
            <template v-else>
                <p class="v-cash__title">Z nº {{ closed.z_number }}</p>
                <p class="c-modal__text">Ventas {{ money(closed.report.sales_total) }} · diferencia {{ money(closed.report.cash.difference) }}. El informe se ha enviado a la impresora.</p>
            </template>
            <template #actions>
                <template v-if="!closed">
                    <TpvButton variant="ghost" @click="closeOpen = false">Cancelar</TpvButton>
                    <TpvButton variant="danger" :loading="busy === 'z'" @click="closeCash(openOrders.length > 0)">
                        {{ openOrders.length ? 'Cerrar igualmente' : 'Cerrar caja' }}
                    </TpvButton>
                </template>
                <TpvButton v-else variant="primary" @click="closeOpen = false; closed = null; load()">Hecho</TpvButton>
            </template>
        </ModalDialog>
    </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AmountKeypad from '@/components/AmountKeypad.vue';
import DenominationCounter from '@/components/DenominationCounter.vue';
import InvoiceRecipientSheet from '@/components/InvoiceRecipientSheet.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import OpenCashDialog from '@/components/OpenCashDialog.vue';
import TextField from '@/components/TextField.vue';
import TpvButton from '@/components/TpvButton.vue';
import VoidTicketSheet from '@/components/VoidTicketSheet.vue';
import pos, { errorCode, errorMessage } from '@/services/pos';
import { useSessionStore } from '@/stores/session';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { emailStatus, money, time } from '@/utils/format';

const router = useRouter();
const session = useSessionStore();
const notices = useNoticesStore();
const authorize = useAuthorizeStore();

const cash = ref(null);
const tickets = ref([]);
const loaded = ref(false);
const busy = ref(null);
const openOpen = ref(false);
const closeOpen = ref(false);
const voiding = ref(null);
const counted = ref(0);
const countMode = ref('amount');
const breakdown = ref(null);
const notes = ref('');
const openOrders = ref([]);
const closed = ref(null);
const q = ref('');
const searched = ref('');
const exchanging = ref(null);
const otherYear = ref(null);
const isInvoice = (t) => t.type === 'completa' || t.type === 'completa_canje';

const report = computed(() => cash.value?.report);
const difference = computed(() => counted.value - (report.value?.cash.expected ?? 0));
const types = { barra: 'Barra', para_llevar: 'Para llevar', domicilio: 'Domicilio', mesa: 'Mesa' };
const typeLabel = (t) => types[t] ?? 'Barra';

async function load() {
    try {
        [cash.value, tickets.value] = await Promise.all([pos.cashSession(), pos.tickets()]);
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        loaded.value = true;
    }
}
onMounted(load);
watch(closeOpen, (open) => {
    if (open) {
        counted.value = 0;
        breakdown.value = null;
        notes.value = '';
        openOrders.value = [];
    }
});

async function guarded(key, fn, ok) {
    busy.value = key;
    try {
        await fn();
        ok?.();
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = null;
    }
}
function printX() {
    guarded('x', () => pos.printX(), () => notices.success('Informe X enviado a la impresora'));
}
function drawer() {
    guarded('drawer', () => authorize.withAuthorization('abrir_cajon', (token) => pos.openDrawer({ authorization_token: token, reason: 'Sin venta' })), () => notices.success('Cajón abierto'));
}
function reprint(t) {
    guarded('reprint', () => authorize.withAuthorization('reimprimir', (token) => pos.reprint(t.id, { authorization_token: token })), () => notices.success(`Copia de ${t.number} enviada`));
}
async function search() {
    const term = q.value.trim();
    if (!term) return;
    try {
        tickets.value = await pos.searchTickets(term);
        searched.value = term;
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
/** Canje del ticket por factura completa (art. 15.6 RD 1619/2012). */
async function exchange(customer, confirmOtherYear) {
    const ticket = exchanging.value ?? otherYear.value?.ticket;
    busy.value = 'exchange';
    try {
        const invoice = await pos.exchange(ticket.id, { customer_id: customer.id, confirm_other_year: confirmOtherYear });
        exchanging.value = null;
        otherYear.value = null;
        notices.success(`Factura ${invoice.number} emitida (sustituye a ${ticket.number})`);
        if (searched.value) await search();
        else await load();
    } catch (e) {
        if (errorCode(e) === 'exchange_other_year') {
            otherYear.value = { ticket, customer, message: errorMessage(e) };
            exchanging.value = null;
        } else notices.error(errorMessage(e));
    } finally {
        busy.value = null;
    }
}
function openPdf(t) {
    window.open(pos.invoicePdfUrl(t.id), '_blank');
}
function sendEmail(t) {
    guarded(`mail-${t.id}`, async () => {
        const e = await pos.emailInvoice(t.id);
        t.email = { to: e.to, status: e.status };
    }, () => notices.success(`Factura ${t.number} en cola para ${t.email.to}`));
}
async function closeCash(force) {
    busy.value = 'z';
    try {
        closed.value = await authorize.withAuthorization('cerrar_caja', (token) => pos.closeCash({
            counted_cash: counted.value,
            breakdown: countMode.value === 'count' ? breakdown.value : null,
            notes: notes.value || null,
            force,
            authorization_token: token,
        }));
        cash.value = null;
    } catch (e) {
        if (errorCode(e) === 'open_orders') openOrders.value = e.response.data.orders;
        else if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = null;
    }
}
</script>
