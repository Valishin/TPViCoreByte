<template>
    <ModalDialog :open="open" title="Abrir caja" text="Fondo inicial (el cambio que hay en el cajón)." @close="$emit('close')">
        <div class="c-tabs" style="margin-bottom: 1rem">
            <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': mode === 'amount' }]" @click="mode = 'amount'">Importe</button>
            <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': mode === 'count' }]" @click="mode = 'count'">Contar billetes y monedas</button>
        </div>
        <AmountKeypad v-if="mode === 'amount'" v-model="amount" label="Fondo inicial" :quick="[5000, 10000, 15000, 20000]" />
        <DenominationCounter v-else @change="onCount" />
        <template #actions>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton variant="primary" size="lg" :loading="busy" @click="submit">Abrir con {{ money(amount) }}</TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref } from 'vue';
import AmountKeypad from './AmountKeypad.vue';
import DenominationCounter from './DenominationCounter.vue';
import ModalDialog from './ModalDialog.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useAmountGuardStore } from '@/stores/amountGuard';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { money } from '@/utils/format';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'opened']);

const authorize = useAuthorizeStore();
const guard = useAmountGuardStore();
const notices = useNoticesStore();
const mode = ref('amount');
const amount = ref(15000);
const breakdown = ref(null);
const busy = ref(false);

function onCount({ breakdown: b, total }) {
    breakdown.value = b;
    amount.value = total;
}

async function submit() {
    // El importe se fija al pulsar: lo que pase después (autorización) no lo cambia.
    const payload = { opening_float: amount.value, breakdown: mode.value === 'count' ? breakdown.value : null };
    if (!(await guard.check('opening_float', payload.opening_float, 'el fondo de caja'))) return;
    busy.value = true;
    try {
        const session = await authorize.withAuthorization('abrir_caja', (token) =>
            pos.openCash({ ...payload, authorization_token: token }),
        );
        notices.success('Caja abierta');
        emit('opened', session);
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
