<template>
    <main class="v-login">
        <h1 class="v-login__logo"><BrandMark :height="44" /></h1>

        <!-- Primero el usuario; al elegirlo, el teclado del PIN sustituye a la lista (caja y móvil) -->
        <h2 v-if="!selected" class="v-login__title">Seleccione el usuario</h2>
        <div v-if="!selected" class="v-login__staff">
            <button
                v-for="p in floorStaff"
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
        <!-- Repartidores aparte, debajo del personal de sala -->
        <template v-if="couriers.length && !selected">
            <h2 class="v-login__title v-login__title--section">Repartidores</h2>
            <div class="v-login__staff">
                <button
                    v-for="p in couriers"
                    :key="p.id"
                    type="button"
                    :class="['v-login__person', { 'v-login__person--selected': selected?.id === p.id }]"
                    @click="select(p)"
                >
                    <span class="v-login__avatar" :style="{ background: p.color }">{{ p.initials }}</span>
                    {{ p.name }}
                </button>
            </div>
        </template>

        <div v-if="selected" class="v-login__pad">
            <p class="v-login__who">
                <span class="v-login__avatar" :style="{ background: selected.color }">{{ selected.initials }}</span>
                {{ selected.name }}
                <TpvButton variant="secondary" @click="select(null)">Cambiar</TpvButton>
            </p>
            <h2 class="v-login__title">Introduce tu PIN</h2>
            <NumericKeypad v-model="pin" masked :length="4" :error="!!error" @complete="submit" />
            <p class="v-login__error" role="alert">{{ error }}</p>
        </div>

        <footer class="v-login__support"><SupportContact /></footer>
    </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BrandMark from '@/components/BrandMark.vue';
import NumericKeypad from '@/components/NumericKeypad.vue';
import SupportContact from '@/components/SupportContact.vue';
import TpvButton from '@/components/TpvButton.vue';
import pos, { errorMessage } from '@/services/pos';
import { useSessionStore } from '@/stores/session';
import { useOrderStore } from '@/stores/order';

const router = useRouter();
const route = useRoute();
const session = useSessionStore();
const order = useOrderStore();

const staff = ref([]);
const loaded = ref(false);
const selected = ref(null);
const pin = ref('');
const error = ref('');
const floorStaff = computed(() => staff.value.filter((p) => p.role !== 'repartidor'));
const couriers = computed(() => staff.value.filter((p) => p.role === 'repartidor'));

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
        router.replace(resume ?? route.query.redirect ?? '/');
    } catch (e) {
        error.value = errorMessage(e);
        pin.value = '';
    }
}
</script>
