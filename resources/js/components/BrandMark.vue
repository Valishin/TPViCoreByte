<template>
    <span class="c-brand" role="img" aria-label="iCOREBYTE TPV" :style="{ '--brand-h': `min(${height}px, 9vw)` }">
        <img class="c-brand__logo" src="/images/icorebyte-logo.png" alt="" />
        <span class="c-brand__divider" aria-hidden="true"></span>
        <span class="c-brand__tpv" aria-hidden="true">TPV</span>
    </span>
</template>

<script setup>
/** Marca «iCOREBYTE | TPV»: el logo y «TPV» en el mismo azul, a la altura de sus letras (más pequeña en pantallas estrechas). */
defineProps({ height: { type: Number, default: 40 } });
</script>
