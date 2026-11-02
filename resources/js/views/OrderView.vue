<template>
    <main class="v-order">
        <header class="v-order__header">
            <TpvButton variant="secondary" :aria-label="isTakeaway ? 'Volver a pedidos' : 'Volver al plano'" @click="router.push(backPath)">
                {{ isTakeaway ? '← Pedidos' : '← Mesas' }}
            </TpvButton>
            <div class="v-order__where">
                <span>{{ where }}</span>
                <span v-if="o?.table?.status === 'cuenta_pedida'" class="c-badge c-badge--pending">Cuenta pedida</span>
                <span v-if="o?.other_accounts?.length" class="c-badge c-badge--info">
                    Mesa {{ o.table.name }}: {{ o.other_accounts.length === 1 ? 'otra cuenta abierta' : `${o.other_accounts.length} cuentas más abiertas` }}
                </span>
                <!-- Separado de las acciones frecuentes -->
                <TpvButton
                    v-if="o?.type === 'mesa' || o?.type === 'barra'"
                    variant="ghost"
                    :class="['v-order__release', { 'v-order__release--blocked': o.release_blocker }]"
                    :aria-disabled="o.release_blocker ? 'true' : undefined"
                    :title="o.release_blocker || ''"
                    @click="askRelease"
                >
                    {{ o.type === 'barra' ? 'Descartar venta' : o.other_accounts?.length ? 'Liberar esta cuenta' : 'Liberar mesa' }}
                </TpvButton>
            </div>
            <template v-if="isTakeaway">
                <TpvButton variant="danger" :disabled="!o" @click="cancelOpen = true">Cancelar pedido</TpvButton>
                <TpvButton v-if="o?.type === 'domicilio'" variant="success" :disabled="!o || !o.lines.length" :loading="sending" @click="goDispatch">Dar salida</TpvButton>
                <TpvButton v-else variant="success" :disabled="!o || !o.lines.length" :loading="sending" @click="goCheckout">Cobrar</TpvButton>
            </template>
            <template v-else>
                <TpvButton variant="secondary" :disabled="!o" @click="guestsOpen = true">{{ o?.guests ?? '–' }} pax</TpvButton>
                <TpvButton variant="secondary" :disabled="!o || o.type !== 'mesa'" @click="moveOpen = true">Mover</TpvButton>
                <TpvButton variant="secondary" :disabled="!o || o.type !== 'mesa'" @click="joinOpen = true">Unir mesa</TpvButton>
                <TpvButton v-if="(o?.tables?.length ?? 0) > 1" variant="secondary" @click="openSeparate">Separar mesas</TpvButton>
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
        <JoinDialog :open="joinOpen" :order="o" :busy="order.busy" @confirm="join" @close="joinOpen = false" />

        <BottomSheet :open="separateOpen" title="Separar mesas" @close="separateOpen = false">
            <p class="v-config__rule">
                Las mesas que separes vuelven a estar libres. Los productos se quedan en {{ o?.table_label }}; para pasar
                productos a otra mesa, usa «Dividir por productos» al cobrar.
            </p>
            <div class="c-options">
                <button
                    v-for="t in joinedTables"
                    :key="t.id"
                    type="button"
                    :class="['c-option', { 'c-option--selected': separateIds.includes(t.id) }]"
                    :aria-pressed="separateIds.includes(t.id) ? 'true' : 'false'"
                    @click="toggleSeparate(t.id)"
                >
                    Mesa {{ t.name }} <span v-if="separateIds.includes(t.id)" aria-hidden="true">✓</span>
                </button>
            </div>
            <template #footer>
                <TpvButton variant="ghost" @click="separateOpen = false">Cancelar</TpvButton>
                <TpvButton variant="primary" block :disabled="!separateIds.length" :loading="order.busy" @click="separate">
                    Separar ({{ separateIds.length }})
                </TpvButton>
            </template>
        </BottomSheet>

        <ModalDialog :open="releaseOpen" :title="releaseTitle" :text="releaseText" danger @close="releaseOpen = false">
            <template #actions>
                <TpvButton variant="ghost" @click="releaseOpen = false">Volver</TpvButton>
                <TpvButton variant="danger" :loading="releasing" @click="release">{{ o?.type === 'barra' ? 'Descartar venta' : o?.other_accounts?.length ? 'Liberar esta cuenta' : 'Liberar mesa' }}</TpvButton>
            </template>
        </ModalDialog>

        <!-- Domicilio por debajo del pedido mínimo de la zona -->
        <ModalDialog :open="!!belowMinimum" title="No llega al pedido mínimo" :text="belowMinimum?.message ?? ''" danger @close="belowMinimum = null">
            <template #actions>
                <TpvButton variant="ghost" @click="belowMinimum = null">Añadir más productos</TpvButton>
                <TpvButton variant="primary" :loading="sending" @click="sendBelowMinimum">Continuar (autoriza encargado)</TpvButton>
            </template>
        </ModalDialog>

        <!-- Llevar / domicilio: forma de pago y cambio al enviar (si aún no se ha elegido) -->
        <ModalDialog :open="!!paymentAsk" title="Forma de pago" wide @close="answerPayment(false)">
            <TakeawayPaymentPicker v-model="paymentChoice" :total="o?.total ?? 0" :card-label="o?.type === 'domicilio' ? 'Tarjeta (datáfono del repartidor)' : 'Tarjeta'" />
            <template #actions>
                <TpvButton variant="ghost" size="lg" @click="answerPayment(false)">Volver</TpvButton>
                <TpvButton variant="primary" size="lg" :loading="savingPayment" @click="savePaymentAndSend">Enviar</TpvButton>
            </template>
        </ModalDialog>

        <DispatchSheet v-if="o?.type === 'domicilio'" :order="dispatching" :busy="sending" :invoice-hint="invoiceHint" @confirm="dispatchTo" @close="dispatching = null" />

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
import JoinDialog from '@/components/JoinDialog.vue';
import LineActions from '@/components/LineActions.vue';
import ModalDialog from '@/components/ModalDialog.vue';
import MoveDialog from '@/components/MoveDialog.vue';
import OrderPanel from '@/components/OrderPanel.vue';
import ProductCard from '@/components/ProductCard.vue';
import ProductConfigurator from '@/components/ProductConfigurator.vue';
import DispatchSheet from '@/components/DispatchSheet.vue';
import TakeawayHeader from '@/components/TakeawayHeader.vue';
import TakeawayPaymentPicker from '@/components/TakeawayPaymentPicker.vue';
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
const joinOpen = ref(false);
const separateOpen = ref(false);
const separateIds = ref([]);
const releaseOpen = ref(false);
const releasing = ref(false);
const sending = ref(false);
const prechecking = ref(false);
const failedJob = ref(null);
const belowMinimum = ref(null);
const dispatching = ref(null);
const invoiceHint = ref('');
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
const where = computed(() => (o.value?.table ? `${o.value.table_label} · ${o.value.table.room}` : types[o.value?.type] ?? 'Pedido'));
const joinedTables = computed(() => (o.value?.tables ?? []).filter((t) => !t.is_main));
const releaseTitle = computed(() => (o.value?.type === 'barra' ? '¿Descartar esta venta?'
    : o.value?.other_accounts?.length ? '¿Liberar esta cuenta?' : `¿Liberar ${o.value?.table_label ?? 'la mesa'}?`));
const releaseText = computed(() => {
    const pending = order.pendingCount;
    const parts = [pending === 1 ? 'Se descartará 1 producto sin enviar.' : pending ? `Se descartarán ${pending} productos sin enviar.` : 'No hay productos.'];
    if (o.value?.type === 'barra') return parts.join(' ');
    parts.push(o.value?.other_accounts?.length
        ? `La mesa ${o.value.table.name} sigue abierta con su otra cuenta.`
        : `${(o.value?.tables?.length ?? 1) > 1 ? 'Las mesas quedan libres' : 'La mesa queda libre'} (abierta por error).`);
    return parts.join(' ');
});
const isTakeaway = computed(() => route.name === 'takeaway-order' || ['para_llevar', 'domicilio'].includes(o.value?.type));
const backPath = computed(() => (isTakeaway.value ? '/pedidos' : '/mesas'));

async function loadOrder() {
    try {
        const loaded = await order.load(Number(route.params.orderId));
        if (loaded.status === 'unido') return;   // lo resuelve el watch de abajo
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
// Cambios desde otra pantalla (tiempo real): unida a otra mesa, liberada o cobrada.
watch(() => o.value?.status, (status) => {
    if (!status || status === 'abierto' || releasing.value) return;
    if (status === 'unido' && o.value.merged_into_order_id) {
        notices.toast('Esta mesa se ha unido a otra: se abre el pedido del grupo.', 'info', 5000);
        router.replace(`/mesas/pedido/${o.value.merged_into_order_id}`);
    } else {
        notices.toast('Este pedido ya está cerrado.');
        router.replace(backPath.value);
    }
});
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

// Forma de pago y cambio: se piden al enviar un pedido de llevar / domicilio que aún no la tiene.
const paymentAsk = ref(null);
const paymentChoice = ref({ payment: 'efectivo', cash_change_for: null });
const savingPayment = ref(false);
function needsPayment() {
    const t = o.value?.takeaway;
    return !!t && !t.planned_payment_method && !t.prepaid_method;
}
function askPayment() {
    paymentChoice.value = { payment: 'efectivo', cash_change_for: null };
    return new Promise((resolve) => {
        paymentAsk.value = { resolve };
    });
}
function answerPayment(ok) {
    paymentAsk.value?.resolve(ok);
    paymentAsk.value = null;
}
async function savePaymentAndSend() {
    savingPayment.value = true;
    try {
        order.order = (await pos.updateTakeaway(o.value.id, paymentChoice.value)).data;
        answerPayment(true);
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        savingPayment.value = false;
    }
}

async function send() {
    if (isTakeaway.value) {
        if (needsPayment() && !(await askPayment())) return;
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
async function dispatchTo(courier, recipient) {
    sending.value = true;
    invoiceHint.value = '';
    try {
        const result = await pos.dispatch(o.value.id, courier.id, recipient?.id);
        board.upsert(result.takeaway);
        dispatching.value = null;
        notices.success(`Sale con ${courier.name} · ${recipient ? 'factura' : 'ticket'} ${result.ticket.number}`);
        router.replace('/pedidos');
    } catch (e) {
        // Más de 3.000 €: se abre la ficha de datos fiscales (art. 4.2.c RD 1619/2012).
        if (errorCode(e) === 'invoice_required') invoiceHint.value = errorMessage(e);
        else notices.error(errorMessage(e));
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
async function join(payload) {
    try {
        const result = await order.join(payload);
        joinOpen.value = false;
        floor.refresh();
        notices.success(`Unidas: ${order.order.table_label}${result.merged?.length ? ' (productos y comensales sumados)' : ''}`);
    } catch (e) {
        notices.error(errorMessage(e));
        floor.refresh();
    }
}
function openSeparate() {
    separateIds.value = [];
    separateOpen.value = true;
}
function toggleSeparate(id) {
    const i = separateIds.value.indexOf(id);
    if (i >= 0) separateIds.value.splice(i, 1);
    else separateIds.value.push(id);
}
async function separate() {
    await act(() => order.separate(separateIds.value), (result) => {
        separateOpen.value = false;
        floor.refresh();
        notices.success(`Separada${result.separated.length > 1 ? 's' : ''}: mesa ${result.separated.join(', ')}`);
    });
}
function askRelease() {
    if (o.value?.release_blocker) {
        notices.toast(o.value.release_blocker, 'warning', 5000);
        return;
    }
    releaseOpen.value = true;
}
async function release() {
    releasing.value = true;
    try {
        await pos.releaseTable(o.value.id);
        releaseOpen.value = false;
        floor.refresh();
        notices.success(o.value?.type === 'barra' ? 'Venta descartada' : 'Mesa liberada');
        order.clear();
        router.replace('/mesas');
    } catch (e) {
        notices.error(errorMessage(e));
        order.reload().catch(() => {});
    } finally {
        releasing.value = false;
    }
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
