<template>
    <!-- Producto personalizado (fuera de la carta): nombre y precio a mano; «Varios» rellena el nombre. -->
    <ModalDialog :open="open" :title="`Personalizado${category ? ` · ${category.name}` : ''}`" wide @close="$emit('close')">
        <div class="c-custom-product">
            <div class="c-custom-product__name">
                <TextField v-model="name" label="Nombre del producto" :maxlength="120" placeholder="Tarta de cumpleaños" />
                <TpvButton variant="secondary" @click="name = 'Varios'">Varios</TpvButton>
            </div>
            <AmountKeypad v-model="price" label="Precio" split />
        </div>
        <template #actions>
            <TpvButton variant="ghost" size="lg" @click="$emit('close')">Cancelar</TpvButton>
            <TpvButton variant="primary" size="lg" :disabled="!valid" :loading="busy" @click="$emit('add', { name: name.trim(), price })">
                Añadir {{ price ? money(price) : '' }}
            </TpvButton>
        </template>
    </ModalDialog>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AmountKeypad from './AmountKeypad.vue';
import ModalDialog from './ModalDialog.vue';
import TextField from './TextField.vue';
import TpvButton from './TpvButton.vue';
import { money } from '@/utils/format';

const props = defineProps({
    open: { type: Boolean, default: false },
    category: { type: Object, default: null },
    busy: { type: Boolean, default: false },
});
defineEmits(['add', 'close']);

const name = ref('');
const price = ref(0);
watch(() => props.open, (open) => {
    if (!open) return;
    name.value = '';
    price.value = 0;
});
const valid = computed(() => name.value.trim() !== '' && price.value > 0);
</script>
