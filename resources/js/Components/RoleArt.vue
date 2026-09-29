<script setup>
// Original emblem art for each role, drawn as inline SVG so it's crisp at any
// size and needs no image files. role = null draws the card back.
import { useId } from 'vue';

defineProps({ role: { type: String, default: null } });

// Gradient ids must be unique on the page, and several cards can show at once.
const uid = useId();
const id = (name) => `${uid}-${name}`;
const url = (name) => `url(#${uid}-${name})`;
</script>

<template>
    <svg class="role-art" viewBox="0 0 200 200" role="img" :aria-label="role ?? 'Card back'">
        <defs>
            <radialGradient :id="id('good')" cx="50%" cy="38%" r="70%">
                <stop offset="0" stop-color="#2d4f86" /><stop offset="1" stop-color="#0d1526" />
            </radialGradient>
            <radialGradient :id="id('evil')" cx="50%" cy="38%" r="70%">
                <stop offset="0" stop-color="#6b1f2a" /><stop offset="1" stop-color="#1a080c" />
            </radialGradient>
            <radialGradient :id="id('back')" cx="50%" cy="45%" r="70%">
                <stop offset="0" stop-color="#3a3020" /><stop offset="1" stop-color="#110e08" />
            </radialGradient>
            <linearGradient :id="id('gold')" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#f3d488" /><stop offset="1" stop-color="#b88a2e" />
            </linearGradient>
            <linearGradient :id="id('steel')" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#e8edf5" /><stop offset="1" stop-color="#8793a8" />
            </linearGradient>
            <linearGradient :id="id('blood')" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#ff7a6e" /><stop offset="1" stop-color="#9c1f1f" />
            </linearGradient>
            <filter :id="id('glow')" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="4" result="b" /><feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
            </filter>
        </defs>

        <!-- Card back: the sword in the stone inside a ring of runes -->
        <g v-if="!role">
            <rect width="200" height="200" :fill="url('back')" />
            <circle cx="100" cy="100" r="78" fill="none" stroke="#d8ad4f" stroke-opacity=".35" stroke-width="1.5" stroke-dasharray="3 7" />
            <circle cx="100" cy="100" r="64" fill="none" stroke="#d8ad4f" stroke-opacity=".6" stroke-width="2" />
            <path d="M68 150h64l-10-18H78z" fill="#5b4a2c" stroke="#d8ad4f" stroke-width="1.5" />
            <path d="M100 36v96" :stroke="url('steel')" stroke-width="7" stroke-linecap="round" />
            <path d="M82 60h36" :stroke="url('gold')" stroke-width="7" stroke-linecap="round" />
            <circle cx="100" cy="46" r="6" :fill="url('gold')" />
        </g>

        <!-- Merlin: wizard hat under a starry sky with a crescent moon -->
        <g v-else-if="role === 'merlin'">
            <rect width="200" height="200" :fill="url('good')" />
            <g fill="#f3d488">
                <circle cx="40" cy="42" r="2" /><circle cx="160" cy="36" r="2.5" /><circle cx="150" cy="80" r="1.6" />
                <circle cx="52" cy="92" r="1.6" /><circle cx="30" cy="130" r="2" /><circle cx="172" cy="128" r="2" />
            </g>
            <path d="M150 50a18 18 0 1 1-20-22 14 14 0 1 0 20 22z" fill="#f3d488" :filter="url('glow')" />
            <path d="M100 28c-6 30-28 72-44 104h88c-10-26-20-52-26-70 10 2 18 0 22-6-14 2-26-8-40-28z" fill="#3f6fc4" stroke="#9fc2ff" stroke-width="2" />
            <path d="M78 104l6-6 6 6-6 6zM110 84l5-5 5 5-5 5zM98 118l4-4 4 4-4 4z" fill="#f3d488" />
            <ellipse cx="100" cy="136" rx="62" ry="12" fill="#2c4f8f" stroke="#9fc2ff" stroke-width="2" />
            <path d="M52 150c16 12 80 12 96 0" fill="none" stroke="#f3d488" stroke-width="3" stroke-linecap="round" opacity=".7" />
        </g>

        <!-- Percival: knight's great helm with a plume -->
        <g v-else-if="role === 'percival'">
            <rect width="200" height="200" :fill="url('good')" />
            <path d="M104 30c26 0 40 16 36 34-10-12-22-16-34-14" fill="#5b9cf0" stroke="#9fc2ff" stroke-width="2" />
            <path d="M62 80c0-26 16-40 38-40s38 14 38 40v56c0 8-6 14-14 14H76c-8 0-14-6-14-14z" :fill="url('steel')" stroke="#dfe6f2" stroke-width="2" />
            <path d="M70 96h60v10H70z" fill="#1b2336" />
            <path d="M100 106v34" stroke="#1b2336" stroke-width="5" />
            <g fill="#1b2336"><circle cx="84" cy="124" r="2.5" /><circle cx="84" cy="134" r="2.5" /><circle cx="116" cy="124" r="2.5" /><circle cx="116" cy="134" r="2.5" /></g>
            <path d="M100 40v56" stroke="#d8ad4f" stroke-width="3" />
            <path d="M58 160h84" stroke="#d8ad4f" stroke-width="4" stroke-linecap="round" />
        </g>

        <!-- Loyal Servant: heater shield with a cross -->
        <g v-else-if="role === 'servant'">
            <rect width="200" height="200" :fill="url('good')" />
            <path d="M100 30l52 16v42c0 38-24 64-52 82-28-18-52-44-52-82V46z" fill="#2c5aa8" :stroke="url('gold')" stroke-width="5" />
            <path d="M100 52v96M68 88h64" :stroke="url('gold')" stroke-width="12" stroke-linecap="round" />
            <path d="M100 30l52 16v42c0 38-24 64-52 82" fill="#ffffff" opacity=".06" />
        </g>

        <!-- Assassin: dagger dripping blood -->
        <g v-else-if="role === 'assassin'">
            <rect width="200" height="200" :fill="url('evil')" />
            <g transform="rotate(35 100 100)">
                <path d="M100 26l12 20v68h-24V46z" :fill="url('steel')" stroke="#f1f4f9" stroke-width="1.5" />
                <path d="M100 30v84" stroke="#8793a8" stroke-width="2" />
                <path d="M72 114h56" :stroke="url('gold')" stroke-width="10" stroke-linecap="round" />
                <path d="M94 120h12v34h-12z" fill="#4a2a1a" />
                <circle cx="100" cy="162" r="9" :fill="url('gold')" />
            </g>
            <path d="M58 60c0 8 10 12 10 20a8 8 0 0 1-16 0c0-8 6-12 6-20z" :fill="url('blood')" />
            <path d="M44 96c0 5 6 8 6 12a5 5 0 0 1-10 0c0-4 4-7 4-12z" :fill="url('blood')" />
        </g>

        <!-- Morgana: crescent moon cradling a watching eye -->
        <g v-else-if="role === 'morgana'">
            <rect width="200" height="200" :fill="url('evil')" />
            <path d="M132 40a66 66 0 1 0 0 120 54 54 0 1 1 0-120z" fill="#b576e0" :filter="url('glow')" opacity=".9" />
            <path d="M78 100c14-18 46-18 60 0-14 18-46 18-60 0z" fill="#1a080c" stroke="#e7c3ff" stroke-width="3" />
            <circle cx="108" cy="100" r="10" fill="#ff5a5a" :filter="url('glow')" />
            <circle cx="108" cy="100" r="4" fill="#1a080c" />
            <g fill="#e7c3ff"><circle cx="150" cy="54" r="2" /><circle cx="160" cy="140" r="2.5" /><circle cx="44" cy="150" r="1.8" /></g>
        </g>

        <!-- Mordred: black crown above crossed swords -->
        <g v-else-if="role === 'mordred'">
            <rect width="200" height="200" :fill="url('evil')" />
            <path d="M60 164l80-80M140 164L60 84" :stroke="url('steel')" stroke-width="7" stroke-linecap="round" />
            <path d="M52 150l16 16M148 150l-16 16" :stroke="url('gold')" stroke-width="7" stroke-linecap="round" />
            <path d="M52 96l12-50 22 26 14-36 14 36 22-26 12 50z" fill="#241016" :stroke="url('gold')" stroke-width="4" stroke-linejoin="round" />
            <path d="M52 96h96v14H52z" fill="#241016" :stroke="url('gold')" stroke-width="4" />
            <g fill="#ff5a5a" :filter="url('glow')"><circle cx="76" cy="103" r="4" /><circle cx="100" cy="103" r="5" /><circle cx="124" cy="103" r="4" /></g>
        </g>

        <!-- Oberon: a lone hooded figure with one glowing eye -->
        <g v-else-if="role === 'oberon'">
            <rect width="200" height="200" :fill="url('evil')" />
            <path d="M100 34c-34 0-52 34-52 70v62h104v-62c0-36-18-70-52-70z" fill="#1c1a24" stroke="#6e5a8a" stroke-width="3" />
            <path d="M100 58c-20 0-30 20-30 40 0 14 12 26 30 26s30-12 30-26c0-20-10-40-30-40z" fill="#07060a" />
            <circle cx="100" cy="94" r="7" fill="#9dff8a" :filter="url('glow')" />
            <path d="M40 176h120" stroke="#6e5a8a" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 8" />
        </g>

        <!-- Minion of Mordred: hood with two burning eyes -->
        <g v-else>
            <rect width="200" height="200" :fill="url('evil')" />
            <path d="M100 38c-36 0-54 32-54 66v56c16 6 34 10 54 10s38-4 54-10v-56c0-34-18-66-54-66z" fill="#2a1216" stroke="#8a3a3a" stroke-width="3" />
            <path d="M100 62c-22 0-32 20-32 38 0 16 14 28 32 28s32-12 32-28c0-18-10-38-32-38z" fill="#0a0406" />
            <g fill="#ff6a3d" :filter="url('glow')"><path d="M82 94l12 4-12 4z" /><path d="M118 94l-12 4 12 4z" /></g>
        </g>
    </svg>
</template>
