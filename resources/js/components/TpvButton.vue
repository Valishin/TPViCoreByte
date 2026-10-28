<template>
    <button
        :type="type"
        :class="['c-button', `c-button--${variant}`, { 'c-button--block': block, 'c-button--loading': loading }]"
        :disabled="disabled || loading"
    >
        <span class="c-button__label"><slot /></span>
        <span v-if="loading" class="c-button__spinner" aria-hidden="true"></span>
    </button>
</template>

<script setup>
defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' }, // primary | secondary | danger
    block: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
</script>
