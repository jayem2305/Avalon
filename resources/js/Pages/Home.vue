<script setup>
import { onMounted, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Toasts from '../Components/Toasts.vue';

const props = defineProps({
    code: { type: String, default: '' },
    resume: { type: String, default: null },
});

const form = useForm({ name: '', code: props.code });
const submitting = ref(null); // 'join' or 'create', for the button spinner
const nameInput = ref(null);
const codeInput = ref(null);

onMounted(() => {
    try { form.name = localStorage.getItem('avalon.name') || ''; } catch { /* storage blocked */ }
    (form.name ? codeInput : nameInput).value?.focus();
});

function rememberName() {
    try { localStorage.setItem('avalon.name', form.name.trim()); } catch { /* storage blocked */ }
}

function join() {
    rememberName();
    form.code = form.code.toUpperCase();
    submitting.value = 'join';
    form.transform((data) => data).post('/join', { onFinish: () => { submitting.value = null; } });
}

function create() {
    rememberName();
    submitting.value = 'create';
    form.transform((data) => ({ name: data.name })).post('/rooms', { onFinish: () => { submitting.value = null; } });
}
</script>

<template>
    <div class="home">
        <div class="brand">
            <svg class="sigil" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.5" style="color: var(--gold)">
                <path d="M32 4v40M22 14h20M32 44l-8 8h16l-8-8z" /><circle cx="32" cy="32" r="28" opacity=".35" />
            </svg>
            <h1>AVALON</h1>
            <p>A game of hidden loyalty for 5–10 players</p>
        </div>

        <form class="panel stack" @submit.prevent="join">
            <div>
                <label class="field-label" for="name">Your name</label>
                <input id="name" ref="nameInput" v-model="form.name" type="text" maxlength="20" autocomplete="nickname" placeholder="e.g. Lancelot">
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>
            <div>
                <label class="field-label" for="code">Room code</label>
                <input id="code" ref="codeInput" v-model="form.code" type="text" class="code-input" maxlength="4" placeholder="ABCD" autocomplete="off">
                <p v-if="form.errors.code" class="field-error">{{ form.errors.code }}</p>
            </div>
            <button class="btn primary block big" :disabled="form.processing" :aria-busy="submitting === 'join'">Join room</button>
            <div class="divider">or</div>
            <button type="button" class="btn block" :disabled="form.processing" :aria-busy="submitting === 'create'" @click="create">Create a new room</button>
        </form>

        <p v-if="resume" class="footnote">
            <Link :href="`/r/${resume}`" class="btn ghost small">Return to room {{ resume }}</Link>
        </p>
        <p class="footnote">Everyone plays on their own phone or computer. Chat in the room, or talk over voice or in person.</p>
        <Toasts />
    </div>
</template>
