<template>
    <BottomSheet :open="!!target" :title="title" @close="$emit('close')">
        <div v-if="target" class="v-config__summary">
            <div class="c-tabs">
                <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': type === 'porcentaje' }]" @click="type = 'porcentaje'">Porcentaje</button>
                <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': type === 'importe' }]" @click="type = 'importe'">Importe</button>
            </div>
            <div v-if="type === 'porcentaje'" class="c-options">
                <button v-for="p in [5, 10, 15, 20, 25, 50]" :key="p" type="button" :class="['c-option', { 'c-option--selected': percent === p }]" @click="percent = p">
                    {{ p }} %
                    <span v-if="p * 100 > limit" class="c-option__extra">encargado</span>
                </button>
            </div>
            <AmountKeypad v-else v-model="amount" label="Descuento" />
            <p class="v-config__question">Motivo</p>
            <div class="c-chips">
                <button v-for="r in reasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': reason === r }]" @click="reason = r">{{ r }}</button>
            </div>
        </div>
        <template #footer>
            <TpvButton v-if="target?.line" variant="secondary" :loading="busy" @click="invite">Invitar</TpvButton>
            <TpvButton v-if="target?.order && order.order?.discount_amount" variant="secondary" :loading="busy" @click="remove">Quitar descuento</TpvButton>
            <TpvButton variant="primary" block :loading="busy" :disabled="!value" @click="apply">Aplicar descuento</TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AmountKeypad from './AmountKeypad.vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { useOrderStore } from '@/stores/order';
import { useSessionStore } from '@/stores/session';

/** target: { line } (descuento / invitación de línea) o { order } (descuento del pedido). */
const props = defineProps({ target: { type: Object, default: null } });
const emit = defineEmits(['close']);

const authorize = useAuthorizeStore();
const notices = useNoticesStore();
const order = useOrderStore();
const session = useSessionStore();

const type = ref('porcentaje');
const percent = ref(10);
const amount = ref(0);
const reason = ref('');
const busy = ref(false);
const reasons = ['Cliente habitual', 'Error en el servicio', 'Promoción', 'Cumpleaños'];
const limit = computed(() => session.settings?.discount_limit_bp ?? 1000);
const value = computed(() => (type.value === 'porcentaje' ? percent.value * 100 : amount.value));
const title = computed(() => (props.target?.line ? `${props.target.line.quantity}× ${props.target.line.name}` : 'Descuento al pedido'));

watch(() => props.target, () => {
    type.value = 'porcentaje';
    percent.value = 10;
    amount.value = 0;
    reason.value = '';
});

async function run(action, fn) {
    busy.value = true;
    try {
        order.order = await authorize.withAuthorization(action, fn);
        emit('close');
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
function apply() {
    const payload = (token) => ({ type: type.value, value: value.value, reason: reason.value || null, authorization_token: token });
    run('descuento', (token) =>
        props.target.line ? pos.discountLine(props.target.line.id, payload(token)) : pos.discountOrder(order.order.id, payload(token)),
    );
}
/** Quitar el descuento del pedido (p. ej. para unir la mesa a otra). */
function remove() {
    busy.value = true;
    pos.discountOrder(order.order.id, { type: 'porcentaje', value: 0 })
        .then((o) => {
            order.order = o;
            emit('close');
        })
        .catch((e) => notices.error(errorMessage(e)))
        .finally(() => (busy.value = false));
}
function invite() {
    run('invitacion', (token) => pos.inviteLine(props.target.line.id, { reason: reason.value || 'Invitación', authorization_token: token }));
}
</script>
