<template>
    <Teleport to="body">
        <div v-if="open" :class="['c-modal', { 'c-modal--danger': danger }]" role="dialog" aria-modal="true" :aria-label="title">
            <div class="c-modal__backdrop" @click="$emit('close')"></div>
            <div class="c-modal__panel">
                <h2 class="c-modal__title">{{ title }}</h2>
                <p v-if="text" class="c-modal__text">{{ text }}</p>
                <slot />
                <div v-if="$slots.actions" class="c-modal__actions"><slot name="actions" /></div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    text: { type: String, default: '' },
    danger: { type: Boolean, default: false },
});
defineEmits(['close']);
</script>
