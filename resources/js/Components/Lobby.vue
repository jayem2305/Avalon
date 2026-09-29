<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useRoom } from '../composables/useRoom';
import { EVIL_COUNT, ROLES, TEAM_SIZES, setupSummary } from '../lib/avalon';
import { toast } from '../lib/toast';

const { view, isHost, act, pending, playerName } = useRoom();

const count = computed(() => view.value.players.length);
const devTools = computed(() => usePage().props.devTools);
const setup = computed(() => setupSummary(count.value, view.value.settings));
const inviteLink = computed(() => `${location.origin}/r/${view.value.code}`);

// Test mode offers only roles this setup deals (sized for at least 5 players,
// so you can pick before adding everyone).
const testRoles = computed(() => setupSummary(Math.max(count.value, 5), view.value.settings).inPlay ?? []);
const pickedRole = computed(() => view.value.settings.hostRole ?? null);
const pickUnavailable = computed(() => pickedRole.value && !testRoles.value.includes(pickedRole.value));

const options = [
    { key: 'percival', label: 'Percival', desc: 'Good. Knows who Merlin is.', side: 'good' },
    { key: 'morgana', label: 'Morgana', desc: 'Evil. Looks like Merlin to Percival.', side: 'evil' },
    { key: 'mordred', label: 'Mordred', desc: 'Evil. Hidden from Merlin.', side: 'evil' },
    { key: 'oberon', label: 'Oberon', desc: 'Evil, but unknown to the other Evil players.', side: 'evil' },
    { key: 'lady', label: 'Lady of the Lake', desc: 'After quests 2, 3 and 4, the holder secretly checks one player’s loyalty.', side: 'neutral' },
];

async function copyLink() {
    try {
        await navigator.clipboard.writeText(inviteLink.value);
        toast('Invite link copied.');
    } catch {
        prompt('Copy this invite link:', inviteLink.value);
    }
}

const removing = ref(null);
async function kick(p) {
    if (!confirm(`Remove ${p.name} from the room?`)) return;
    removing.value = p.id;
    await act('kick', { target: p.id });
    removing.value = null;
}
</script>

<template>
    <!-- Column 1: invite and players. Column 2: game setup and start. -->
    <div class="lobby-col">
        <div class="panel share-panel">
            <div>
                <p class="muted">Room code, share it with the other players</p>
                <div class="big-code">{{ view.code }}</div>
            </div>
            <button class="btn small" @click="copyLink">Copy invite link</button>
        </div>

        <div class="panel">
            <div class="row between"><h2>Players</h2><span class="muted">{{ count }}/10</span></div>
            <ul class="lobby-players">
                <li v-for="p in view.players" :key="p.id">
                    <span class="grow">{{ p.name }} <span v-if="p.id === view.meId" class="muted">(you)</span></span>
                    <span v-if="p.bot" class="tag">Bot</span>
                    <span v-if="p.id === view.hostId" class="tag gold">Host</span>
                    <button v-if="isHost && p.id !== view.meId" class="btn small ghost" :aria-busy="removing === p.id" :disabled="removing === p.id" @click="kick(p)">Remove</button>
                </li>
            </ul>
            <div v-if="isHost && count < 10" class="row" style="margin-top: 12px">
                <button class="btn small" :aria-busy="pending === 'addBot'" :disabled="pending === 'addBot'" @click="act('addBot')">+ Add bot</button>
                <span class="muted" style="font-size: .85rem">Bots fill empty seats and play on their own.</span>
            </div>
        </div>
    </div>

    <div class="lobby-col">
        <div class="panel">
            <div class="row between">
                <h2>Roles</h2>
                <span v-if="pending === 'settings'" class="inline-status"><span class="spinner" /> Saving…</span>
                <span v-else-if="!isHost" class="muted">The host chooses</span>
            </div>
            <div class="options">
                <label class="option good locked">
                    <input type="checkbox" checked disabled>
                    <span><strong>Merlin &amp; the Assassin</strong><small>Always in the game.</small></span>
                </label>
                <label v-for="o in options" :key="o.key" class="option" :class="[o.side, { locked: !isHost }]">
                    <input
                        type="checkbox"
                        :checked="view.settings[o.key]"
                        :disabled="!isHost"
                        @change="act('settings', { [o.key]: $event.target.checked })"
                    >
                    <span><strong>{{ o.label }}</strong><small>{{ o.desc }}</small></span>
                </label>
            </div>
            <label v-if="isHost && devTools" class="option neutral test-role">
                <span>
                    <strong>Test mode: my role</strong>
                    <small>Local server only. Applies to the next game; after that roles are random again.</small>
                </span>
                <select :value="pickedRole ?? ''" @change="act('settings', { hostRole: $event.target.value || null })">
                    <option value="">Random (normal game)</option>
                    <option v-for="key in testRoles" :key="key" :value="key">{{ ROLES[key].name }}</option>
                    <option v-if="pickUnavailable" :value="pickedRole" disabled>{{ ROLES[pickedRole].name }} (not in this setup)</option>
                </select>
                <small v-if="pickUnavailable" class="field-error" style="flex-basis: 100%">
                    {{ ROLES[pickedRole].name }} isn't in this setup any more. Turn it back on, or pick another role.
                </small>
            </label>
            <div class="setup-summary">
                <template v-if="setup.ok">
                    <span style="color: var(--good)">Good ({{ setup.good }}):</span> {{ setup.goodRoles.join(', ') }}<br>
                    <span style="color: var(--evil)">Evil ({{ setup.evil }}):</span> {{ setup.evilRoles.join(', ') }}
                    <div v-if="setup.notes.length" class="muted" style="margin-top: 6px">{{ setup.notes.join(' ') }}</div>
                </template>
                <div v-else class="warn">{{ setup.error }}</div>
            </div>
        </div>

        <div class="panel">
            <h2>Quest team sizes</h2>
            <table class="sizes">
                <tr><th>Players</th><th>Good/Evil</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Q5</th></tr>
                <tr v-for="(sizes, n) in TEAM_SIZES" :key="n" :class="{ current: +n === count }">
                    <td>{{ n }}</td>
                    <td>{{ n - EVIL_COUNT[n] }}/{{ EVIL_COUNT[n] }}</td>
                    <td v-for="(size, i) in sizes" :key="i">{{ size }}{{ n >= 7 && i === 3 ? '*' : '' }}</td>
                </tr>
            </table>
            <p class="muted" style="font-size: .8rem; margin-top: 8px">* With 7 or more players, the 4th quest needs two Fail cards to fail.</p>
        </div>

        <div class="panel">
            <button v-if="isHost" class="btn primary block big" :disabled="!setup.ok || pending === 'start'" :aria-busy="pending === 'start'" @click="act('start')">Start game</button>
            <p v-else class="muted" style="text-align: center">Waiting for {{ playerName(view.hostId) }} to start the game…</p>
        </div>
    </div>
</template>
