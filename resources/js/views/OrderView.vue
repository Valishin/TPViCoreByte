<template>
    <main class="v-order">
        <header class="v-order__header">
            <TpvButton variant="secondary" :aria-label="isTakeaway ? 'Volver a pedidos' : 'Volver al plano'" @click="router.push(backPath)">
                {{ isTakeaway ? '← Pedidos' : '← Mesas' }}
            </TpvButton>
            <div class="v-order__where">
                {{ where }}
                <span v-if="o?.table?.status === 'cuenta_pedida'" class="c-badge c-badge--pending">Cuenta pedida</span>
            </div>
            <template v-if="isTakeaway">
                <TpvButton variant="danger" :disabled="!o" @click="cancelOpen = true">Cancelar pedido</TpvButton>
                <TpvButton v-if="o?.type === 'domicilio'" variant="success" :disabled="!o || !o.lines.length" :loading="sending" @click="goDispatch">Dar salida</TpvButton>
                <TpvButton v-else variant="success" :disabled="!o || !o.lines.length" :loading="sending" @click="goCheckout">Cobrar</TpvButton>
            </template>
            <template v-else>
                <TpvButton variant="secondary" :disabled="!o" @click="guestsOpen = true">{{ o?.guests ?? '–' }} pax</TpvButton>
                <TpvButton variant="secondary" :disabled="!o || o.type !== 'mesa'" @click="moveOpen = true">Mover</TpvButton>
                <TpvButton variant="secondary" :disabled="!o || !o.lines.length" :loading="prechecking" @click="precheck">Cuenta</TpvButton>
                <TpvButton variant="success" :disabled="!o || !o.lines.length" :loading="sending" @click="goCheckout">Cobrar</TpvButton>
            </template>
        </header>
        <TakeawayHeader v-if="o?.takeaway" :takeaway="o.takeaway" :total="o.total" @updated="order.order = $event" />

        <div v-if="o" class="v-order__body">
            <nav class="v-order__categories" aria-label="Categorías">
                <button
                    v-for="c in catalog.categories"
                    :key="c.id"
                    type="button"
                    :class="['v-order__category', { 'v-order__category--active': c.id === categoryId }]"
                    :style="{ '--category-color': c.color }"
                    @click="categoryId = c.id"
                >
                    {{ c.name }}
                </button>
            </nav>

            <section class="v-order__products" aria-label="Productos">
                <ProductCard
                    v-for="p in products"
                    :key="p.id"
                    :product="p"
                    :color="category?.color"
                    :flash="flashId === p.id"
                    @select="tapProduct"
                />
            </section>

            <!-- Caja: el pedido siempre a la vista -->
            <OrderPanel
                v-if="ui.isPos"
                :sending="sending"
                @select-line="selectedLineId = $event.id"
                @send="send"
                @failed-job="failedJob = $event"
            />
        </div>

        <!-- Móvil: barra inferior con resumen y Enviar; el pedido en una hoja -->
        <div v-if="o && !ui.isPos" class="v-order__bar">
            <button type="button" class="v-order__summary" @click="sheetOpen = true">
                <span class="v-order__summary-total">{{ money(o.total) }}</span>
                <span class="v-order__summary-count">
                    {{ order.pendingCount ? `${order.pendingCount} por enviar · ` : '' }}Ver pedido ▴
                </span>
            </button>
            <TpvButton variant="primary" :disabled="!order.pendingCount" :loading="sending" :count="order.pendingCount || null" @click="send">
                Enviar
            </TpvButton>
        </div>
        <BottomSheet v-if="!ui.isPos" :open="sheetOpen" :title="where" @close="sheetOpen = false">
            <OrderPanel :sending="sending" @select-line="selectedLineId = $event.id" @send="send" @failed-job="failedJob = $event" />
        </BottomSheet>

        <ProductConfigurator :product="configuring" :busy="order.busy" @add="addConfigured" @close="closeConfigurator" />
        <LineActions :line-id="selectedLineId" @close="selectedLineId = null" />
        <GuestsDialog :open="guestsOpen" :current="o?.guests" @confirm="setGuests" @close="guestsOpen = false" />
        <MoveDialog :open="moveOpen" @confirm="move" @close="moveOpen = false" />

        <!-- Domicilio por debajo del pedido mínimo de la zona -->
        <ModalDialog :open="!!belowMinimum" title="No llega al pedido mínimo" :text="belowMinimum?.message ?? ''" danger @close="belowMinimum = null">
            <template #actions>
                <TpvButton variant="ghost" @click="belowMinimum = null">Añadir más productos</TpvButton>
                <TpvButton variant="primary" :loading="sending" @click="sendBelowMinimum">Continuar (autoriza encargado)</TpvButton>
            </template>
        </ModalDialog>

        <DispatchSheet v-if="o?.type === 'domicilio'" :order="dispatching" :busy="sending" @confirm="dispatchTo" @close="dispatching = null" />

        <BottomSheet :open="cancelOpen" title="Cancelar pedido" @close="cancelOpen = false">
            <p class="v-config__rule">{{ order.order?.send_count ? 'Ya se envió a cocina: se imprimirá la anulación y necesita autorización de encargado.' : 'Todavía no se ha enviado nada a cocina.' }}</p>
            <p class="v-config__question">Motivo</p>
            <div class="c-options">
                <button v-for="r in cancelReasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': cancelReason === r }]" @click="cancelReason = r">{{ r }}</button>
            </div>
            <TextField v-model="cancelReason" label="Otro motivo" :maxlength="120" />
            <template #footer>
                <TpvButton variant="ghost" @click="cancelOpen = false">Volver</TpvButton>
                <TpvButton variant="danger" block :disabled="!cancelReason" :loading="cancelling" @click="cancelOrder">Cancelar pedido</TpvButton>
            </template>
        </BottomSheet>

        <ModalDialog :open="!!failedJob" title="Comanda sin imprimir" :text="failedJob ? `${failedJob.printer?.name}: ${failedJob.error}` : ''" danger @close="failedJob = null">
            <div class="c-options">
                <button v-for="p in otherPrinters" :key="p.id" type="button" class="c-option" @click="redirectJob(p.id)">
                    Enviar a {{ p.name }}
                </button>
            </div>
            <template #actions>
                <TpvButton variant="ghost" @click="failedJob = null">Cerrar</TpvButton>
                <TpvButton variant="primary" @click="retryJob">Reintentar</TpvButton>
            </template>
        </ModalDialog>
    </main>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BottomSheet from '@/components/BottomSheet.vue';
import GuestsDialog from '@/components/GuestsDialog.vue';
import LineActions from '@/components/LineActions.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import MoveDialog from '@/components/MoveDialog.vue';
import OrderPanel from '@/components/OrderPanel.vue';
import ProductCard from '@/components/ProductCard.vue';
import ProductConfigurator from '@/components/ProductConfigurator.vue';
import DispatchSheet from '@/components/DispatchSheet.vue';
import TakeawayHeader from '@/components/TakeawayHeader.vue';
import TextField from '@/components/TextField.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorCode, errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useTakeawayStore } from '@/stores/takeaway';
import { useCatalogStore } from '@/stores/catalog';
import { useFloorStore } from '@/stores/floor';
import { useNoticesStore } from '@/stores/notices';
import { useOrderStore } from '@/stores/order';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';
import { money } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const catalog = useCatalogStore();
const floor = useFloorStore();
const notices = useNoticesStore();
const order = useOrderStore();
const session = useSessionStore();
const ui = useUiStore();

const categoryId = ref(null);
const configuring = ref(null);
const selectedLineId = ref(null);
const flashId = ref(null);
const sheetOpen = ref(false);
const guestsOpen = ref(false);
const moveOpen = ref(false);
const sending = ref(false);
const prechecking = ref(false);
const failedJob = ref(null);
const belowMinimum = ref(null);
const dispatching = ref(null);
const cancelOpen = ref(false);
const cancelReason = ref('');
const cancelling = ref(false);
const cancelReasons = ['El cliente lo anula', 'No contesta / no confirma', 'Pedido duplicado', 'Error al tomarlo'];
const authorize = useAuthorizeStore();
const board = useTakeawayStore();

const o = computed(() => (order.order?.id === Number(route.params.orderId) ? order.order : null));
const category = computed(() => catalog.categories.find((c) => c.id === categoryId.value));
const products = computed(() => (categoryId.value ? catalog.productsOf(categoryId.value) : []));
const otherPrinters = computed(() => notices.printers.filter((p) => p.id !== failedJob.value?.printer?.id));
const types = { barra: 'Barra', para_llevar: 'Para llevar', domicilio: 'Domicilio' };
const where = computed(() => (o.value?.table ? `Mesa ${o.value.table.name} · ${o.value.table.room}` : types[o.value?.type] ?? 'Pedido'));
const isTakeaway = computed(() => route.name === 'takeaway-order' || ['para_llevar', 'domicilio'].includes(o.value?.type));
const backPath = computed(() => (isTakeaway.value ? '/pedidos' : '/mesas'));

async function loadOrder() {
    try {
        const loaded = await order.load(Number(route.params.orderId));
        if (loaded.status !== 'abierto') {
            notices.toast('Este pedido ya está cerrado.');
            router.replace(backPath.value);
        }
    } catch (e) {
        notices.error(errorMessage(e));
        router.replace(backPath.value);
    }
}

onMounted(async () => {
    await loadOrder();
    if (!catalog.data) await catalog.load();
    categoryId.value ??= catalog.categories[0]?.id ?? null;
    // Volver de un bloqueo con el configurador a medias.
    if (order.draft) configuring.value = catalog.productById(order.draft.productId) ?? null;
});
watch(() => route.params.orderId, () => route.params.orderId && loadOrder());
watch(() => catalog.categories, (cats) => {
    if (!cats.some((c) => c.id === categoryId.value)) categoryId.value = cats[0]?.id ?? null;
});

async function act(fn, ok) {
    try {
        const result = await fn();
        if (ok) ok(result);
        return result;
    } catch (e) {
        notices.error(errorMessage(e));
    }
}

/** Producto simple: 1 toque. Con opciones: configurador. */
function tapProduct(product) {
    if (catalog.needsConfig(product)) {
        configuring.value = product;
        return;
    }
    act(() => order.addLine({ product_id: product.id, quantity: 1 }), () => {
        flashId.value = product.id;
        setTimeout(() => (flashId.value === product.id ? (flashId.value = null) : null), 250);
    });
}
function addConfigured(payload) {
    act(() => order.addLine(payload), () => closeConfigurator());
}
function closeConfigurator() {
    configuring.value = null;
    order.draft = null;
}

function sent(result) {
    const sent = result.sent;
    const where = sent.jobs.map((j) => `${j.printer} (${j.lines})`).join(' · ');
    notices.success(where ? `Enviado: ${where}` : 'Enviado');
    if (sent.unrouted.length) notices.toast(`Sin impresora: ${sent.unrouted.join(', ')}`, 'warning', 6000);
    sheetOpen.value = false;
}
/** Domicilio por debajo del mínimo: aviso y, si se quiere seguir, autorización. */
async function sendBelowMinimum() {
    sending.value = true;
    try {
        const result = await authorize.withAuthorization('pedido_minimo', (token) =>
            order.send({ accept_below_minimum: true, authorization_token: token }));
        belowMinimum.value = null;
        sent(result);
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        sending.value = false;
    }
}

async function send() {
    if (isTakeaway.value) {
        sending.value = true;
        try {
            sent(await order.send());
        } catch (e) {
            if (errorCode(e) === 'minimum_order') belowMinimum.value = e.response.data;
            else notices.error(errorMessage(e));
        } finally {
            sending.value = false;
        }
        return;
    }
    sending.value = true;
    await act(() => order.send(), (result) => {
        const sent = result.sent;
        const where = sent.jobs.map((j) => `${j.printer} (${j.lines})`).join(' · ');
        notices.success(where ? `Enviado: ${where}` : 'Enviado');
        if (sent.unrouted.length) notices.toast(`Sin impresora: ${sent.unrouted.join(', ')}`, 'warning', 6000);
        sheetOpen.value = false;
        if (ui.isPos && session.settings?.lock_pos_after_send) {
            session.lock(null).then(() => router.replace('/entrar'));
        }
    });
    sending.value = false;
}
/** Cobrar: si quedan líneas sin enviar, se envían antes (un solo toque). */
async function goCheckout() {
    if (order.pendingCount) await send();
    if (!order.pendingCount) router.push(isTakeaway.value ? `/pedidos/${o.value.id}/cobrar` : `/mesas/pedido/${o.value.id}/cobrar`);
}
/** Dar salida: si quedan líneas sin enviar, se envían antes. */
async function goDispatch() {
    if (order.pendingCount) await send();
    if (!order.pendingCount && !belowMinimum.value) dispatching.value = o.value.takeaway;
}
async function dispatchTo(courier) {
    sending.value = true;
    try {
        const result = await pos.dispatch(o.value.id, courier.id);
        board.upsert(result.takeaway);
        dispatching.value = null;
        notices.success(`Sale con ${courier.name} · ticket ${result.ticket.number}`);
        router.replace('/pedidos');
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        sending.value = false;
    }
}
async function cancelOrder() {
    cancelling.value = true;
    try {
        const result = await authorize.withAuthorization('cancelar_pedido', (token) =>
            pos.cancelTakeaway(o.value.id, { reason: cancelReason.value, authorization_token: token }));
        board.upsert(result.takeaway);
        cancelOpen.value = false;
        notices.success('Pedido cancelado');
        router.replace('/pedidos');
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        cancelling.value = false;
    }
}
async function precheck() {
    prechecking.value = true;
    await act(() => order.precheck(), () => notices.success('Cuenta enviada a la impresora'));
    prechecking.value = false;
}
function setGuests(n) {
    guestsOpen.value = false;
    act(() => order.setGuests(n));
}
function move(tableId) {
    moveOpen.value = false;
    act(() => order.move(tableId), () => {
        floor.refresh();
        notices.success(`Movido a la mesa ${order.order.table?.name}`);
    });
}
function retryJob() {
    const job = failedJob.value;
    failedJob.value = null;
    act(() => notices.retry(job)).then(() => order.reload());
}
function redirectJob(printerId) {
    const job = failedJob.value;
    failedJob.value = null;
    act(() => notices.redirect(job, printerId)).then(() => order.reload());
}
</script>
