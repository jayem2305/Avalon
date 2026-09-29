<script setup>
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';

const { view } = useRoom();

const quests = computed(() => view.value.teamSizes.map((size, i) => {
    const v = view.value;
    const r = v.questResults[i];
    if (r) {
        return { size, cls: r.success ? 'success' : 'fail', label: r.fails === 0 ? 'Success' : `${r.fails} fail${r.fails > 1 ? 's' : ''}` };
    }
    return { size, cls: i === v.questNum && v.phase !== 'ended' ? 'current' : '', label: `Quest ${i + 1}` };
}));
</script>

<template>
    <div class="panel">
        <div class="track">
            <div v-for="(q, i) in quests" :key="i" class="quest" :class="q.cls">
                <div>
                    <div class="size">{{ q.size }}</div>
                    <div class="lbl">{{ q.label }}</div>
                </div>
                <span v-if="view.twoFailQuest === i" class="two">2 fails</span>
            </div>
        </div>
        <div class="vote-track">
            <span>Rejected proposals</span>
            <span class="pips">
                <span v-for="i in 5" :key="i" class="pip" :class="{ on: i <= view.rejectCount, last: i === 5 }">{{ i }}</span>
            </span>
            <span v-if="view.rejectCount === 4 && view.phase !== 'ended'" class="tag evil">Next rejection hands Evil the win</span>
            <span v-else>5 in a row and Evil wins</span>
        </div>
    </div>
</template>
