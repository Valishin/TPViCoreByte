<template>
    <ModalDialog :open="!!store.request" title="¿Seguro?" danger @close="store.answer(false)">
        <p class="c-amount-confirm__value" data-layer-focus tabindex="-1">{{ money(store.request?.cents ?? 0) }}</p>
        <p class="c-modal__text">
            Es más de lo habitual para {{ store.request?.label }} (hasta {{ money(store.request?.max ?? 0) }}).
            Comprueba que no sobra ningún cero.
        </p>
        <template #actions>
            <TpvButton variant="secondary" size="lg" @click="store.answer(false)">Corregir</TpvButton>
            <TpvButton variant="danger" size="lg" @click="store.answer(true)">Sí, es correcto</TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import ModalDialog from './ModalDialog.vue';
import TpvButton from './TpvButton.vue';
import { useAmountGuardStore } from '@/stores/amountGuard';
import { money } from '@/utils/format';

const store = useAmountGuardStore();
</script>
