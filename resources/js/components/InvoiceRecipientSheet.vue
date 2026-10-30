<template>
    <BottomSheet :open="open" :title="form ? (form.id ? 'Completar datos fiscales' : 'Nuevo cliente para factura') : title" @close="$emit('close')">
        <!-- Buscar. Con el teclado en pantalla abierto, lo que se escribe y el aviso se
             ven en la franja del teclado: el campo sale de la vista (sigue enfocado) y
             quedan los 3 mejores resultados encima del teclado, sin pie. Se toca el
             cliente sin pulsar "Hecho". -->
        <div v-if="!form" :class="['c-recipient', { 'c-recipient--typing': typing }]">
            <p v-if="hint && !typing" class="c-alert c-alert--warning"><span class="c-alert__text">{{ hint }}</span></p>
            <div class="c-recipient__search">
                <TextField v-model="query" label="Buscar por NIF o nombre" placeholder="B12345674 o Restaurante…" autocomplete="off" :hint="searchHint" />
            </div>
            <p v-if="typing && query.trim().length < 2" class="v-config__rule">Escribe el NIF o el nombre: los resultados salen aquí.</p>
            <div class="c-recipient__results">
                <button v-for="c in shown" :key="c.id" type="button" class="c-recipient__result" @click="pick(c)">
                    <strong>{{ c.legal_name || c.name }}</strong>
                    <span>{{ c.tax_id || 'Sin NIF' }}<template v-if="c.fiscal_address"> · {{ c.fiscal_address }}, {{ c.fiscal_city }}</template></span>
                    <small v-if="!c.has_fiscal_data" class="c-recipient__missing">Faltan datos fiscales: tócalo para completarlos</small>
                </button>
                <button v-if="typing && query.trim().length >= 2 && !searching && shown.length < MAX_WHILE_TYPING" type="button" class="c-recipient__result c-recipient__result--new" @click="newCustomer">
                    <strong>+ Nuevo cliente «{{ query.trim() }}»</strong>
                </button>
            </div>
        </div>

        <!-- Alta rápida / completar -->
        <form v-else class="c-recipient__form" @submit.prevent="save">
            <TextField v-model="form.legal_name" class="c-recipient__wide" label="Nombre o razón social *" :maxlength="255" autocomplete="off" />
            <!-- La validación sale junto al campo y en el teclado en pantalla mientras se teclea -->
            <TextField
                v-model="form.tax_id"
                class="c-recipient__wide"
                label="NIF / NIE / CIF / NIF-IVA *"
                :maxlength="30"
                autocomplete="off"
                :error="taxError || ''"
                :hint="form.tax_id && !taxError ? `✓ ${taxIdLabels[passport ? 'otro' : detected]} válido` : ''"
            />
            <label class="v-checkout__toggle c-recipient__wide">
                <input v-model="passport" type="checkbox" /> Es otro documento (pasaporte, extranjero sin NIF-IVA)
            </label>
            <TextField v-model="form.fiscal_address" class="c-recipient__wide" :label="`Domicilio${required ? ' *' : ''}`" :maxlength="255" autocomplete="off" />
            <TextField v-model="form.fiscal_postal_code" :label="`Código postal${required ? ' *' : ''}`" keyboard="numeric" :maxlength="10" autocomplete="off" />
            <TextField v-model="form.fiscal_city" :label="`Población${required ? ' *' : ''}`" :maxlength="255" autocomplete="off" />
            <TextField v-model="form.fiscal_province" label="Provincia" :maxlength="255" autocomplete="off" />
            <TextField v-model="form.fiscal_country" label="País (ES, FR…)" :maxlength="2" autocomplete="off" />
            <TextField v-model="form.billing_email" class="c-recipient__wide" label="Email para la factura" type="email" :maxlength="255" autocomplete="off" />
            <label v-if="form.billing_email" class="v-checkout__toggle c-recipient__wide">
                <input v-model="form.accepts_email_invoices" type="checkbox" /> El cliente acepta recibir las facturas por email
            </label>
            <p v-if="error" class="c-alert c-alert--error c-recipient__wide"><span class="c-alert__text">{{ error }}</span></p>
        </form>

        <template v-if="form || !typing" #footer>
            <template v-if="!form">
                <TpvButton variant="ghost" @click="$emit('close')">Cancelar</TpvButton>
                <TpvButton variant="primary" block @click="newCustomer">+ Nuevo cliente con datos fiscales</TpvButton>
            </template>
            <template v-else>
                <TpvButton variant="ghost" @click="form = null">← Buscar</TpvButton>
                <TpvButton variant="success" block :disabled="!canSave" :loading="busy" @click="save">Guardar y usar en la factura</TpvButton>
            </template>
        </template>
    </BottomSheet>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import TextField from './TextField.vue';
import TpvButton from './TpvButton.vue';
import { keyboard, keyboardEnabled } from '@/composables/useOnScreenKeyboard';
import pos, { errorMessage } from '@/services/pos';
import { useSessionStore } from '@/stores/session';
import { detectTaxId, normalizeTaxId, taxIdError, taxIdLabels } from '@/utils/taxid';

/**
 * Destinatario de una factura completa (art. 6.1.c-e RD 1619/2012): buscar un
 * cliente por NIF o nombre, o darlo de alta rápido (con el teclado en pantalla
 * en caja). Emite "select" con el cliente ya con sus datos fiscales completos.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Factura con datos del cliente' },
    hint: { type: String, default: '' },
    initialQuery: { type: String, default: '' },
});
const emit = defineEmits(['select', 'close']);

const session = useSessionStore();
const required = computed(() => session.settings?.invoice_require_address ?? true);
const query = ref('');
const results = ref([]);
const searching = ref(false);
const form = ref(null);
const passport = ref(false);
const busy = ref(false);
const error = ref('');

// Teclado en pantalla abierto (caja): la lista se reduce a lo que cabe encima.
const typing = computed(() => keyboardEnabled.value && !!keyboard.target && !keyboard.hidden);
const MAX_WHILE_TYPING = 3;
const shown = computed(() => (typing.value ? results.value.slice(0, MAX_WHILE_TYPING) : results.value));
const searchHint = computed(() => {
    if (query.value.trim().length < 2) return '';
    if (searching.value) return 'Buscando…';
    if (!results.value.length) return 'Ningún cliente con ese NIF o nombre';
    const more = results.value.length - shown.value.length;
    return more > 0 ? `${results.value.length} encontrados (se ven ${shown.value.length}: afina la búsqueda)` : `${results.value.length} encontrado${results.value.length > 1 ? 's' : ''}`;
});
const detected = computed(() => detectTaxId(form.value?.tax_id));
const taxError = computed(() => (form.value?.tax_id ? taxIdError(form.value.tax_id, passport.value ? 'otro' : null) : null));
const canSave = computed(() => {
    const f = form.value;
    if (!f || !f.legal_name?.trim() || !f.tax_id || taxError.value) return false;
    return !required.value || (f.fiscal_address?.trim() && f.fiscal_postal_code?.trim() && f.fiscal_city?.trim());
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.value = null;
    error.value = '';
    results.value = [];
    query.value = props.initialQuery;
});
watch(passport, (v) => form.value && (form.value.tax_id_type = v ? 'otro' : null));

let timer = null;
let seq = 0;
watch(query, (q) => {
    clearTimeout(timer);
    if (q.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const mine = ++seq;
        searching.value = true;
        try {
            const found = await pos.billingCustomers(q.trim());
            if (mine === seq) results.value = found;
        } catch {
            if (mine === seq) results.value = [];
        } finally {
            if (mine === seq) searching.value = false;
        }
    }, 250);
});

function blank() {
    return {
        id: null, legal_name: '', tax_id: '', tax_id_type: null, fiscal_address: '', fiscal_postal_code: '', fiscal_city: '',
        fiscal_province: '', fiscal_country: 'ES', billing_email: '', accepts_email_invoices: false,
    };
}
function newCustomer() {
    const f = blank();
    // Lo buscado se aprovecha: si parece un NIF va al NIF; si no, al nombre.
    const q = query.value.trim();
    if (q && detectTaxId(q)) f.tax_id = normalizeTaxId(q);
    else f.legal_name = q;
    passport.value = false;
    form.value = f;
    error.value = '';
}
function pick(c) {
    if (c.has_fiscal_data) return emit('select', c);
    // Cliente de reparto sin datos fiscales: se completan.
    form.value = { ...blank(), ...Object.fromEntries(Object.entries(c).filter(([, v]) => v !== null)), legal_name: c.legal_name || c.name || '' };
    passport.value = c.tax_id_type === 'otro';
    error.value = '';
}

async function save() {
    if (!canSave.value) return;
    busy.value = true;
    error.value = '';
    const f = form.value;
    const payload = {
        legal_name: f.legal_name.trim(),
        tax_id: normalizeTaxId(f.tax_id),
        tax_id_type: passport.value ? 'otro' : null,
        fiscal_address: f.fiscal_address || null,
        fiscal_postal_code: f.fiscal_postal_code || null,
        fiscal_city: f.fiscal_city || null,
        fiscal_province: f.fiscal_province || null,
        fiscal_country: (f.fiscal_country || 'ES').toUpperCase(),
        billing_email: f.billing_email || null,
        accepts_email_invoices: !!(f.billing_email && f.accepts_email_invoices),
    };
    try {
        const saved = f.id ? await pos.updateBillingCustomer(f.id, payload) : await pos.createBillingCustomer(payload);
        emit('select', saved);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>
