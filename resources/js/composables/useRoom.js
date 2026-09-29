import { computed, inject, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { toast } from '../lib/toast';
import { play } from '../lib/sound';

const KEY = Symbol('room');

// Safety net in case a websocket message is missed.
const FALLBACK_POLL_MS = { connected: 20000, disconnected: 3000 };

/**
 * Owns the live room state for the Room page: applies server responses,
 * listens for Reverb "updated" pings and exposes helpers to child components.
 */
export function provideRoom(initial) {
    const view = ref(initial);
    const connected = ref(false);
    const code = initial.code;
    const ui = reactive({ selected: [], roleOpen: false });

    function apply(next) {
        // Ignore stale responses that arrive after a newer state.
        if (next.version >= view.value.version) view.value = next;
    }

    function handleError(e) {
        const data = e.response?.data;
        if (data?.kind === 'no_room' || data?.kind === 'not_player') {
            toast(data.kind === 'no_room' ? 'That room no longer exists.' : 'You are no longer in this room.', true);
            router.visit('/');
        } else if (e.response?.status === 419) {
            toast('Your session expired. Reloading…', true);
            setTimeout(() => location.reload(), 1200);
        } else {
            toast(data?.message || 'Could not reach the server.', true);
        }
    }

    // Name of the action being sent right now, so buttons can show a spinner.
    const pending = ref(null);

    async function act(action, data = {}) {
        if (action !== 'chat') play('click');
        pending.value = action;
        try {
            const res = await axios.post(`/r/${code}/act/${action}`, data);
            if (res.data.left) return router.visit('/');
            apply(res.data.state);
        } catch (e) {
            handleError(e);
        } finally {
            if (pending.value === action) pending.value = null;
        }
    }

    let refreshing = false;
    async function refresh() {
        if (refreshing) return;
        refreshing = true;
        try {
            const res = await axios.get(`/r/${code}/state`, { params: { v: view.value.version } });
            if (res.data.state) apply(res.data.state);
        } catch (e) {
            if (e.response) handleError(e);
        } finally {
            refreshing = false;
        }
    }

    let timer = null;
    function schedulePoll() {
        clearTimeout(timer);
        const ms = connected.value ? FALLBACK_POLL_MS.connected : FALLBACK_POLL_MS.disconnected;
        timer = setTimeout(async () => {
            if (!document.hidden) await refresh();
            schedulePoll();
        }, ms);
    }

    const pusher = () => window.Echo?.connector?.pusher;
    const onState = ({ current }) => {
        const was = connected.value;
        connected.value = current === 'connected';
        if (connected.value && !was) refresh(); // catch up on anything missed
        schedulePoll();
    };
    const onVisible = () => { if (!document.hidden) refresh(); };

    onMounted(() => {
        window.Echo.channel(`room.${code}`).listen('.updated', (e) => {
            if (e.version > view.value.version) refresh();
        });
        pusher()?.connection.bind('state_change', onState);
        connected.value = pusher()?.connection.state === 'connected';
        document.addEventListener('visibilitychange', onVisible);
        schedulePoll();
    });

    onBeforeUnmount(() => {
        clearTimeout(timer);
        window.Echo.leave(`room.${code}`);
        pusher()?.connection.unbind('state_change', onState);
        document.removeEventListener('visibilitychange', onVisible);
    });

    // Clear any half-made selection whenever the game moves on.
    watch(
        () => { const v = view.value; return [v.phase, v.questNum, v.proposals.length, v.ladyChecks.length].join('|'); },
        () => { ui.selected = []; },
    );
    // Keep the role card hidden when a new game starts, in case someone is peeking.
    watch(() => view.value.phase, (now, before) => { if (before === 'lobby' || before === 'ended') ui.roleOpen = false; });

    /** True when the game in state v is waiting on the current player. */
    function isMyTurn(v) {
        const me = v.meId;
        switch (v.phase) {
            case 'proposing': return v.leaderId === me;
            case 'voting': return v.myVote === null;
            case 'questing': return v.team.includes(me) && !v.myCard;
            case 'lady': return v.lady?.holderId === me;
            case 'assassin': return v.assassinId === me;
            default: return false;
        }
    }
    const myTurn = computed(() => isMyTurn(view.value));

    // Sound effects for what changed between two states, spaced out so they
    // don't overlap when several things happen at once (e.g. bots finishing a quest).
    watch(view, (next, prev) => {
        if (!prev || next.version <= prev.version) return;
        const queue = [];
        const newGame = (prev.phase === 'lobby' || prev.phase === 'ended') && next.phase !== 'lobby' && next.phase !== 'ended';
        if (newGame) queue.push('start');
        if (next.phase === 'assassin' && prev.phase !== 'assassin') queue.push('dread');
        if (next.proposals.length > prev.proposals.length) {
            queue.push(next.proposals[next.proposals.length - 1].approved ? 'approved' : 'rejected');
        }
        if (next.questResults.length > prev.questResults.length) {
            queue.push(next.questResults[next.questResults.length - 1].success ? 'success' : 'fail');
        }
        if (next.phase === 'ended' && prev.phase !== 'ended') {
            if (next.assassinTarget) queue.push('strike');
            queue.push(next.me && (next.me.evil ? 'evil' : 'good') === next.winner ? 'win' : 'lose');
        } else if (isMyTurn(next) && !isMyTurn(prev)) {
            queue.push('turn');
        }
        const lastChat = next.chat[next.chat.length - 1];
        if (next.chat.length !== prev.chat.length && lastChat && lastChat.pid !== next.meId) queue.push('chat');
        queue.forEach((name, i) => setTimeout(() => play(name), i * 700));
    });

    // Mood of the whole page: 'hunt' while the Assassin looks for Merlin,
    // 'fallen' if Merlin was found. Applied as <html data-scene="…">.
    const scene = computed(() => {
        const v = view.value;
        if (v.phase === 'assassin') return 'hunt';
        const target = v.phase === 'ended' && v.players.find((p) => p.id === v.assassinTarget);
        return target && target.role === 'merlin' ? 'fallen' : null;
    });
    let heartbeat = null;
    watch(scene, (s) => {
        const root = document.documentElement;
        if (s) root.dataset.scene = s;
        else delete root.dataset.scene;
        clearInterval(heartbeat);
        if (s === 'hunt') heartbeat = setInterval(() => play('heartbeat'), 1500);
    }, { immediate: true });
    onBeforeUnmount(() => {
        clearInterval(heartbeat);
        delete document.documentElement.dataset.scene;
    });

    const playerName = (id) => view.value.players.find((p) => p.id === id)?.name ?? '?';
    const names = (ids) => ids.map(playerName).join(', ');
    const isHost = computed(() => view.value.hostId === view.value.meId);

    /** Which players the current user may tap right now, and why. */
    const selection = computed(() => {
        const v = view.value;
        const me = v.meId;
        if (v.phase === 'proposing' && v.leaderId === me) {
            return { kind: 'team', max: v.teamSizes[v.questNum], can: () => true };
        }
        if (v.phase === 'lady' && v.lady?.holderId === me) {
            return { kind: 'lady', max: 1, can: (p) => p.id !== me && !v.lady.previous.includes(p.id) };
        }
        if (v.phase === 'assassin' && v.assassinId === me) {
            return { kind: 'assassin', max: 1, can: (p) => !p.evil };
        }
        return null;
    });

    function togglePick(id) {
        const mode = selection.value;
        if (!mode) return;
        if (ui.selected.includes(id)) {
            ui.selected = ui.selected.filter((x) => x !== id);
        } else if (mode.max === 1) {
            ui.selected = [id];
        } else if (ui.selected.length < mode.max) {
            ui.selected = [...ui.selected, id];
        } else {
            toast(`The team has only ${mode.max} spots. Deselect someone first.`);
        }
    }

    const ctx = { view, connected, ui, act, pending, scene, isHost, myTurn, playerName, names, selection, togglePick };
    provide(KEY, ctx);
    return ctx;
}

export function useRoom() {
    return inject(KEY);
}
