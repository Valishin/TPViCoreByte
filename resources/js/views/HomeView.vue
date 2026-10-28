<template>
    <main class="v-home">
        <h1 class="v-home__title">TPV Hostelería</h1>
        <p class="v-home__subtitle">Entorno base listo.</p>
        <RouterLink to="/estado" custom v-slot="{ navigate }">
            <TpvButton @click="navigate">Ver estado del sistema</TpvButton>
        </RouterLink>
    </main>
</template>

<script setup>
import TpvButton from '@/components/TpvButton.vue';
</script>
