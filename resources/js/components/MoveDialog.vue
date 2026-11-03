<template>
    <Teleport to="body">
        <!-- Mismo plano que «Unir mesas»: se elige la mesa libre tocándola en el plano. -->
        <div v-if="open" ref="el" v-bind="layer.attrs.value" class="c-join" role="dialog" aria-modal="true" aria-label="Mover a otra mesa">
            <header class="c-join__header">
                <h2 class="c-join__title">Mover {{ order?.table_label ?? 'la mesa' }} a…</h2>
                <nav v-if="floor.rooms.length > 1" class="c-tabs" aria-label="Salas">
                    <button
                        v-for="r in floor.rooms"
                        :key="r.id"
                        type="button"
                        :class="['c-tabs__tab', { 'c-tabs__tab--active': r.id === roomId }]"
                        @click="roomId = r.id"
                    >
                        {{ r.name }}<template v-if="freeIn(r)"> · {{ freeIn(r) }} libres</template>
                    </button>
                </nav>
                <p class="c-join__hint">{{ floor.freeTables.length ? 'Toca la mesa libre a la que lo pasas.' : 'No hay mesas libres.' }}</p>
            </header>

            <div class="c-join__plan">
                <FloorPlan v-if="room" :tables="room.tables" :all-tables="floor.tables" :now="now" :marks="marks" @select="choose" />
            </div>

            <footer class="c-join__footer">
                <TpvButton variant="ghost" size="lg" @click="$emit('close')">Cancelar</TpvButton>
                <TpvButton variant="primary" size="lg" block :disabled="!target" :loading="busy" @click="$emit('confirm', target.id)">
                    {{ target ? `Mover a Mesa ${target.name}` : 'Elige una mesa libre' }}
                </TpvButton>
            </footer>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, ref, toRef, watch } from 'vue';
import { useLayer } from '@/composables/useLayer';
import FloorPlan from './FloorPlan.vue';
import TpvButton from './TpvButton.vue';
import { useFloorStore } from '@/stores/floor';

/** Mover el pedido a otra mesa: el plano de la sala (como «Unir mesas») y se toca la mesa libre. */
const props = defineProps({
    open: { type: Boolean, default: false },
    order: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
defineEmits(['confirm', 'close']);

const layer = useLayer(toRef(props, 'open'));
const el = layer.el;

const floor = useFloorStore();
const roomId = ref(null);
const targetId = ref(null);
const now = ref(Date.now());

watch(() => props.open, (open) => {
    if (!open) return;
    targetId.value = null;
    now.value = Date.now();
    // Empieza en la sala de la mesa actual.
    roomId.value = floor.rooms.find((r) => r.tables.some((t) => t.id === props.order?.table?.id))?.id ?? floor.rooms[0]?.id;
    floor.load().catch(() => {});
});
// Si otra pantalla ocupa la mesa elegida, deja de estar elegida.
watch(() => floor.rooms, () => {
    if (target.value && target.value.status !== 'libre') targetId.value = null;
});

const room = computed(() => floor.rooms.find((r) => r.id === roomId.value) ?? floor.rooms[0]);
const currentIds = computed(() => (props.order?.tables ?? [props.order?.table]).filter(Boolean).map((t) => t.id));
const target = computed(() => floor.tables.find((t) => t.id === targetId.value) ?? null);
const freeIn = (r) => r.tables.filter((t) => t.status === 'libre').length;

function marks(t) {
    if (currentIds.value.includes(t.id)) return { mark: 'current' };
    if (t.status === 'fuera_servicio') return { mark: 'disabled', note: 'Fuera de servicio' };
    if (t.status !== 'libre') return { mark: 'disabled', note: 'Ocupada' };
    return { mark: t.id === targetId.value ? 'selected' : 'selectable' };
}
function choose(t) {
    if (marks(t).mark === 'disabled' || marks(t).mark === 'current') return;
    targetId.value = targetId.value === t.id ? null : t.id;
}
</script>
