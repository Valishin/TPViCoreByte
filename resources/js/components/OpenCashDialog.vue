<template>
    <ModalDialog
        :open="open"
        wide
        :title="prompt ? 'La caja está cerrada' : 'Abrir caja'"
        :text="prompt ? '¿La abres ahora? Hace falta para cobrar. Indica el fondo inicial (el cambio que hay en el cajón).' : 'Fondo inicial (el cambio que hay en el cajón).'"
        @close="$emit('close')"
    >
        <div class="c-tabs" style="margin-bottom: 1rem">
            <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': mode === 'amount' }]" @click="setMode('amount')">Importe</button>
            <button type="button" :class="['c-tabs__tab', { 'c-tabs__tab--active': mode === 'count' }]" @click="setMode('count')">Contar billetes y monedas</button>
        </div>
        <!-- En caja: importe y billetes a la izquierda, teclado a la derecha (cabe sin desplazar). -->
        <AmountKeypad v-if="mode === 'amount'" v-model="amount" label="Fondo inicial" :quick="[5000, 10000, 15000, 20000]" split />
        <DenominationCounter v-else @change="onCount" />
        <template #actions>
            <TpvButton variant="ghost" @click="$emit('close')">{{ prompt ? 'Ahora no' : 'Cancelar' }}</TpvButton>
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

defineProps({
    open: { type: Boolean, default: false },
    // Al entrar con la caja cerrada: la misma ventana, con la pregunta «¿La abres ahora?».
    prompt: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'opened']);

const authorize = useAuthorizeStore();
const guard = useAmountGuardStore();
const notices = useNoticesStore();
const mode = ref('amount');
const amount = ref(15000);
const breakdown = ref(null);
const busy = ref(false);

const typed = ref(amount.value);   // lo de «Importe», para volver a él

function onCount({ breakdown: b, total }) {
    breakdown.value = b;
    amount.value = total;
}

/**
 * Cada pestaña con su importe: al contar, el botón dice lo contado (el
 * recuento empieza vacío), no el fondo propuesto; al volver a «Importe»,
 * vuelve lo que había.
 */
function setMode(next) {
    if (next === mode.value) return;
    if (mode.value === 'amount') typed.value = amount.value;
    mode.value = next;
    breakdown.value = null;
    amount.value = next === 'count' ? 0 : typed.value;
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
