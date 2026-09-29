<script setup>
import { ref } from 'vue';
import { provideRoom } from '../composables/useRoom';
import { muted, play } from '../lib/sound';
import Lobby from '../Components/Lobby.vue';
import PhaseBanner from '../Components/PhaseBanner.vue';
import ActionPanel from '../Components/ActionPanel.vue';
import QuestTrack from '../Components/QuestTrack.vue';
import RoundTable from '../Components/RoundTable.vue';
import PixelMap from '../Components/PixelMap.vue';
import RoleCard from '../Components/RoleCard.vue';
import HistoryPanel from '../Components/HistoryPanel.vue';
import SidePanel from '../Components/SidePanel.vue';
import Toasts from '../Components/Toasts.vue';

const props = defineProps({ initial: { type: Object, required: true } });
const { view, connected, isHost, act, pending } = provideRoom(props.initial);

const leaving = ref(false);
async function leave() {
    if (!confirm('Leave this room?')) return;
    leaving.value = true;
    await act('leave'); // goes to the home page once the server confirms
    leaving.value = false;
}

function endGame() {
    if (confirm('End the current game and return everyone to the lobby?')) act('restart');
}

function toggleSound() {
    muted.value = !muted.value;
    play('click');
}
</script>

<template>
    <div class="wrap room-page">
        <header class="topbar">
            <div class="title">
                <h1>AVALON</h1>
                <span class="room-code">{{ view.code }}</span>
                <span class="conn" :class="{ off: !connected }" :title="connected ? 'Live' : 'Reconnecting…'" />
            </div>
            <div class="row">
                <button class="btn small ghost icon-btn" :title="muted ? 'Sound off' : 'Sound on'" @click="toggleSound">
                    {{ muted ? '🔇' : '🔊' }}
                </button>
                <button v-if="view.phase === 'lobby'" class="btn small ghost" :disabled="leaving" :aria-busy="leaving" @click="leave">
                    Leave room
                </button>
                <button
                    v-else-if="isHost && view.phase !== 'ended'"
                    class="btn small ghost"
                    :aria-busy="pending === 'restart'"
                    @click="endGame"
                >
                    End game
                </button>
            </div>
        </header>

        <div v-if="view.phase === 'lobby'" class="room-layout lobby-layout">
            <main><Lobby /></main>
            <div class="room-side"><SidePanel /></div>
        </div>

        <!-- Game: on big screens the three sections sit side by side and fit the
             window (each scrolls on its own); on small screens they stack. -->
        <div v-else class="game-layout">
            <main class="g-main">
                <PhaseBanner />
                <QuestTrack />
                <ActionPanel />
                <RoleCard class="only-mobile" />
                <RoundTable />
                <HistoryPanel />
            </main>
            <section class="g-map"><PixelMap /></section>
            <div class="g-side room-side">
                <RoleCard class="only-desktop" />
                <SidePanel />
            </div>
        </div>
        <Toasts />
    </div>
</template>
