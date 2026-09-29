<script setup>
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';

const { view, playerName } = useRoom();

const banner = computed(() => {
    const v = view.value;
    const q = v.questNum + 1;
    return {
        proposing: ['⚑', `Quest ${Math.min(q, 5)}: team proposal`, `${playerName(v.leaderId)} is choosing ${v.teamSizes[v.questNum]} players.`],
        voting: ['⚖', `Quest ${q}: vote on the team`, 'Everyone votes to approve or reject.'],
        questing: ['⚔', `Quest ${q} is underway`, 'The team secretly decides the quest’s fate.'],
        lady: ['☾', 'Lady of the Lake', `${playerName(v.lady?.holderId)} examines a player’s loyalty.`],
        assassin: ['🗡', 'The Assassin strikes', 'Good has won three quests, but night has fallen. Evil hunts for Merlin…'],
        ended: ['♛', 'Game over', v.winReason],
    }[v.phase];
});
</script>

<template>
    <div class="panel phase-banner">
        <div class="icon">{{ banner[0] }}</div>
        <div>
            <h2>{{ banner[1] }}</h2>
            <p>{{ banner[2] }}</p>
        </div>
    </div>
</template>
