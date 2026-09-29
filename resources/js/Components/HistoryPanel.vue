<script setup>
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';

const { view, playerName, names } = useRoom();

// Newest first: each quest's proposals, then its result, then any Lady check.
const items = computed(() => {
    const v = view.value;
    const seat = Object.fromEntries(v.players.map((p) => [p.id, p.seat]));
    const out = [];
    for (let q = 0; q <= Math.min(v.questNum, 4); q++) {
        for (const p of v.proposals.filter((x) => x.quest === q)) {
            const votes = Object.entries(p.votes).sort(([a], [b]) => seat[a] - seat[b]);
            const yes = votes.filter(([, ok]) => ok).length;
            out.push({ type: 'proposal', key: `p${q}-${p.attempt}`, q, p, votes, yes, no: votes.length - yes });
        }
        const r = v.questResults[q];
        if (r) out.push({ type: 'result', key: `r${q}`, q, r });
        for (const c of v.ladyChecks.filter((x) => x.afterQuest === q + 1)) {
            out.push({ type: 'lady', key: `l${c.targetId}`, c });
        }
    }
    return out.reverse();
});
</script>

<template>
    <!-- Shown only once the game is over, as a recap. -->
    <details v-if="view.phase === 'ended' && items.length" class="panel" open>
        <summary>History</summary>
        <ul class="history">
            <li v-for="it in items" :key="it.key">
                <template v-if="it.type === 'proposal'">
                    <b>Quest {{ it.q + 1 }}, proposal {{ it.p.attempt }}</b>:
                    {{ playerName(it.p.leaderId) }} proposed {{ names(it.p.team) }}
                    <span class="tag" :class="it.p.approved ? 'ok' : 'evil'">{{ it.p.approved ? 'Approved' : 'Rejected' }} {{ it.yes }}–{{ it.no }}</span>
                    <div class="votes">
                        <span v-for="[id, ok] in it.votes" :key="id" class="tag" :class="ok ? 'ok' : 'evil'">{{ ok ? '✓' : '✗' }} {{ playerName(id) }}</span>
                    </div>
                </template>
                <template v-else-if="it.type === 'result'">
                    <b>Quest {{ it.q + 1 }} {{ it.r.success ? 'succeeded' : 'failed' }}</b>:
                    {{ it.r.fails }} Fail card{{ it.r.fails === 1 ? '' : 's' }} (team: {{ names(it.r.team) }})
                </template>
                <template v-else>
                    ☾ {{ playerName(it.c.holderId) }} examined {{ playerName(it.c.targetId) }}
                </template>
            </li>
        </ul>
    </details>
</template>
