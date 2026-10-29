<template>
    <BottomSheet :open="!!ticket" :title="ticket ? `Anular ${ticket.number}` : ''" @close="$emit('close')">
        <div v-if="detail" class="v-config__summary">
            <p class="v-config__rule">Se emitirá un ticket rectificativo con los importes en negativo. El original no se modifica.</p>
            <p class="v-config__question">¿Qué se anula?</p>
            <div v-for="line in roots" :key="line.id" class="v-config__row">
                <span>{{ line.name }}<template v-if="line.variant_name"> ({{ line.variant_name }})</template> · {{ money(line.line_total) }}</span>
                <span class="c-stepper">
                    <TpvButton variant="secondary" icon size="sm" aria-label="Menos" :disabled="!units[line.id]" @click="units[line.id]--">−</TpvButton>
                    <span class="c-stepper__value">{{ units[line.id] }}/{{ detail.remaining[line.id] }}</span>
                    <TpvButton variant="secondary" icon size="sm" aria-label="Más" :disabled="units[line.id] >= detail.remaining[line.id]" @click="units[line.id]++">+</TpvButton>
                </span>
            </div>
            <p class="v-config__question">Motivo</p>
            <div class="c-options">
                <button v-for="r in reasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': reason === r }]" @click="reason = r">{{ r }}</button>
            </div>
            <p class="v-config__question">Devolver por</p>
            <div class="c-chips">
                <button v-for="m in methods" :key="m.value" type="button" :class="['c-option', { 'c-option--selected': refund === m.value }]" @click="refund = m.value">{{ m.label }}</button>
            </div>
        </div>
        <template #footer>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton variant="danger" block :disabled="!reason || !total" :loading="busy" @click="submit">
                Anular {{ total }} {{ total === 1 ? 'unidad' : 'unidades' }}
            </TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { isCancelled, useAuthorizeStore } from '@/stores/authorize';
import { useNoticesStore } from '@/stores/notices';
import { money } from '@/utils/format';

const props = defineProps({ ticket: { type: Object, default: null } });
const emit = defineEmits(['close', 'done']);

const authorize = useAuthorizeStore();
const notices = useNoticesStore();
const detail = ref(null);
const units = reactive({});
const reason = ref('');
const refund = ref('efectivo');
const busy = ref(false);
const reasons = ['Error al cobrar', 'Cliente devuelve producto', 'Producto en mal estado', 'Ticket duplicado'];
const methods = [{ value: 'efectivo', label: 'Efectivo' }, { value: 'tarjeta', label: 'Tarjeta' }, { value: 'bizum', label: 'Bizum' }, { value: 'otro', label: 'Otro' }];

const roots = computed(() => (detail.value?.lines ?? []).filter((l) => !l.parent_line_id));
const total = computed(() => Object.values(units).reduce((t, n) => t + n, 0));

watch(() => props.ticket?.id, async (id) => {
    detail.value = null;
    reason.value = '';
    Object.keys(units).forEach((k) => delete units[k]);
    if (!id) return;
    detail.value = await pos.ticket(id);
    // Por defecto todo lo pendiente (anulación total).
    for (const line of roots.value) units[line.id] = detail.value.remaining[line.id] ?? 0;
    refund.value = detail.value.payments[0]?.method ?? 'efectivo';
});

async function submit() {
    busy.value = true;
    try {
        const all = roots.value.every((l) => units[l.id] === detail.value.remaining[l.id]);
        const rect = await authorize.withAuthorization('anular_ticket', (token) => pos.rectify(props.ticket.id, {
            reason: reason.value,
            lines: all ? null : { ...units },
            refund_method: refund.value,
            authorization_token: token,
        }));
        notices.success(`Emitido ${rect.number} (${money(rect.total)})`);
        emit('done');
    } catch (e) {
        if (!isCancelled(e)) notices.error(errorMessage(e));
    } finally {
        busy.value = false;
    }
}
</script>
