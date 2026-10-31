<template>
    <!-- Caja: plano según pos_x / pos_y, con líneas entre las mesas unidas -->
    <section v-if="ui.isPos" class="c-floor-plan" :style="{ '--cols': cols, '--rows': rows }">
        <svg class="c-floor-plan__links" :viewBox="`0 0 ${cols} ${rows}`" preserveAspectRatio="none" aria-hidden="true">
            <line
                v-for="l in links"
                :key="l.key"
                :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2"
                :style="{ stroke: l.color }"
                class="c-floor-plan__link"
            />
        </svg>
        <TableCard
            v-for="t in tables"
            :key="t.id"
            :table="t"
            :now="now"
            :style="planStyle(t)"
            :mark="markOf(t)"
            :note="noteOf(t)"
            :main-name="mainName(t)"
            @select="$emit('select', t)"
        />
    </section>

    <!-- Móvil: rejilla compacta (las mesas de un grupo, seguidas) -->
    <section v-else class="c-floor-plan c-floor-plan--grid">
        <TableCard
            v-for="t in sorted"
            :key="t.id"
            :table="t"
            :now="now"
            :mark="markOf(t)"
            :note="noteOf(t)"
            :main-name="mainName(t)"
            @select="$emit('select', t)"
        />
    </section>
</template>

<script setup>
import { computed } from 'vue';
import TableCard from './TableCard.vue';
import { useUiStore } from '@/stores/ui';
import { groupLinks } from '@/utils/tableGroups';

const props = defineProps({
    tables: { type: Array, required: true },
    now: { type: Number, default: () => Date.now() },
    // Modo selección: (mesa) => { mark, note } o null
    marks: { type: Function, default: null },
    // Todas las mesas (de todas las salas) para el nombre de la principal.
    allTables: { type: Array, default: () => [] },
});
defineEmits(['select']);

const ui = useUiStore();

const byName = (a, b) => a.name.localeCompare(b.name, 'es', { numeric: true });
const sorted = computed(() =>
    [...props.tables].sort((a, b) => {
        // Las mesas de un grupo juntas, detrás de su principal.
        const ka = a.group ? props.tables.find((t) => t.id === a.group.main_table_id) ?? a : a;
        const kb = b.group ? props.tables.find((t) => t.id === b.group.main_table_id) ?? b : b;
        return byName(ka, kb) || (a.group?.is_main ? 0 : 1) - (b.group?.is_main ? 0 : 1) || byName(a, b);
    }),
);

// Cuadrícula del plano: cada mesa ocupa 2×2 celdas por unidad de tamaño.
const cols = computed(() => Math.max(6, ...props.tables.map((t) => t.x + t.w * 2 + 1)));
const rows = computed(() => Math.max(4, ...props.tables.map((t) => t.y + t.h * 2 + 1)));
const links = computed(() => groupLinks(props.tables));

function planStyle(t) {
    return {
        gridColumn: `${t.x + 1} / span ${t.w * 2}`,
        gridRow: `${t.y + 1} / span ${t.h * 2}`,
    };
}
const markOf = (t) => props.marks?.(t)?.mark ?? null;
const noteOf = (t) => props.marks?.(t)?.note ?? '';
function mainName(t) {
    if (!t.group || t.group.is_main) return '';
    const pool = props.allTables.length ? props.allTables : props.tables;
    return pool.find((m) => m.id === t.group.main_table_id)?.name ?? '';
}
</script>
