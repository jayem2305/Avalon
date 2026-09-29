<script setup>
// While Inertia moves between pages (joining or creating a room, leaving it),
// cover the screen with a skeleton of the page that is loading. It only appears
// if the visit takes longer than a moment, so fast visits don't flash.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import HomeSkeleton from './HomeSkeleton.vue';
import RoomSkeleton from './RoomSkeleton.vue';

const DELAY_MS = 180;
const kind = ref(null);
let timer = null;
const off = [];

onMounted(() => {
    off.push(router.on('start', (event) => {
        const url = event.detail.visit.url;
        const path = typeof url === 'string' ? new URL(url, location.href).pathname : url.pathname;
        // Joining or creating posts to /join or /rooms, then lands on the room.
        const next = path.startsWith('/r/') || path === '/join' || path === '/rooms' ? 'room' : 'home';
        clearTimeout(timer);
        timer = setTimeout(() => { kind.value = next; }, DELAY_MS);
    }));
    off.push(router.on('finish', () => {
        clearTimeout(timer);
        kind.value = null;
    }));
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    off.forEach((stop) => stop());
});
</script>

<template>
    <div v-if="kind" class="nav-skeleton">
        <RoomSkeleton v-if="kind === 'room'" />
        <HomeSkeleton v-else />
    </div>
</template>
