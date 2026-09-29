<script setup>
import { computed } from 'vue';
import { useRoom } from '../composables/useRoom';
import { ROLES } from '../lib/avalon';
import RoleArt from './RoleArt.vue';

const { view, ui, selection, togglePick } = useRoom();

const KNOW_TAGS = {
    evil: ['evil', 'Evil'],
    merlin: ['good', 'Merlin'],
    'merlin?': ['good', 'Merlin or Morgana'],
};

const lastProposal = computed(() => {
    const v = view.value;
    return v.phase !== 'voting' ? v.proposals[v.proposals.length - 1] : null;
});

const cards = computed(() => {
    const v = view.value;
    const mode = selection.value;
    const last = lastProposal.value;
    const findings = v.me?.ladyFindings ?? [];
    const showLeader = v.phase !== 'ended' && v.phase !== 'assassin';

    return v.players.map((p) => {
        const tags = [];
        const onTeam = v.team.includes(p.id);
        if (p.id === v.meId) tags.push(['', 'You']);
        if (p.bot) tags.push(['', 'Bot']);
        if (showLeader && p.id === v.leaderId) tags.push(['gold', '♛ Leader']);
        if (v.phase !== 'ended' && v.lady?.holderId === p.id) tags.push(['gold', '☾ Lady']);
        if (onTeam) tags.push(['gold', 'On quest']);
        if (v.phase === 'voting') tags.push(v.voted.includes(p.id) ? ['ok', 'Voted'] : ['', 'Deciding…']);
        if (v.phase === 'questing' && onTeam) tags.push(v.played.includes(p.id) ? ['ok', 'Card played'] : ['', 'Choosing…']);

        if (v.phase === 'ended' && p.role) {
            const r = ROLES[p.role];
            tags.push([r.side, r.short ?? r.name]);
            if (p.id === v.assassinId && p.role !== 'assassin') tags.push(['evil', 'Assassin']);
        } else if (p.evil) {
            tags.push(['evil', p.id === v.assassinId ? 'Assassin' : 'Evil']);
        } else if (v.me?.knows?.[p.id]) {
            tags.push(KNOW_TAGS[v.me.knows[p.id]]);
        }

        const finding = findings.find((f) => f.targetId === p.id);
        if (finding && v.phase !== 'ended') tags.push([finding.evil ? 'evil' : 'good', `☾ ${finding.evil ? 'Evil' : 'Good'}`]);
        if (v.assassinTarget === p.id) tags.push(['evil', '🗡 Struck']);

        const selectable = !!mode && mode.can(p);
        return {
            ...p,
            tags,
            selectable,
            selected: ui.selected.includes(p.id),
            initial: p.name.trim().charAt(0).toUpperCase(),
            hue: (p.seat * 137) % 360, // spread seat colours around the colour wheel
            lastVote: last ? last.votes[p.id] : undefined,
            classes: {
                me: p.id === v.meId,
                'on-team': onTeam,
                'revealed-evil': p.evil && v.phase !== 'ended',
                struck: v.assassinTarget === p.id,
                selectable,
                selected: ui.selected.includes(p.id),
                dim: !!mode && !selectable,
            },
        };
    });
});
</script>

<template>
    <div class="panel">
        <div class="row between" style="margin-bottom: 12px">
            <h2>The Round Table</h2>
            <span v-if="lastProposal" class="muted" style="font-size: .8rem">✓/✗ = votes on the last proposal</span>
        </div>
        <div class="players">
            <component
                :is="p.selectable ? 'button' : 'div'"
                v-for="p in cards"
                :key="p.id"
                class="player"
                :class="p.classes"
                :type="p.selectable ? 'button' : undefined"
                @click="p.selectable && togglePick(p.id)"
            >
                <span v-if="p.selected" class="check">✓</span>
                <span v-else-if="p.lastVote === true" class="vote-mark yes" title="Approved last proposal">✓</span>
                <span v-else-if="p.lastVote === false" class="vote-mark no" title="Rejected last proposal">✗</span>
                <div class="player-head">
                    <span class="avatar" :style="{ '--hue': p.hue }">
                        <RoleArt v-if="p.role" :role="p.role" />
                        <template v-else>{{ p.initial }}</template>
                    </span>
                    <div class="name">{{ p.name }}</div>
                </div>
                <div class="tags">
                    <span v-for="([cls, text], i) in p.tags" :key="i" class="tag" :class="cls">{{ text }}</span>
                </div>
            </component>
        </div>
    </div>
</template>
