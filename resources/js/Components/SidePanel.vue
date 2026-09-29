<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoom } from '../composables/useRoom';

const { view, act, pending } = useRoom();

const tab = ref('chat');
const draft = ref('');
const feed = ref(null);
const seenChat = ref(view.value.chat.length);

const unread = computed(() => tab.value !== 'chat' && view.value.chat.length !== seenChat.value);

function scrollToBottom(force = false) {
    const el = feed.value;
    if (!el) return;
    const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    nextTick(() => { if (force || nearBottom) el.scrollTop = el.scrollHeight; });
}

watch(() => [view.value.chat.length, view.value.log.length], () => {
    if (tab.value === 'chat') seenChat.value = view.value.chat.length;
    scrollToBottom();
});

function show(name) {
    tab.value = name;
    if (name === 'chat') seenChat.value = view.value.chat.length;
    scrollToBottom(true);
}

function send() {
    const text = draft.value.trim();
    if (!text) return;
    draft.value = '';
    show('chat');
    act('chat', { text });
}

onMounted(() => scrollToBottom(true));
</script>

<template>
    <aside class="panel side-feed">
        <div class="side-tabs">
            <button :class="{ active: tab === 'chat' }" @click="show('chat')">
                Chat<span v-if="unread" class="dot" />
            </button>
            <button :class="{ active: tab === 'log' }" @click="show('log')">Game log</button>
        </div>
        <div ref="feed" class="feed">
            <template v-if="tab === 'chat'">
                <div v-for="(m, i) in view.chat" :key="i" class="msg" :class="{ mine: m.pid === view.meId }">
                    <b>{{ m.name }}</b> {{ m.text }}
                </div>
                <div v-if="!view.chat.length" class="empty">No messages yet.</div>
            </template>
            <template v-else>
                <div v-for="(l, i) in view.log" :key="i" class="event">{{ l.text }}</div>
            </template>
        </div>
        <form class="chat-form" @submit.prevent="send">
            <input v-model="draft" type="text" maxlength="300" placeholder="Say something…" autocomplete="off">
            <button class="btn small primary" :aria-busy="pending === 'chat'">Send</button>
        </form>
    </aside>
</template>
