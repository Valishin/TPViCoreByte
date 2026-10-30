<template>
    <main class="v-board">
        <div class="v-board__bar">
            <h1 class="v-board__title">Pedidos</h1>
            <span class="v-board__legend">
                <span class="c-takeaway-card__left c-takeaway-card__left--soon">⏱ menos de 10 min</span>
                <span class="c-takeaway-card__left c-takeaway-card__left--late">⚠ tarde</span>
            </span>
            <TpvButton variant="primary" size="lg" @click="router.push('/pedidos/nuevo')">+ Nuevo pedido</TpvButton>
        </div>

        <!-- Móvil: una pestaña por estado -->
        <nav v-if="!ui.isPos" class="v-board__tabs" aria-label="Estados">
            <button
                v-for="c in tabs"
                :key="c.status"
                type="button"
                :class="['v-board__tab', { 'v-board__tab--active': tab === c.status }]"
                @click="tab = c.status"
            >{{ c.label }} <span class="v-board__count">{{ c.count }}</span></button>
        </nav>

        <div class="v-board__columns">
            <section
                v-for="c in visibleColumns"
                :key="c.status"
                class="v-board__column"
                :aria-label="c.label"
            >
                <h2 v-if="ui.isPos" class="v-board__column-title">{{ c.label }} <span class="v-board__count">{{ store.byStatus(c.status).length }}</span></h2>
                <TakeawayCard
                    v-for="o in store.byStatus(c.status)"
                    :key="o.id"
                    :order="o"
                    :now="store.now"
                    :busy="busy[o.id] ?? null"
                    @open="open"
                    @action="action"
                />
                <p v-if="!store.byStatus(c.status).length" class="v-board__empty">—</p>
            </section>
        </div>

        <!-- Entregados hoy: plegado -->
        <section v-if="ui.isPos || tab === 'hoy'" class="v-board__done">
            <button v-if="ui.isPos" type="button" class="v-board__done-toggle" :aria-expanded="showDone" @click="showDone = !showDone">
                {{ showDone ? '▾' : '▸' }} Entregados hoy ({{ store.finished.length }})
            </button>
            <div v-if="showDone || !ui.isPos" class="v-board__done-list">
                <TakeawayCard v-for="o in store.finished" :key="o.id" :order="o" :now="store.now" @open="open" @action="action" />
                <p v-if="!store.finished.length" class="v-board__empty">Todavía ninguno.</p>
            </div>
        </section>

        <DispatchSheet :order="dispatching" :busy="!!dispatching && busy[dispatching.id] === 'dispatch'" :invoice-hint="invoiceHint" @confirm="dispatchTo" @close="dispatching = null" />
        <InvoiceRecipientSheet :open="!!handingOver" :hint="invoiceHint" @close="handingOver = null" @select="deliverWithInvoice" />
    </main>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import DispatchSheet from '@/components/DispatchSheet.vue';
import InvoiceRecipientSheet from '@/components/InvoiceRecipientSheet.vue';
import TakeawayCard from '@/components/TakeawayCard.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorCode, errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { COLUMNS, useTakeawayStore } from '@/stores/takeaway';
import { useUiStore } from '@/stores/ui';

const router = useRouter();
const store = useTakeawayStore();
const notices = useNoticesStore();
const ui = useUiStore();

const tab = ref('pendiente');
const showDone = ref(false);
const busy = reactive({});
const dispatching = ref(null);
// Más de 3.000 € (art. 4.2.c RD 1619/2012): el servidor pide factura con datos.
const invoiceHint = ref('');
const handingOver = ref(null);

const tabs = computed(() => [
    ...COLUMNS.map((c) => ({ ...c, count: store.byStatus(c.status).length })),
    { status: 'hoy', label: 'Entregados', count: store.finished.length },
]);
const visibleColumns = computed(() => (ui.isPos ? COLUMNS : COLUMNS.filter((c) => c.status === tab.value)));

let timer = null;
onMounted(() => {
    store.tick();
    store.load().catch((e) => notices.error(errorMessage(e)));
    timer = setInterval(() => store.tick(), 30000);
});
onBeforeUnmount(() => clearInterval(timer));

function open(order) {
    if (order.status === 'abierto') router.push(`/pedidos/${order.id}`);
}

async function run(order, key, fn, ok) {
    busy[order.id] = key;
    try {
        const result = await fn();
        if (result?.takeaway) store.upsert(result.takeaway);
        ok?.(result);
    } catch (e) {
        if (errorCode(e) === 'invoice_required') invoiceHint.value = errorMessage(e);
        if (errorCode(e) === 'invoice_required' && key === 'deliver') handingOver.value = order;
        else if (errorCode(e) !== 'invoice_required') notices.error(errorMessage(e));
    } finally {
        delete busy[order.id];
    }
}

function action(key, order) {
    switch (key) {
        case 'open':
            return open(order);
        case 'ready':
            return run(order, key, () => pos.takeawayStatus(order.id, 'listo'));
        case 'deliver':
            return run(order, key, () => pos.takeawayStatus(order.id, 'entregado'), () => notices.success('Entregado'));
        case 'deliver_invoice':
            // "Factura con datos" al entregar un prepagado, como en el cobro y en "Dar salida".
            invoiceHint.value = '';
            handingOver.value = order;
            return;
        case 'checkout':
            return router.push(`/pedidos/${order.id}/cobrar`);
        case 'dispatch':
            dispatching.value = order;
            return;
        case 'couriers':
            return router.push('/repartidores');
    }
}

function dispatchTo(courier, recipient) {
    const order = dispatching.value;
    invoiceHint.value = '';
    run(order, 'dispatch', () => pos.dispatch(order.id, courier.id, recipient?.id), (r) => {
        dispatching.value = null;
        notices.success(`Sale con ${courier.name} · ${recipient ? 'factura' : 'ticket'} ${r.ticket.number}`);
    });
}
function deliverWithInvoice(customer) {
    const order = handingOver.value;
    handingOver.value = null;
    invoiceHint.value = '';
    run(order, 'deliver', () => pos.takeawayStatus(order.id, 'entregado', customer.id), () => notices.success('Entregado con factura'));
}
</script>
