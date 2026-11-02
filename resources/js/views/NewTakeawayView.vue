<template>
    <main class="v-new-order">
        <header class="v-order__header">
            <TpvButton variant="secondary" @click="router.push('/pedidos')">← Pedidos</TpvButton>
            <div class="v-order__where">Nuevo pedido</div>
        </header>

        <!-- 1. Solo el teléfono -->
        <div v-if="step === 'phone'" class="v-new-order__start">
            <p class="v-config__question">Teléfono del cliente</p>
            <NumericKeypad v-model="phone" :length="12" placeholder="Teléfono" ok-label="Buscar" @submit="lookup" @update:model-value="autoLookup" />
            <TpvButton v-if="hasType('para_llevar')" variant="ghost" @click="walkIn">Para llevar sin teléfono</TpvButton>
        </div>

        <!-- 2. Cliente nuevo: solo el alta -->
        <section v-else-if="step === 'new'" class="v-new-order__start v-new-order__customer">
            <div class="v-new-order__phone-line">
                <strong>{{ normalizedPhone }}</strong>
                <TpvButton variant="ghost" @click="resetCustomer">Cambiar teléfono</TpvButton>
            </div>
            <p class="v-new-order__name">Cliente nuevo</p>
            <TypeToggle v-if="types.length > 1" v-model="type" :types="types" />
            <TextField v-model="newName" label="Nombre" :maxlength="255" />
            <AddressForm v-if="type === 'domicilio'" v-model="newAddress" />
            <TpvButton variant="primary" size="lg" block :disabled="!!newMissing" :loading="busy" @click="saveCustomer">
                {{ type === 'domicilio' ? 'Guardar cliente' : 'Continuar' }}
            </TpvButton>
            <p v-if="newMissing" class="v-config__rule">{{ newMissing }}</p>
        </section>

        <!-- 3. Pedido: dirección, hora y crear (el pago se pide al enviar) -->
        <div v-else class="v-new-order__body">
            <section class="v-new-order__col v-new-order__customer">
                <div class="v-new-order__phone-line">
                    <strong>{{ normalizedPhone || 'Sin teléfono' }}</strong>
                    <TpvButton variant="ghost" @click="resetCustomer">Cambiar teléfono</TpvButton>
                </div>
                <p v-if="customer" class="v-new-order__name">{{ customer.name }}</p>
                <p v-else-if="normalizedPhone" class="v-new-order__name">{{ takeawayName }}</p>
                <TextField v-else v-model="takeawayName" label="Nombre (opcional)" :maxlength="255" />
                <p v-if="customer?.notes" class="c-alert c-alert--warning"><span class="c-alert__text">⚠ {{ customer.notes }}</span></p>

                <TypeToggle v-if="types.length > 1 && normalizedPhone" :model-value="type" :types="types" @update:model-value="changeType" />

                <template v-if="type === 'domicilio' && customer">
                    <p class="v-config__question">Dirección</p>
                    <!-- Una dirección por fila, a todo lo ancho: calle · CP y municipio · zona -->
                    <div class="v-new-order__addresses">
                        <button
                            v-for="addr in customer.addresses"
                            :key="addr.id"
                            type="button"
                            :class="['c-option', 'v-new-order__address', { 'c-option--selected': addressId === addr.id }]"
                            @click="addressId = addr.id; newAddress = null"
                        >
                            <strong>{{ streetText(addr) }}</strong>
                            <span v-if="addr.postal_code || addr.city">{{ [addr.postal_code, addr.city].filter(Boolean).join(' ') }}</span>
                            <span class="c-option__extra">{{ addr.zone ? `${addr.zone.name} · envío ${money(addr.zone.delivery_fee)}` : 'Sin zona' }}</span>
                        </button>
                        <button type="button" :class="['c-option', 'v-new-order__address-new', { 'c-option--selected': newAddress }]" @click="startNewAddress">+ Otra dirección</button>
                    </div>
                    <AddressForm v-if="newAddress" v-model="newAddress" />
                </template>

                <div v-if="customer?.recent_orders?.length" class="v-new-order__recent">
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
            </section>

            <section class="v-new-order__col">
                <p class="v-config__question">{{ type === 'domicilio' ? 'Hora de entrega' : 'Hora de recogida' }}</p>
                <TakeawayTimePicker v-model="time" :minutes="asapMinutes" :now="now" />

                <TpvButton variant="primary" size="lg" block :disabled="!!missing" :loading="busy" @click="create">
                    {{ repeatFrom ? 'Crear y repetir el pedido' : 'Crear y añadir productos' }}
                </TpvButton>
                <p v-if="missing" class="v-config__rule">{{ missing }}</p>
                <p class="v-config__rule">La forma de pago y el cambio se piden al enviar el pedido.</p>
            </section>
        </div>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AddressForm from '@/components/AddressForm.vue';
import NumericKeypad from '@/components/NumericKeypad.vue';
import TakeawayTimePicker from '@/components/TakeawayTimePicker.vue';
import TextField from '@/components/TextField.vue';
import TpvButton from '@/components/TpvButton.vue';
import TypeToggle from '@/components/TakeawayTypeToggle.vue';
import pos, { errorMessage } from '@/services/pos';
import { useNoticesStore } from '@/stores/notices';
import { useSessionStore } from '@/stores/session';
import { useTakeawayStore } from '@/stores/takeaway';
import { money } from '@/utils/format';

/**
 * Nuevo pedido para llevar / a domicilio:
 *   1. solo el teléfono;
 *   2. si no existe, solo el alta del cliente;
 *   3. dirección y hora → «Crear y añadir productos».
 * La forma de pago y el cambio se piden al enviar (OrderView).
 */
const router = useRouter();
const session = useSessionStore();
const notices = useNoticesStore();
const board = useTakeawayStore();

const hasType = (t) => session.hasType(t);
const types = computed(() => [
    { value: 'domicilio', label: 'Domicilio' },
    { value: 'para_llevar', label: 'Para llevar' },
].filter((t) => hasType(t.value)));

const step = ref('phone');
const type = ref(null);
const phone = ref('');
const normalizedPhone = ref('');
const customer = ref(null);
const addressId = ref(null);
const newAddress = ref(null);
const newName = ref('');
const repeatFrom = ref(null);
const takeawayName = ref('');
const time = ref({ asap: true });
const busy = ref(false);
const now = ref(Date.now());

onMounted(() => {
    if (!board.settings) board.load().catch(() => {});
});

const defaultType = () => types.value[0]?.value ?? null;
const asapMinutes = computed(() =>
    type.value === 'domicilio' ? board.settings?.delivery_minutes ?? 35 : board.settings?.takeaway_prep_minutes ?? 15);

async function lookup() {
    if (phone.value.length < 9 || busy.value) return;
    busy.value = true;
    try {
        const result = await pos.lookupCustomer(phone.value);
        normalizedPhone.value = result.phone ?? phone.value;
        customer.value = result.customer;
        type.value = defaultType();
        repeatFrom.value = null;
        now.value = Date.now();
        if (result.customer) {
            useCustomer(result.customer);
        } else {
            newName.value = '';
            newAddress.value = {};
            step.value = 'new';
        }
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
/** Con 9 cifras (número español) busca solo. */
function autoLookup(value) {
    if (value.length === 9 && /^[6-9]/.test(value)) lookup();
}
function useCustomer(c) {
    customer.value = c;
    const def = c.addresses?.[0];
    addressId.value = def?.id ?? null;
    newAddress.value = def ? null : {};
    step.value = 'details';
}
/** Para llevar de alguien que está en el local: sin teléfono ni ficha. */
function walkIn() {
    resetCustomer();
    type.value = 'para_llevar';
    now.value = Date.now();
    step.value = 'details';
}
function resetCustomer() {
    step.value = 'phone';
    customer.value = null;
    phone.value = '';
    normalizedPhone.value = '';
    newAddress.value = null;
    addressId.value = null;
    takeawayName.value = '';
    repeatFrom.value = null;
}
function startNewAddress() {
    addressId.value = null;
    newAddress.value = {};
}
/** Sin ficha y se cambia a domicilio: hace falta el alta con la dirección. */
function changeType(value) {
    type.value = value;
    if (value === 'domicilio' && !customer.value) {
        newName.value = takeawayName.value;
        newAddress.value = {};
        step.value = 'new';
    }
}

const addressMissing = (a) => {
    if (!a?.street?.trim()) return 'Falta la dirección.';
    if (!a?.delivery_zone_id) return 'Elige la zona de reparto.';
    return '';
};
const newMissing = computed(() => {
    if (!newName.value.trim()) return 'Falta el nombre del cliente.';
    return type.value === 'domicilio' ? addressMissing(newAddress.value) : '';
});
const missing = computed(() => {
    if (!type.value) return 'Este local no tiene activos los pedidos para llevar ni a domicilio.';
    if (type.value !== 'domicilio') return '';
    if (!customer.value) return 'Falta el cliente.';
    return addressId.value ? '' : addressMissing(newAddress.value);
});

/** Alta del cliente nuevo (domicilio) o solo su nombre (para llevar) y al pedido. */
async function saveCustomer() {
    if (type.value !== 'domicilio') {
        takeawayName.value = newName.value.trim();
        step.value = 'details';
        return;
    }
    busy.value = true;
    try {
        useCustomer(await pos.createCustomer({ phone: normalizedPhone.value, name: newName.value.trim(), address: newAddress.value }));
    } catch (e) {
        notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}

function streetText(a) {
    return [`${a.street} ${a.number ?? ''}`.trim(), a.floor_door].filter(Boolean).join(', ');
}
function dateLabel(iso) {
    return new Date(iso).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
}

async function create() {
    busy.value = true;
    try {
        const payload = { type: type.value, ...time.value };
        if (customer.value) {
            let address = addressId.value;
            if (type.value === 'domicilio' && !address) {
                address = (await pos.addAddress(customer.value.id, newAddress.value)).address.id;
            }
            Object.assign(payload, { customer_id: customer.value.id, address_id: type.value === 'domicilio' ? address : null });
        } else {
            Object.assign(payload, { customer_name: takeawayName.value || null, customer_phone: normalizedPhone.value || null });
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
