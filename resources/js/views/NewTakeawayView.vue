<template>
    <main class="v-new-order">
        <header class="v-order__header">
            <TpvButton variant="secondary" @click="router.push('/pedidos')">← Pedidos</TpvButton>
            <div class="v-order__where">Nuevo pedido</div>
        </header>

        <div class="v-new-order__body">
            <section class="v-new-order__col">
                <!-- 1. Tipo -->
                <div class="v-new-order__types">
                    <button
                        v-for="t in types"
                        :key="t.value"
                        type="button"
                        :class="['v-new-order__type', { 'v-new-order__type--active': type === t.value }]"
                        @click="setType(t.value)"
                    >{{ t.label }}</button>
                </div>

                <!-- 2a. Domicilio: teléfono con teclado numérico → ficha o alta -->
                <template v-if="type === 'domicilio'">
                    <div v-if="!lookedUp" class="v-new-order__phone">
                        <p class="v-config__question">Teléfono del cliente</p>
                        <NumericKeypad v-model="phone" :length="12" placeholder="Teléfono" ok-label="Buscar" @submit="lookup" @update:model-value="autoLookup" />
                    </div>

                    <div v-else class="v-new-order__customer">
                        <div class="v-new-order__phone-line">
                            <strong>{{ normalizedPhone }}</strong>
                            <TpvButton variant="ghost" @click="resetCustomer">Cambiar teléfono</TpvButton>
                        </div>

                        <!-- Cliente conocido -->
                        <template v-if="customer">
                            <p class="v-new-order__name">{{ customer.name }}</p>
                            <p v-if="customer.notes" class="c-alert c-alert--warning"><span class="c-alert__text">⚠ {{ customer.notes }}</span></p>

                            <p class="v-config__question">Dirección</p>
                            <div class="c-options">
                                <button
                                    v-for="addr in customer.addresses"
                                    :key="addr.id"
                                    type="button"
                                    :class="['c-option', { 'c-option--selected': addressId === addr.id }]"
                                    @click="addressId = addr.id; newAddress = null"
                                >
                                    {{ addressText(addr) }}
                                    <span class="c-option__extra">{{ addr.zone ? `${addr.zone.name} · envío ${money(addr.zone.delivery_fee)}` : 'Sin zona' }}</span>
                                </button>
                                <button type="button" :class="['c-option', { 'c-option--selected': newAddress }]" @click="startNewAddress">+ Otra dirección</button>
                            </div>
                            <AddressForm v-if="newAddress" v-model="newAddress" />

                            <div v-if="customer.recent_orders?.length" class="v-new-order__recent">
                                <p class="v-config__question">Últimos pedidos</p>
                                <button
                                    v-for="(r, i) in customer.recent_orders.slice(0, 3)"
                                    :key="r.id"
                                    type="button"
                                    :class="['c-option', { 'c-option--selected': repeatFrom === r.id }]"
                                    @click="repeatFrom = repeatFrom === r.id ? null : r.id"
                                >
                                    {{ dateLabel(r.opened_at) }} · {{ money(r.total) }}
                                    <span class="c-option__extra">{{ r.summary.join(', ') }}</span>
                                    <span v-if="i === 0" class="c-option__extra">{{ repeatFrom === r.id ? '✓ Se repetirá' : 'Toca para repetir' }}</span>
                                </button>
                            </div>
                        </template>

                        <!-- Cliente nuevo: alta rápida -->
                        <template v-else>
                            <p class="v-config__rule">Cliente nuevo: alta rápida.</p>
                            <TextField v-model="newName" label="Nombre" :maxlength="255" />
                            <AddressForm v-model="newAddress" />
                        </template>
                    </div>
                </template>

                <!-- 2b. Para llevar: nombre y/o teléfono (opcionales) -->
                <template v-if="type === 'para_llevar'">
                    <TextField v-model="takeawayName" label="Nombre (opcional)" :maxlength="255" />
                    <TextField v-model="takeawayPhone" label="Teléfono (opcional)" type="tel" keyboard="numeric" :maxlength="20" />
                </template>
            </section>

            <section v-if="type" class="v-new-order__col">
                <p class="v-config__question">{{ type === 'domicilio' ? 'Hora de entrega' : 'Hora de recogida' }}</p>
                <TakeawayTimePicker v-model="time" :minutes="asapMinutes" :now="now" />

                <p class="v-config__question">Pago previsto</p>
                <TakeawayPaymentPicker v-model="payment" :card-label="type === 'domicilio' ? 'Tarjeta (datáfono del repartidor)' : 'Tarjeta'" />

                <TpvButton variant="primary" size="lg" block :disabled="!canCreate" :loading="busy" @click="create">
                    {{ repeatFrom ? 'Crear y repetir el pedido' : 'Crear y añadir productos' }}
                </TpvButton>
                <p v-if="type === 'domicilio' && lookedUp && !canCreate" class="v-config__rule">{{ missing }}</p>
            </section>
        </div>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AddressForm from '@/components/AddressForm.vue';
import NumericKeypad from '@/components/NumericKeypad.vue';
import TakeawayPaymentPicker from '@/components/TakeawayPaymentPicker.vue';
import TakeawayTimePicker from '@/components/TakeawayTimePicker.vue';
import TextField from '@/components/TextField.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { useSessionStore } from '@/stores/session';
import { useTakeawayStore } from '@/stores/takeaway';
import { money } from '@/utils/format';

const router = useRouter();
const session = useSessionStore();
const notices = useNoticesStore();
const board = useTakeawayStore();

const types = computed(() => [
    { value: 'para_llevar', label: 'Para llevar' },
    { value: 'domicilio', label: 'Domicilio' },
].filter((t) => session.hasType(t.value)));

const type = ref(null);
const phone = ref('');
const lookedUp = ref(false);
const normalizedPhone = ref('');
const customer = ref(null);
const addressId = ref(null);
const newAddress = ref(null);
const newName = ref('');
const repeatFrom = ref(null);
const takeawayName = ref('');
const takeawayPhone = ref('');
const time = ref({ asap: true });
const payment = ref({ payment: 'efectivo', cash_change_for: null });
const busy = ref(false);
const now = ref(Date.now());

onMounted(() => {
    if (types.value.length === 1) type.value = types.value[0].value;
    if (!board.settings) board.load().catch(() => {});
});

const asapMinutes = computed(() =>
    type.value === 'domicilio' ? board.settings?.delivery_minutes ?? 35 : board.settings?.takeaway_prep_minutes ?? 15);

function setType(value) {
    type.value = value;
    now.value = Date.now();
}

async function lookup() {
    if (phone.value.length < 9) return;
    try {
        const result = await pos.lookupCustomer(phone.value);
        normalizedPhone.value = result.phone ?? phone.value;
        customer.value = result.customer;
        lookedUp.value = true;
        const def = result.customer?.addresses?.[0];
        addressId.value = def?.id ?? null;
        newAddress.value = result.customer ? (def ? null : {}) : {};
        repeatFrom.value = null;
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
/** Con 9 cifras (número español) busca solo. */
function autoLookup(value) {
    if (value.length === 9 && /^[6-9]/.test(value)) lookup();
}
function resetCustomer() {
    lookedUp.value = false;
    customer.value = null;
    phone.value = '';
    newAddress.value = null;
    addressId.value = null;
}
function startNewAddress() {
    addressId.value = null;
    newAddress.value = {};
}

const addressValid = computed(() => !!(newAddress.value?.street?.trim() && newAddress.value?.delivery_zone_id));
const missing = computed(() => {
    if (!customer.value && !newName.value.trim()) return 'Falta el nombre del cliente.';
    if (!addressId.value && !newAddress.value?.street?.trim()) return 'Falta la dirección.';
    if (!addressId.value && !newAddress.value?.delivery_zone_id) return 'Elige la zona de reparto.';
    return '';
});
const canCreate = computed(() => {
    if (!type.value) return false;
    if (type.value === 'para_llevar') return true;
    return lookedUp.value && !missing.value;
});

function addressText(a) {
    return [`${a.street} ${a.number ?? ''}`.trim(), a.floor_door, a.postal_code].filter(Boolean).join(', ');
}
function dateLabel(iso) {
    return new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
}

async function create() {
    busy.value = true;
    try {
        const payload = { type: type.value, ...time.value, ...payment.value };
        if (type.value === 'domicilio') {
            let c = customer.value;
            let address = addressId.value;
            if (!c) {
                c = await pos.createCustomer({ phone: normalizedPhone.value, name: newName.value.trim(), address: newAddress.value });
                address = c.addresses[0].id;
            } else if (!address) {
                address = (await pos.addAddress(c.id, newAddress.value)).address.id;
            }
            Object.assign(payload, { customer_id: c.id, address_id: address });
        } else {
            Object.assign(payload, { customer_name: takeawayName.value || null, customer_phone: takeawayPhone.value || null });
        }

        const created = await pos.createTakeaway(payload);
        board.upsert(created.takeaway);
        if (repeatFrom.value) {
            const repeated = await pos.repeatOrder(created.data.id, repeatFrom.value);
            if (repeated.skipped?.length) notices.toast(`No se ha podido repetir: ${repeated.skipped.join(', ')}`, 'warning', 6000);
        }
        router.replace(`/pedidos/${created.data.id}`);
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
