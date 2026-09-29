<script setup>
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';
import { ROLES } from '../lib/avalon';
import { play } from '../lib/sound';
import RoleArt from './RoleArt.vue';

const { view, ui, names, playerName } = useRoom();

const me = computed(() => view.value.me);
const role = computed(() => ROLES[me.value.role]);
const known = computed(() => Object.keys(me.value.knows));
const seesMinions = computed(() => me.value.evil && me.value.role !== 'oberon');

function flip() {
    ui.roleOpen = !ui.roleOpen;
    play('flip');
}
</script>

<template>
    <div v-if="me && role" class="panel role-panel">
        <button
            type="button"
            class="role-flip"
            :class="{ flipped: ui.roleOpen }"
            :aria-label="ui.roleOpen ? 'Hide my role' : 'Reveal my secret role'"
            @click="flip"
        >
            <span class="role-flip-inner">
                <span class="face back">
                    <RoleArt />
                    <span class="face-label">Tap to reveal your role</span>
                </span>
                <span class="face front" :class="role.side">
                    <RoleArt :role="me.role" />
                    <span class="face-title">
                        <small>{{ role.side === 'good' ? 'Loyal to Arthur' : 'Serves Mordred' }}</small>
                        {{ role.name }}
                    </span>
                </span>
            </span>
        </button>
        <p class="muted role-hint">{{ ui.roleOpen ? 'Tap the card to hide it again.' : 'Make sure nobody is looking at your screen.' }}</p>

        <div v-if="ui.roleOpen" class="role-card" :class="role.side">
            <p>{{ role.text }}</p>

            <template v-if="me.role === 'merlin'">
                <p v-if="known.length"><b>Evil players you can see:</b> {{ names(known) }}</p>
                <p v-else>You see no Evil players.</p>
                <p v-if="view.settings.mordred" class="muted">Mordred is hidden from you.</p>
            </template>
            <template v-else-if="me.role === 'percival'">
                <p v-if="known.length === 1"><b>Merlin is:</b> {{ names(known) }}</p>
                <p v-else><b>One of these is Merlin, the other is Morgana:</b> {{ names(known) }}</p>
            </template>
            <template v-else-if="seesMinions">
                <p v-if="known.length"><b>Your fellow minions:</b> {{ names(known) }}</p>
                <p v-else>You see no other minions.</p>
                <p v-if="view.settings.oberon" class="muted">Oberon also serves Evil, but is hidden from you.</p>
            </template>

            <p v-if="me.isAssassin && me.role !== 'assassin'">
                <b>You are also the Assassin.</b> If Good wins three quests, you name Merlin.
            </p>

            <template v-if="me.ladyFindings.length">
                <p style="margin-top: 10px"><b>Lady of the Lake findings</b></p>
                <ul>
                    <li v-for="f in me.ladyFindings" :key="f.targetId">
                        {{ playerName(f.targetId) }} is
                        <b :style="{ color: f.evil ? 'var(--evil)' : 'var(--good)' }">{{ f.evil ? 'Evil' : 'Good' }}</b>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>
