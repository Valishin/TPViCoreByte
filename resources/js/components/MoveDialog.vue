<template>
    <ModalDialog :open="open" title="Mover a otra mesa" @close="$emit('close')">
        <p v-if="!floor.freeTables.length" class="c-modal__text">No hay mesas libres.</p>
        <template v-for="room in rooms" :key="room.name">
            <p class="c-modal__text"><strong>{{ room.name }}</strong></p>
            <div class="c-options" style="margin-bottom: 1rem">
                <button v-for="t in room.tables" :key="t.id" type="button" class="c-option" @click="$emit('confirm', t.id)">
                    Mesa {{ t.name }} <span class="c-option__extra">{{ t.seats }} plazas</span>
                </button>
            </div>
        </template>
        <template #actions>
            <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed } from 'vue';
import ModalDialog from './ModalDialog.vue';
import TpvButton from './TpvButton.vue';
import { useFloorStore } from '@/stores/floor';

defineProps({ open: { type: Boolean, default: false } });
defineEmits(['confirm', 'close']);

const floor = useFloorStore();
const rooms = computed(() =>
    floor.rooms
        .map((r) => ({ name: r.name, tables: r.tables.filter((t) => t.status === 'libre') }))
        .filter((r) => r.tables.length),
);
</script>
