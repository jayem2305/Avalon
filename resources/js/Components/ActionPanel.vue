<script setup>
import { computed, ref } from 'vue';
import { useRoom } from '../composables/useRoom';
import PlayerPicker from './PlayerPicker.vue';

const { view, ui, act, isHost, myTurn, playerName, names } = useRoom();

// Which button's request is in flight: it shows a spinner, and the others
// are disabled so nothing is sent twice.
const clicked = ref(null);
const busy = computed(() => clicked.value !== null);

async function send(action, data, key = action) {
    clicked.value = key;
    await act(action, data);
    clicked.value = null;
}

const size = computed(() => view.value.teamSizes[view.value.questNum]);
const attempt = computed(() => view.value.rejectCount + 1);
const pick = computed(() => ui.selected[0]);
const onTeam = computed(() => view.value.team.includes(view.value.meId));
const twoFailNote = computed(() => view.value.twoFailQuest === view.value.questNum);
const waitingVotes = computed(() => view.value.players.filter((p) => !view.value.voted.includes(p.id)).map((p) => p.id));
const waitingCards = computed(() => view.value.team.filter((id) => !view.value.played.includes(id)));
const iWon = computed(() => view.value.me && (view.value.me.evil ? 'evil' : 'good') === view.value.winner);

function playFail() {
    if (confirm('Play a FAIL card?')) send('quest', { card: 'fail' }, 'fail');
}

function strike() {
    if (confirm(`Strike ${playerName(pick.value)}? This ends the game.`)) send('assassinate', { target: pick.value });
}
</script>

<template>
    <div v-if="view.phase === 'ended'" class="panel result-banner" :class="view.winner">
        <h2>{{ view.winner === 'good' ? 'Good triumphs' : 'Evil prevails' }}</h2>
        <p>{{ view.winReason }}</p>
        <p style="margin-top: 12px; color: var(--text)">{{ iWon ? 'Your side won.' : 'Your side lost.' }}</p>
        <div class="row" style="justify-content: center; margin-top: 16px">
            <div v-if="isHost" class="end-actions">
                <button class="btn primary big" :disabled="busy" :aria-busy="clicked === 'rematch'" @click="send('rematch')">Play again</button>
                <button class="btn big" :disabled="busy" :aria-busy="clicked === 'restart'" @click="send('restart')">Change settings</button>
            </div>
            <span v-else class="muted">Waiting for {{ playerName(view.hostId) }} to start a new game…</span>
        </div>
    </div>

    <div v-else class="panel action" :class="{ 'my-turn': myTurn }">
        <span v-if="myTurn" class="turn-badge">Your turn</span>
        <!-- Team proposal -->
        <template v-if="view.phase === 'proposing'">
            <template v-if="view.leaderId === view.meId">
                <h2>You are the leader</h2>
                <p class="hint">
                    Pick <b>{{ size }}</b> players for Quest {{ view.questNum + 1 }} by tapping them below
                    (you can include yourself). Proposal {{ attempt }} of 5.
                </p>
            </template>
            <template v-else>
                <h2>Waiting for the leader</h2>
                <p class="hint">
                    <span class="names">{{ playerName(view.leaderId) }}</span> is choosing {{ size }} players for
                    Quest {{ view.questNum + 1 }}. Proposal {{ attempt }} of 5.
                </p>
            </template>
            <p v-if="attempt === 5" class="hint" style="color: var(--evil)">This is the 5th proposal. If it is rejected, Evil wins.</p>
            <PlayerPicker v-if="view.leaderId === view.meId" />
            <div v-if="view.leaderId === view.meId" class="choices single">
                <button
                    class="btn primary big"
                    :disabled="busy || ui.selected.length !== size"
                    :aria-busy="clicked === 'propose'"
                    @click="send('propose', { team: ui.selected })"
                >
                    Propose team ({{ ui.selected.length }}/{{ size }})
                </button>
            </div>
        </template>

        <!-- Team vote -->
        <template v-else-if="view.phase === 'voting'">
            <h2>Approve this team?</h2>
            <p class="hint">{{ playerName(view.leaderId) }} proposes: <span class="names">{{ names(view.team) }}</span></p>
            <div class="choices">
                <button class="btn big" :class="{ good: view.myVote === true }" :disabled="busy" :aria-busy="clicked === 'approve'" @click="send('vote', { approve: true }, 'approve')">Approve</button>
                <button class="btn big" :class="{ evil: view.myVote === false }" :disabled="busy" :aria-busy="clicked === 'reject'" @click="send('vote', { approve: false }, 'reject')">Reject</button>
            </div>
            <p v-if="view.myVote === null" class="hint">Your vote will be revealed once everyone has voted.</p>
            <p v-else class="hint">
                You voted <b>{{ view.myVote ? 'Approve' : 'Reject' }}</b>. You can change it until the last vote is in.
                Waiting for: <span class="names">{{ names(waitingVotes) }}</span>
            </p>
        </template>

        <!-- Quest -->
        <template v-else-if="view.phase === 'questing'">
            <template v-if="onTeam && !view.myCard">
                <h2>Choose your quest card</h2>
                <p class="hint">
                    Your card is secret. Only the number of Fail cards is revealed.
                    <template v-if="twoFailNote"> This quest needs <b>two</b> Fail cards to fail.</template>
                </p>
                <div class="choices">
                    <button class="btn good big" :disabled="busy" :aria-busy="clicked === 'success'" @click="send('quest', { card: 'success' }, 'success')">Success</button>
                    <button class="btn evil big" :disabled="busy || !view.me.evil" :aria-busy="clicked === 'fail'" @click="playFail">Fail</button>
                </div>
                <p v-if="!view.me.evil" class="hint">Loyal servants of Arthur must always play Success.</p>
            </template>
            <template v-else-if="onTeam">
                <h2>Card played</h2>
                <p class="hint">
                    You played <b>{{ view.myCard === 'fail' ? 'Fail' : 'Success' }}</b>.
                    Waiting for: <span class="names">{{ names(waitingCards) }}</span>
                </p>
            </template>
            <template v-else>
                <h2>The quest is underway</h2>
                <p class="hint">
                    <span class="names">{{ names(view.team) }}</span> are choosing their cards.
                    <template v-if="twoFailNote"> This quest needs <b>two</b> Fail cards to fail.</template>
                </p>
            </template>
        </template>

        <!-- Lady of the Lake -->
        <template v-else-if="view.phase === 'lady'">
            <template v-if="view.lady.holderId === view.meId">
                <h2>Use the Lady of the Lake</h2>
                <p class="hint">
                    Choose a player to secretly learn their loyalty. You can’t pick anyone who has already held the Lady.
                    They receive the Lady next.
                </p>
                <PlayerPicker />
                <div class="choices single">
                    <button class="btn primary big" :disabled="busy || !pick" :aria-busy="clicked === 'lady'" @click="send('lady', { target: pick })">
                        {{ pick ? `Examine ${playerName(pick)}` : 'Choose a player' }}
                    </button>
                </div>
            </template>
            <template v-else>
                <h2>The Lady of the Lake</h2>
                <p class="hint"><span class="names">{{ playerName(view.lady.holderId) }}</span> is choosing a player to examine.</p>
            </template>
        </template>

        <!-- Assassination -->
        <template v-else-if="view.phase === 'assassin'">
            <template v-if="view.assassinId === view.meId">
                <h2>Name Merlin</h2>
                <p class="hint">Talk it over with your fellow minions in chat, then strike. If you hit Merlin, Evil wins.</p>
                <PlayerPicker />
                <div class="choices single">
                    <button class="btn evil big" :disabled="busy || !pick" :aria-busy="clicked === 'assassinate'" @click="strike">
                        {{ pick ? `Strike ${playerName(pick)}` : 'Choose a player' }}
                    </button>
                </div>
            </template>
            <template v-else-if="view.me.evil">
                <h2>Help the Assassin</h2>
                <p class="hint">Who is Merlin? Share your suspicions with <span class="names">{{ playerName(view.assassinId) }}</span> in chat.</p>
            </template>
            <template v-else>
                <h2>Hold your breath</h2>
                <p class="hint">Evil is now revealed. <span class="names">{{ playerName(view.assassinId) }}</span> (the Assassin) is trying to find Merlin.</p>
            </template>
        </template>
    </div>
</template>
