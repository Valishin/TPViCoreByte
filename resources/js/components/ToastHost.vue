<template>
    <div class="c-toasts" aria-live="polite">
        <div v-for="t in notices.toasts" :key="t.id" :class="['c-toast', `c-toast--${t.type}`]" @click="notices.dismiss(t.id)">
            {{ t.text }}
        </div>
    </div>
</template>

<script setup>
import { useNoticesStore } from '@/stores/notices';

const notices = useNoticesStore();
</script>
