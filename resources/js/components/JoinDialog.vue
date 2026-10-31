<template>
    <Teleport to="body">
        <div v-if="open" class="c-join" role="dialog" aria-modal="true" aria-label="Unir mesas">
            <header class="c-join__header">
                <h2 class="c-join__title">Unir a {{ order?.table_label }}</h2>
                <nav v-if="floor.rooms.length > 1" class="c-tabs" aria-label="Salas">
                    <button
                        v-for="room in floor.rooms"
                        :key="room.id"
                        type="button"
                        :class="['c-tabs__tab', { 'c-tabs__tab--active': room.id === roomId }]"
                        @click="roomId = room.id"
                    >
                        {{ room.name }}<template v-if="selectedIn(room)"> · {{ selectedIn(room) }}</template>
                    </button>
                </nav>
                <p class="c-join__hint">Toca las mesas que quieres unir (libres u ocupadas).</p>
            </header>

            <div class="c-join__plan">
                <FloorPlan v-if="room" :tables="room.tables" :all-tables="floor.tables" :now="now" :marks="marks" @select="toggle" />
            </div>

            <footer class="c-join__footer">
                <TpvButton variant="ghost" size="lg" @click="close">Cancelar</TpvButton>
                <TpvButton variant="primary" size="lg" block :disabled="!selected.length" :loading="busy" @click="next">
                    Unir ({{ selected.length }})
                </TpvButton>
            </footer>

            <!-- Mesas ocupadas: qué pasa, qué cuenta y avisos -->
            <ModalDialog :open="confirming" title="¿Unir estas mesas?" @close="confirming = false">
                <div v-for="t in occupied" :key="t.id" class="c-join__table">
                    <p class="c-join__table-name">Mesa {{ t.name }}</p>
                    <template v-if="t.orders.length > 1">
                        <p class="c-modal__text">Tiene {{ t.orders.length }} cuentas: ¿cuál unes?</p>
                        <div class="c-options c-join__accounts">
                            <button
                                v-for="(acc, i) in t.orders"
                                :key="acc.id"
                                type="button"
                                :class="['c-option', { 'c-option--selected': choice[t.id] === acc.id }]"
                                @click="choice[t.id] = acc.id"
                            >
                                <span>Cuenta {{ i + 1 }} <span class="c-option__extra">{{ acc.summary || 'Sin productos' }}</span></span>
                                <strong>{{ money(acc.total) }}</strong>
                            </button>
                            <button type="button" :class="['c-option', { 'c-option--selected': choice[t.id] === 'all' }]" @click="choice[t.id] = 'all'">
                                Unir todas las cuentas <strong>{{ money(t.total) }}</strong>
                            </button>
                        </div>
                        <p v-if="choice[t.id] && choice[t.id] !== 'all'" class="c-join__note">
                            La mesa {{ t.name }} sigue abierta con su otra cuenta.
                        </p>
                    </template>
                    <p v-else class="c-modal__text">
                        {{ t.order.summary || 'Sin productos' }} · <strong>{{ money(t.order.total) }}</strong>
                        <template v-if="t.order.guests"> · {{ t.order.guests }} comensales</template>
                    </p>
                    <p v-if="prechecked(t)" class="c-join__warning">
                        ⚠ La mesa {{ t.name }} ya tiene la cuenta impresa. Se guarda tal cual; pide la cuenta del grupo de nuevo.
                    </p>
                </div>
                <p class="c-modal__text">
                    Sus productos pasan a {{ order?.table_label }} (lo ya enviado no se vuelve a imprimir) y se suman los comensales.
                </p>
                <p v-if="order?.precheck_printed" class="c-join__warning">⚠ Esta mesa ya tenía la cuenta impresa: habrá que pedirla de nuevo.</p>
                <template #actions>
                    <TpvButton variant="ghost" @click="confirming = false">Volver</TpvButton>
                    <TpvButton variant="primary" :disabled="!choicesReady" :loading="busy" @click="submit">Unir</TpvButton>
                </template>
            </ModalDialog>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import FloorPlan from './FloorPlan.vue';
import ModalDialog from './ModalDialog.vue';
import TpvButton from './TpvButton.vue';
import { useFloorStore } from '@/stores/floor';
import { money } from '@/utils/format';

const props = defineProps({
    open: { type: Boolean, default: false },
    order: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'close']);

const floor = useFloorStore();
const roomId = ref(null);
const selectedIds = ref([]);
const confirming = ref(false);
const choice = reactive({});
const now = ref(Date.now());

watch(() => props.open, (open) => {
    if (!open) return;
    selectedIds.value = [];
    confirming.value = false;
    Object.keys(choice).forEach((k) => delete choice[k]);
    now.value = Date.now();
    // Empieza en la sala de la mesa actual.
    roomId.value = floor.rooms.find((r) => r.tables.some((t) => t.id === props.order?.table?.id))?.id ?? floor.rooms[0]?.id;
    floor.load().catch(() => {});
});
// Si otra pantalla ocupa o une una mesa elegida, deja de estar disponible.
watch(() => floor.rooms, () => {
    selectedIds.value = selectedIds.value.filter((id) => {
        const t = floor.tables.find((x) => x.id === id);
        return t && !blocked(t);
    });
});

const room = computed(() => floor.rooms.find((r) => r.id === roomId.value) ?? floor.rooms[0]);
const currentIds = computed(() => (props.order?.tables ?? []).map((t) => t.id));
const selected = computed(() => floor.tables.filter((t) => selectedIds.value.includes(t.id)));
const occupied = computed(() => selected.value.filter((t) => (t.orders?.length ?? 0) > 0));
const choicesReady = computed(() => occupied.value.every((t) => t.orders.length <= 1 || choice[t.id]));
const selectedIn = (r) => r.tables.filter((t) => selectedIds.value.includes(t.id)).length;

/** Por qué no se puede elegir (null = se puede). */
function blocked(t) {
    if (currentIds.value.includes(t.id)) return 'current';
    if (t.status === 'fuera_servicio') return 'Fuera de servicio';
    if (t.group && t.group.order_id !== props.order?.id) return t.group.is_main ? 'Tiene mesas unidas' : `Unida a ${t.group.label}`;
    return null;
}
function marks(t) {
    const reason = blocked(t);
    if (reason === 'current') return { mark: 'current' };
    if (reason) return { mark: 'disabled', note: reason };
    return { mark: selectedIds.value.includes(t.id) ? 'selected' : 'selectable' };
}
function toggle(t) {
    if (blocked(t)) return;
    const i = selectedIds.value.indexOf(t.id);
    if (i >= 0) selectedIds.value.splice(i, 1);
    else selectedIds.value.push(t.id);
}
const prechecked = (t) => {
    const chosen = choice[t.id];
    const accounts = t.orders.length > 1 && chosen && chosen !== 'all' ? t.orders.filter((o) => o.id === chosen) : t.orders;
    return accounts.some((o) => o.precheck_printed);
};

function next() {
    if (occupied.value.length) confirming.value = true;
    else submit();
}
function submit() {
    const accounts = {};
    for (const t of occupied.value) {
        if (t.orders.length > 1) accounts[t.id] = choice[t.id] === 'all' ? 'all' : [choice[t.id]];
    }
    emit('confirm', { table_ids: [...selectedIds.value], accounts });
}
function close() {
    confirming.value = false;
    emit('close');
}
</script>
