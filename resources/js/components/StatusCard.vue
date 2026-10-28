<template>
    <section :class="['c-status-card', `c-status-card--${status}`]">
        <header class="c-status-card__header">
            <span class="c-status-card__dot" aria-hidden="true"></span>
            <h2 class="c-status-card__title">{{ title }}</h2>
        </header>
        <p class="c-status-card__message">{{ message }}</p>
        <slot />
        <pre v-if="detail" class="c-status-card__detail">{{ formatted }}</pre>
    </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    status: { type: String, default: 'idle' }, // idle | loading | ok | error
    message: { type: String, default: '' },
    detail: { type: [Object, String], default: null },
});

const formatted = computed(() =>
    typeof props.detail === 'string' ? props.detail : JSON.stringify(props.detail, null, 2),
);
</script>
