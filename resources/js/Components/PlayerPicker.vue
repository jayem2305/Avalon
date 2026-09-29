<script setup>
// Compact player chips shown inside the action panel whenever you need to pick
// someone (leader choosing a team, Lady of the Lake, the Assassin), so you can
// choose without scrolling down to the Round Table. Uses the same selection
// rules as the player cards and the map.
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';

const { view, ui, selection, togglePick } = useRoom();

const chips = computed(() => {
    const mode = selection.value;
    if (!mode) return [];
    return view.value.players.map((p) => ({
        ...p,
        can: mode.can(p),
        picked: ui.selected.includes(p.id),
        initial: p.name.trim().charAt(0).toUpperCase(),
        hue: (p.seat * 137) % 360,
    }));
});
</script>

<template>
    <div v-if="chips.length" class="picker" role="group" aria-label="Choose players">
        <button
            v-for="p in chips"
            :key="p.id"
            type="button"
            class="pick-chip"
            :class="{ picked: p.picked }"
            :disabled="!p.can"
            :aria-pressed="p.picked"
            @click="togglePick(p.id)"
        >
            <span class="avatar" :style="{ '--hue': p.hue }">{{ p.initial }}</span>
            <span class="pick-name" :title="p.id === view.meId ? 'You' : p.name">{{ p.name }}</span>
            <span v-if="p.picked" class="pick-check">✓</span>
        </button>
    </div>
</template>
