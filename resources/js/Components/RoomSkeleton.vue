<script setup>
// Grey placeholder shaped like the room page, shown while a room is loading.
</script>

<template>
    <div class="wrap" aria-busy="true" aria-label="Loading the room">
        <header class="topbar">
            <div class="title"><h1>AVALON</h1><span class="skeleton" style="width: 64px; height: 26px" /></div>
            <span class="skeleton" style="width: 90px; height: 34px" />
        </header>
        <div class="room-layout">
            <main>
                <div class="skel-panel">
                    <span class="skeleton skel-title" />
                    <span class="skeleton skel-line" style="width: 60%" />
                </div>
                <div class="skel-panel">
                    <div class="skel-circles"><span v-for="i in 5" :key="i" class="skeleton" /></div>
                </div>
                <div class="skel-panel">
                    <span class="skeleton skel-title" style="width: 30%" />
                    <span class="skeleton skel-line" />
                    <span class="skeleton skel-line" style="width: 80%" />
                </div>
                <div class="skel-panel">
                    <span class="skeleton skel-title" style="width: 25%" />
                    <span class="skeleton skel-map" />
                </div>
                <div class="skel-panel">
                    <span class="skeleton skel-title" style="width: 35%" />
                    <div class="skel-cards"><span v-for="i in 6" :key="i" class="skeleton" /></div>
                </div>
            </main>
            <div class="room-side">
                <div class="skel-panel"><span class="skeleton skel-card" /></div>
                <div class="skel-panel">
                    <span class="skeleton" style="height: 34px" />
                    <span class="skeleton skel-line" style="height: 220px" />
                </div>
            </div>
        </div>
    </div>
</template>
