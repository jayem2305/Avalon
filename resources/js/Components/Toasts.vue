<script setup>
import { toasts } from '../lib/toast';
</script>

<template>
    <div id="toasts" aria-live="polite">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="{ error: t.isError }">{{ t.message }}</div>
    </div>
</template>
