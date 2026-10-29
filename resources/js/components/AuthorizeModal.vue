<template>
    <ModalDialog :open="!!store.request" title="Autorización de encargado" :text="store.request?.label" @close="cancel">
        <div v-if="!managers.length && loaded" class="c-modal__text">No hay encargados con PIN.</div>
        <div class="c-options" style="margin-bottom: 1rem">
            <button
                v-for="m in managers"
                :key="m.id"
                type="button"
                :class="['c-option', { 'c-option--selected': selected?.id === m.id }]"
                @click="selected = m; pin = ''; error = ''"
            >
                <span class="c-topbar__avatar" :style="{ background: m.color }">{{ m.initials }}</span>
                {{ m.name }}
            </button>
        </div>
        <NumericKeypad v-model="pin" masked :length="4" :error="!!error" :disabled="!selected || busy" @complete="submit" />
        <p class="v-login__error" role="alert">{{ error }}</p>
        <template #actions>
            <TpvButton variant="ghost" @click="cancel">Cancelar</TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import ModalDialog from './ModalDialog.vue';
import NumericKeypad from './NumericKeypad.vue';
import TpvButton from './TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useAuthorizeStore } from '@/stores/authorize';

const store = useAuthorizeStore();
const managers = ref([]);
const loaded = ref(false);
const selected = ref(null);
const pin = ref('');
const error = ref('');
const busy = ref(false);

watch(() => store.request, async (request) => {
    selected.value = null;
    pin.value = '';
    error.value = '';
    if (request) {
        managers.value = await pos.managers().catch(() => []);
        loaded.value = true;
        if (managers.value.length === 1) selected.value = managers.value[0];
    }
});

async function submit() {
    busy.value = true;
    try {
        await store.grant(selected.value.id, pin.value);
    } catch (e) {
        error.value = errorMessage(e);
        pin.value = '';
    } finally {
        busy.value = false;
    }
}
function cancel() {
    store.cancel();
}
</script>
