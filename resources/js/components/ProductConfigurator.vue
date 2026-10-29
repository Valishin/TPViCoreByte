<template>
    <BottomSheet :open="!!product && !!d" :title="product?.name" @close="$emit('close')">
        <div v-if="product && d" class="v-config">
            <div class="v-config__steps" aria-hidden="true">
                <span
                    v-for="(s, i) in steps"
                    :key="s.key"
                    :class="['v-config__step', { 'v-config__step--done': i < d.step, 'v-config__step--current': i === d.step }]"
                ></span>
            </div>

            <!-- Tamaño / variante -->
            <section v-if="step.key === 'variant'">
                <p class="v-config__question">Elige tamaño</p>
                <div class="c-options">
                    <button
                        v-for="v in product.variants"
                        :key="v.id"
                        type="button"
                        :class="['c-option', { 'c-option--selected': d.variantId === v.id }]"
                        @click="pickVariant(v)"
                    >
                        {{ v.name }} <span class="c-option__extra">{{ money(v.price) }}</span>
                    </button>
                </div>
            </section>

            <!-- Entera o mitad y mitad -->
            <section v-else-if="step.key === 'half'">
                <p class="v-config__question">¿Entera o mitad y mitad?</p>
                <div class="c-options" style="margin-bottom: 1rem">
                    <button type="button" :class="['c-option', { 'c-option--selected': d.halfMode === 'whole' }]" @click="pickWhole">
                        Entera <span class="c-option__extra">{{ product.name }}</span>
                    </button>
                </div>
                <p class="v-config__rule">Mitad {{ product.name }} y mitad…</p>
                <div class="c-options">
                    <button
                        v-for="p in halfCandidates"
                        :key="p.id"
                        type="button"
                        :class="['c-option', { 'c-option--selected': d.halfMode === 'half' && d.halfProductId === p.id }]"
                        @click="pickHalf(p)"
                    >
                        {{ p.name }} <span class="c-option__extra">{{ money(variantFor(p)?.price) }}</span>
                    </button>
                </div>
            </section>

            <!-- Modificadores del producto o de la segunda mitad -->
            <section v-else-if="step.key === 'modifiers' || step.key === 'halfModifiers'">
                <p v-if="step.key === 'halfModifiers'" class="v-config__question">Mitad {{ halfProduct?.name }}</p>
                <div v-for="g in step.groups" :key="g.id" class="v-config__section">
                    <p class="v-config__question">{{ g.name }}</p>
                    <p :class="['v-config__rule', { 'v-config__rule--error': showErrors && !groupValid(g, selection(step.key)[g.id]) }]">{{ rule(g) }}</p>
                    <div class="c-options">
                        <button
                            v-for="m in g.modifiers"
                            :key="m.id"
                            type="button"
                            :class="['c-option', { 'c-option--selected': has(selection(step.key), g.id, m.id) }]"
                            @click="toggle(selection(step.key), g, m.id)"
                        >
                            {{ m.name }} <span class="c-option__extra">{{ delta(modPrice(m)) }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Grupo de un menú -->
            <section v-else-if="step.key.startsWith('menu:')">
                <p class="v-config__question">{{ step.group.name }}</p>
                <p :class="['v-config__rule', { 'v-config__rule--error': showErrors && !menuGroupValid(step.group) }]">{{ rule(step.group) }}</p>
                <div class="c-options">
                    <button
                        v-for="item in step.group.items"
                        :key="item.id"
                        type="button"
                        :class="['c-option', { 'c-option--selected': countItem(step.group, item) > 0, 'c-option--disabled': !itemProduct(item)?.available }]"
                        :disabled="!itemProduct(item)?.available"
                        @click="pickMenuItem(step.group, item)"
                    >
                        {{ itemName(item) }}
                        <span v-if="countItem(step.group, item) > 1" class="c-option__count">{{ countItem(step.group, item) }}</span>
                        <span v-else class="c-option__extra">{{ item.supplement ? delta(item.supplement) : '' }}</span>
                    </button>
                </div>
                <!-- Modificadores del producto elegido (p. ej. punto de la carne) -->
                <template v-for="(choice, idx) in d.menu[step.group.id] ?? []" :key="idx">
                    <div v-for="g in catalog.groupsOf(itemProduct(itemById(step.group, choice.itemId)))" :key="`${idx}-${g.id}`" class="v-config__section">
                        <p class="v-config__question">{{ itemName(itemById(step.group, choice.itemId)) }}: {{ g.name }}</p>
                        <p :class="['v-config__rule', { 'v-config__rule--error': showErrors && !groupValid(g, choice.modifiers[g.id]) }]">{{ rule(g) }}</p>
                        <div class="c-options">
                            <button
                                v-for="m in g.modifiers"
                                :key="m.id"
                                type="button"
                                :class="['c-option', { 'c-option--selected': has(choice.modifiers, g.id, m.id) }]"
                                @click="toggle(choice.modifiers, g, m.id)"
                            >
                                {{ m.name }} <span class="c-option__extra">{{ delta(m.price_delta) }}</span>
                            </button>
                        </div>
                    </div>
                </template>
            </section>

            <!-- Cantidad y notas -->
            <section v-else-if="step.key === 'final'" class="v-config__summary">
                <p class="v-config__question">{{ summary }}</p>
                <div class="v-config__row">
                    <strong>Cantidad</strong>
                    <span class="c-stepper">
                        <TpvButton variant="secondary" icon :disabled="d.quantity <= 1" aria-label="Menos" @click="d.quantity--">−</TpvButton>
                        <span class="c-stepper__value">{{ d.quantity }}</span>
                        <TpvButton variant="secondary" icon aria-label="Más" @click="d.quantity++">+</TpvButton>
                    </span>
                </div>
                <QuickNotes v-model="d.notes" :notes="quickNotes" />
            </section>
        </div>

        <template #footer>
            <TpvButton variant="ghost" @click="back">{{ d?.step > 0 ? 'Atrás' : 'Cancelar' }}</TpvButton>
            <TpvButton v-if="!isLast" variant="secondary" size="lg" @click="next">
                {{ allValid ? 'Cantidad / nota' : 'Siguiente' }}
            </TpvButton>
            <!-- Añadir en cuanto todo lo obligatorio está elegido, desde cualquier paso -->
            <TpvButton variant="primary" size="lg" block :loading="busy" :disabled="!allValid" @click="add">
                Añadir · {{ money(price) }}
            </TpvButton>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import QuickNotes from './QuickNotes.vue';
import TpvButton from './TpvButton.vue';
import { useCatalogStore } from '@/stores/catalog';
import { useOrderStore } from '@/stores/order';
import { delta, money } from '@/utils/format';

/**
 * Configurador paso a paso. El estado vive en order.draft para que no se
 * pierda al bloquear la pantalla (si vuelve el mismo camarero).
 */
const props = defineProps({
    product: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'close']);

const catalog = useCatalogStore();
const order = useOrderStore();
const showErrors = ref(false);
const quickNotes = computed(() => catalog.quickNotesFor(props.product?.id));

watch(
    () => props.product?.id,
    (id) => {
        showErrors.value = false;
        if (id && order.draft?.productId !== id) {
            order.draft = {
                productId: id, step: 0, variantId: null, halfMode: null, halfProductId: null,
                modifiers: {}, halfModifiers: {}, menu: {}, quantity: 1, notes: '',
            };
        }
    },
    { immediate: true },
);

const d = computed(() => (order.draft?.productId === props.product?.id ? order.draft : null));

const variant = computed(() => props.product?.variants.find((v) => v.id === d.value?.variantId) ?? null);
const halfProduct = computed(() => (d.value?.halfMode === 'half' ? catalog.productById(d.value.halfProductId) : null));
const halfCandidates = computed(() =>
    props.product ? catalog.halfCandidates(props.product).filter((p) => variantFor(p)) : [],
);

const steps = computed(() => {
    const p = props.product;
    if (!p || !d.value) return [];
    const list = [];
    if (p.variants.length) list.push({ key: 'variant' });
    if (p.allows_half_and_half && p.variants.length) list.push({ key: 'half' });
    const groups = catalog.groupsOf(p);
    if (groups.length) list.push({ key: 'modifiers', groups });
    if (halfProduct.value) {
        const hg = catalog.groupsOf(halfProduct.value);
        if (hg.length) list.push({ key: 'halfModifiers', groups: hg });
    }
    for (const g of p.menu_groups) list.push({ key: `menu:${g.id}`, group: g });
    list.push({ key: 'final' });
    return list;
});
const step = computed(() => steps.value[Math.min(d.value?.step ?? 0, steps.value.length - 1)] ?? { key: 'final' });
const isLast = computed(() => step.value.key === 'final');

// --- Selección -------------------------------------------------------
function selection(key) {
    return key === 'halfModifiers' ? d.value.halfModifiers : d.value.modifiers;
}
function has(sel, groupId, modId) {
    return (sel[groupId] ?? []).includes(modId);
}
function toggle(sel, group, modId) {
    const current = sel[group.id] ?? [];
    if (current.includes(modId)) sel[group.id] = current.filter((id) => id !== modId);
    else if (group.max === 1) sel[group.id] = [modId];
    else if (group.max === null || current.length < group.max) sel[group.id] = [...current, modId];
    // Paso de un único grupo de elección única: avanzar solo.
    if (step.value.groups?.length === 1 && group.max === 1 && sel[group.id].length === 1) autoAdvance();
}
function groupValid(group, ids = []) {
    const n = (ids ?? []).length;
    return n >= group.min && (group.max === null || n <= group.max);
}
function rule(g) {
    if (g.max === null) return g.min > 0 ? `Obligatorio: al menos ${g.min}` : 'Opcional';
    if (g.min === g.max) return g.min > 0 ? `Elige ${g.min}` : 'Opcional';
    return g.min > 0 ? `Elige de ${g.min} a ${g.max}` : `Opcional, hasta ${g.max}`;
}
function variantFor(p) {
    return variant.value ? p.variants.find((v) => v.code === variant.value.code) : null;
}
function modPrice(m) {
    const code = variant.value?.code;
    return code && m.variant_prices?.[code] !== undefined ? m.variant_prices[code] : m.price_delta;
}

function pickVariant(v) {
    d.value.variantId = v.id;
    // Si cambia el tamaño, la otra mitad debe tenerlo también.
    if (halfProduct.value && !variantFor(halfProduct.value)) {
        d.value.halfMode = null;
        d.value.halfProductId = null;
    }
    autoAdvance();
}
function pickWhole() {
    d.value.halfMode = 'whole';
    d.value.halfProductId = null;
    d.value.halfModifiers = {};
    autoAdvance();
}
function pickHalf(p) {
    d.value.halfMode = 'half';
    d.value.halfProductId = p.id;
    d.value.halfModifiers = {};
    autoAdvance();
}

// --- Menús -----------------------------------------------------------
function itemById(group, itemId) {
    return group.items.find((i) => i.id === itemId);
}
function itemProduct(item) {
    return item ? catalog.productById(item.product_id) : null;
}
function itemName(item) {
    const p = itemProduct(item);
    const v = p?.variants.find((x) => x.id === item?.variant_id);
    return p ? p.name + (v ? ` (${v.name})` : '') : '—';
}
function countItem(group, item) {
    return (d.value.menu[group.id] ?? []).filter((c) => c.itemId === item.id).length;
}
function pickMenuItem(group, item) {
    const chosen = d.value.menu[group.id] ?? [];
    if (group.max === 1) {
        d.value.menu[group.id] = [{ itemId: item.id, modifiers: {} }];
        if (!catalog.groupsOf(itemProduct(item)).length) autoAdvance();
        return;
    }
    if (chosen.length < group.max) {
        d.value.menu[group.id] = [...chosen, { itemId: item.id, modifiers: {} }];
    } else {
        // Lleno: tocar uno elegido lo quita.
        const idx = chosen.findIndex((c) => c.itemId === item.id);
        if (idx >= 0) d.value.menu[group.id] = chosen.filter((_, i) => i !== idx);
    }
}
function menuGroupValid(group) {
    const chosen = d.value.menu[group.id] ?? [];
    if (chosen.length < group.min || chosen.length > group.max) return false;
    return chosen.every((c) =>
        catalog.groupsOf(itemProduct(itemById(group, c.itemId))).every((g) => groupValid(g, c.modifiers[g.id])),
    );
}

// --- Validación y navegación ----------------------------------------
function stepValid(s) {
    switch (true) {
        case s.key === 'variant':
            return !!d.value.variantId;
        case s.key === 'half':
            return d.value.halfMode === 'whole' || (d.value.halfMode === 'half' && !!d.value.halfProductId);
        case s.key === 'modifiers' || s.key === 'halfModifiers':
            return s.groups.every((g) => groupValid(g, selection(s.key)[g.id]));
        case s.key.startsWith('menu:'):
            return menuGroupValid(s.group);
        default:
            return true;
    }
}
const allValid = computed(() => steps.value.every((s) => stepValid(s)));

function add() {
    if (!allValid.value) {
        // Ir al primer paso incompleto y marcarlo.
        d.value.step = steps.value.findIndex((s) => !stepValid(s));
        showErrors.value = true;
        return;
    }
    emit('add', payload());
}

function autoAdvance() {
    nextTick(() => {
        if (stepValid(step.value) && !isLast.value) d.value.step++;
    });
}
function next() {
    if (!stepValid(step.value)) {
        showErrors.value = true;
        return;
    }
    showErrors.value = false;
    if (!isLast.value) d.value.step++;
}
function back() {
    if (d.value?.step > 0) d.value.step--;
    else emit('close');
}

// --- Precio orientativo y envío -------------------------------------
const flat = (sel) => Object.values(sel).flat();
const modsTotal = (ids, groups, price = modPrice) =>
    groups.flatMap((g) => g.modifiers).filter((m) => ids.includes(m.id)).reduce((t, m) => t + price(m), 0);

const price = computed(() => {
    const p = props.product;
    if (!p || !d.value) return 0;
    let unit = variant.value?.price ?? p.price;
    if (halfProduct.value) unit = Math.max(unit, variantFor(halfProduct.value)?.price ?? 0);
    unit += modsTotal(flat(d.value.modifiers), catalog.groupsOf(p));
    if (halfProduct.value) unit += modsTotal(flat(d.value.halfModifiers), catalog.groupsOf(halfProduct.value));
    for (const g of p.menu_groups) {
        for (const c of d.value.menu[g.id] ?? []) {
            const item = itemById(g, c.itemId);
            unit += item?.supplement ?? 0;
            unit += modsTotal(flat(c.modifiers), catalog.groupsOf(itemProduct(item)), (m) => m.price_delta);
        }
    }
    return unit * d.value.quantity;
});

const summary = computed(() => {
    const p = props.product;
    if (!p) return '';
    const parts = [halfProduct.value ? `½ ${p.name} / ½ ${halfProduct.value.name}` : p.name];
    if (variant.value) parts.push(variant.value.name);
    return parts.join(' · ');
});

function payload() {
    const p = props.product;
    const body = {
        product_id: p.id,
        variant_id: d.value.variantId,
        modifier_ids: flat(d.value.modifiers),
        quantity: d.value.quantity,
        notes: d.value.notes || null,
    };
    if (halfProduct.value) body.half = { product_id: halfProduct.value.id, modifier_ids: flat(d.value.halfModifiers) };
    if (p.menu_groups.length) {
        body.menu_choices = p.menu_groups.flatMap((g) =>
            (d.value.menu[g.id] ?? []).map((c) => ({ item_id: c.itemId, modifier_ids: flat(c.modifiers) })),
        );
    }
    return body;
}
</script>
