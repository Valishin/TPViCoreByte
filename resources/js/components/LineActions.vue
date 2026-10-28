<template>
    <BottomSheet :open="!!line" :title="title" @close="close">
        <template v-if="line">
            <!-- Pendiente de enviar: cantidad, notas y anular sin preguntar -->
            <div v-if="pending" class="v-config__summary">
                <div class="v-config__row">
                    <strong>Cantidad</strong>
                    <span class="c-stepper">
                        <TpvButton variant="secondary" icon :disabled="line.quantity <= 1" aria-label="Menos" @click="setQty(line.quantity - 1)">−</TpvButton>
                        <span class="c-stepper__value">{{ line.quantity }}</span>
                        <TpvButton variant="secondary" icon aria-label="Más" @click="setQty(line.quantity + 1)">+</TpvButton>
                    </span>
                </div>
                <div class="c-chips">
                    <button v-for="n in quickNotes" :key="n" type="button" :class="['c-option', { 'c-option--selected': note === n }]" @click="saveNote(note === n ? '' : n)">{{ n }}</button>
                </div>
                <label class="c-field">
                    <span class="c-field__label">Nota para cocina</span>
                    <input v-model="note" class="c-field__input" maxlength="200" placeholder="Escribe una nota…" @change="saveNote(note)" />
                </label>
            </div>

            <!-- Ya enviada: solo anular, con motivo (única confirmación de la app) -->
            <div v-else-if="!line.voided_at" class="v-config__summary">
                <p class="v-config__rule">Ya se envió a cocina. Para cambiarla, anúlala (se imprime una ANULACIÓN) y añádela de nuevo.</p>
                <p class="v-config__question">Motivo de la anulación</p>
                <div class="c-options">
                    <button v-for="r in reasons" :key="r" type="button" :class="['c-option', { 'c-option--selected': reason === r }]" @click="reason = r">{{ r }}</button>
                </div>
                <label class="c-field">
                    <span class="c-field__label">Otro motivo</span>
                    <input v-model="reason" class="c-field__input" maxlength="120" />
                </label>
            </div>
            <p v-else class="v-config__rule">Línea anulada: {{ line.void_reason }}</p>
        </template>

        <template #footer>
            <TpvButton variant="ghost" @click="close">Cerrar</TpvButton>
            <TpvButton v-if="line && pending" variant="danger" block :loading="order.busy" @click="voidLine(null)">Quitar</TpvButton>
            <TpvButton
                v-else-if="line && !line.voided_at"
                variant="danger"
                block
                :disabled="!reason"
                :loading="order.busy"
                @click="voidLine(reason)"
            >Anular y avisar a cocina</TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TpvButton from './TpvButton.vue';
import { useOrderStore } from '@/stores/order';
import { useNoticesStore } from '@/stores/notices';
import { errorMessage } from '@/services/pos';

const props = defineProps({ lineId: { type: Number, default: null } });
const emit = defineEmits(['close']);

const order = useOrderStore();
const notices = useNoticesStore();
const note = ref('');
const reason = ref('');

const reasons = ['Error del camarero', 'Cliente cambia de idea', 'Producto agotado', 'Tarda demasiado'];
const quickNotes = ['Sin sal', 'Poco hecho', 'Muy hecho', 'Para compartir', 'Rápido', 'Sin gluten'];

// Siempre la versión actual del store (cambia tras cada acción).
const line = computed(() => order.lines.find((l) => l.id === props.lineId) ?? null);
const pending = computed(() => line.value && !line.value.sent_at && !line.value.voided_at);
const title = computed(() => (line.value ? `${line.value.quantity}× ${line.value.name}` : ''));

watch(() => props.lineId, () => {
    note.value = line.value?.notes ?? '';
    reason.value = '';
});

function close() {
    emit('close');
}
async function act(fn, done) {
    try {
        await fn();
        done?.();
    } catch (e) {
        notices.error(errorMessage(e));
    }
}
function setQty(quantity) {
    act(() => order.updateLine(line.value, { quantity }));
}
function saveNote(value) {
    note.value = value;
    act(() => order.updateLine(line.value, { notes: value || null }));
}
function voidLine(why) {
    act(() => order.voidLine(line.value, why), () => {
        notices.success(why ? 'Anulada: se ha avisado a cocina' : 'Quitada');
        close();
    });
}
</script>
