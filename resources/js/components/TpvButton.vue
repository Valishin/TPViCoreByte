<template>
    <button
        :type="type"
        :class="['c-button', `c-button--${variant}`, size && `c-button--${size}`, { 'c-button--block': block, 'c-button--icon': icon }]"
        :disabled="disabled || loading"
    >
        <span class="c-button__label"><slot /></span>
        <span v-if="count" class="c-button__count">{{ count }}</span>
        <span v-if="loading" class="c-button__spinner" aria-hidden="true"></span>
    </button>
</template>

<script setup>
defineProps({
    type: { type: String, default: 'button' },
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | success | ghost
    size: { type: String, default: null }, // lg | sm
    block: { type: Boolean, default: false },
    icon: { type: Boolean, default: false },
    count: { type: [Number, String], default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
</script>
