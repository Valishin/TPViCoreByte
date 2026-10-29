<template>
    <main class="v-login">
        <h1 class="v-login__brand">{{ locked ? 'Pantalla bloqueada' : 'TPV' }}</h1>

        <!-- En móvil, al elegir nombre el teclado sustituye a la lista -->
        <h2 v-if="ui.isPos || !selected" class="v-login__title">Seleccione el usuario</h2>
        <div v-if="ui.isPos || !selected" class="v-login__staff">
            <button
                v-for="p in staff"
                :key="p.id"
                type="button"
                :class="['v-login__person', { 'v-login__person--selected': selected?.id === p.id }]"
                @click="select(p)"
            >
                <span class="v-login__avatar" :style="{ background: p.color }">{{ p.initials }}</span>
                {{ p.name }}
            </button>
            <p v-if="loaded && !staff.length" class="v-login__hint">No hay camareros con PIN. Créalos en el backoffice (/admin).</p>
        </div>

        <div v-if="selected || ui.isPos" class="v-login__pad">
            <h2 class="v-login__title">Introduce tu PIN</h2>
            <p v-if="selected" class="v-login__who">
                {{ selected.name }}
                <TpvButton v-if="!ui.isPos" variant="ghost" size="sm" @click="select(null)">Cambiar</TpvButton>
            </p>
            <NumericKeypad v-model="pin" masked :length="4" :error="!!error" :disabled="!selected" @complete="submit" />
            <p class="v-login__error" role="alert">{{ error }}</p>
        </div>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NumericKeypad from '@/components/NumericKeypad.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useSessionStore } from '@/stores/session';
import { useUiStore } from '@/stores/ui';
import { useOrderStore } from '@/stores/order';

const router = useRouter();
const route = useRoute();
const session = useSessionStore();
const ui = useUiStore();
const order = useOrderStore();

const staff = ref([]);
const loaded = ref(false);
const selected = ref(null);
const pin = ref('');
const error = ref('');
const locked = computed(() => !!session.lockedBy);

onMounted(async () => {
    try {
        staff.value = await pos.staff();
    } finally {
        loaded.value = true;
    }
    // Tras un bloqueo, preseleccionar al camarero que estaba.
    if (session.lockedBy) selected.value = staff.value.find((p) => p.id === session.lockedBy.userId) ?? null;
});

function select(person) {
    selected.value = person;
    pin.value = '';
    error.value = '';
}

async function submit() {
    if (!selected.value) {
        error.value = 'Primero toca tu nombre.';
        pin.value = '';
        return;
    }
    error.value = '';
    try {
        const { resume, sameUser } = await session.login(selected.value.id, pin.value);
        if (!sameUser) order.clear(); // otro camarero: no se hereda la comanda a medias
        router.replace(resume ?? route.query.redirect ?? '/mesas');
    } catch (e) {
        error.value = errorMessage(e);
        pin.value = '';
    }
}
</script>
