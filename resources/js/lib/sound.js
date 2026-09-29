import { ref, watch } from 'vue';

// Small synthesized sound effects (Web Audio), so there are no audio files to load.
// Browsers only allow audio after the user interacts with the page, so the
// audio context is created lazily on the first sound after a click.

const STORE_KEY = 'avalon.muted';

export const muted = ref(readMuted());
watch(muted, (m) => { try { localStorage.setItem(STORE_KEY, m ? '1' : '0'); } catch { /* storage blocked */ } });

function readMuted() {
    try { return localStorage.getItem(STORE_KEY) === '1'; } catch { return false; }
}

let ctx = null;
function audio() {
    if (!ctx) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        ctx = new Ctx();
    }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
}

// Unlock audio on the first interaction so later sounds (e.g. "your turn") can play.
for (const ev of ['pointerdown', 'keydown']) {
    window.addEventListener(ev, () => audio(), { once: true, capture: true });
}

/** One note: frequency (Hz), start offset and length (s), waveform, volume. */
function note(freq, { at = 0, len = 0.2, type = 'sine', vol = 0.18, slide = null } = {}) {
    const a = audio();
    if (!a || a.state !== 'running') return;
    const t = a.currentTime + at;
    const osc = a.createOscillator();
    const gain = a.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    if (slide) osc.frequency.exponentialRampToValueAtTime(slide, t + len);
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(vol, t + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + len);
    osc.connect(gain).connect(a.destination);
    osc.start(t);
    osc.stop(t + len + 0.05);
}

const chord = (freqs, opts) => freqs.forEach((f) => note(f, opts));

/** Filtered noise burst, used for thunder. */
function noise({ at = 0, len = 1.5, vol = 0.25, freq = 400 } = {}) {
    const a = audio();
    if (!a || a.state !== 'running') return;
    const t = a.currentTime + at;
    const buf = a.createBuffer(1, Math.floor(a.sampleRate * len), a.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    const src = a.createBufferSource();
    src.buffer = buf;
    const filter = a.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = freq;
    const gain = a.createGain();
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(vol, t + 0.05);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + len);
    src.connect(filter).connect(gain).connect(a.destination);
    src.start(t);
}

const SOUNDS = {
    // Button press.
    click: () => note(660, { len: 0.06, type: 'triangle', vol: 0.08 }),
    // Another player's chat message.
    chat: () => { note(880, { len: 0.08, vol: 0.07 }); note(1320, { at: 0.07, len: 0.1, vol: 0.06 }); },
    // It's your move: a bright two-note chime.
    turn: () => { note(784, { len: 0.25, type: 'triangle' }); note(1175, { at: 0.12, len: 0.4, type: 'triangle' }); },
    // Game start: a short horn call.
    start: () => {
        note(392, { len: 0.25, type: 'sawtooth', vol: 0.09 });
        note(523, { at: 0.22, len: 0.25, type: 'sawtooth', vol: 0.09 });
        note(659, { at: 0.44, len: 0.6, type: 'sawtooth', vol: 0.1 });
    },
    // Card flip when revealing your role.
    flip: () => note(300, { len: 0.18, type: 'triangle', vol: 0.12, slide: 900 }),
    // Vote results.
    approved: () => { note(523, { len: 0.15 }); note(784, { at: 0.1, len: 0.3 }); },
    rejected: () => { note(392, { len: 0.15, type: 'square', vol: 0.07 }); note(262, { at: 0.12, len: 0.35, type: 'square', vol: 0.07 }); },
    // Quest results.
    success: () => {
        chord([523, 659, 784], { len: 0.35, type: 'triangle', vol: 0.1 });
        chord([659, 784, 1047], { at: 0.3, len: 0.7, type: 'triangle', vol: 0.1 });
    },
    fail: () => {
        note(196, { len: 0.5, type: 'sawtooth', vol: 0.1, slide: 98 });
        note(233, { at: 0.05, len: 0.5, type: 'sawtooth', vol: 0.07, slide: 117 });
    },
    // End of game, from your side's point of view.
    win: () => [523, 659, 784, 1047].forEach((f, i) => note(f, { at: i * 0.14, len: i === 3 ? 0.9 : 0.2, type: 'triangle', vol: 0.12 })),
    lose: () => [392, 349, 311, 262].forEach((f, i) => note(f, { at: i * 0.22, len: i === 3 ? 1 : 0.28, type: 'sine', vol: 0.14 })),
    // Assassin's strike.
    strike: () => note(1200, { len: 0.35, type: 'sawtooth', vol: 0.08, slide: 150 }),
    // The hunt for Merlin begins: a low, uneasy drone.
    dread: () => {
        note(55, { len: 3, type: 'sawtooth', vol: 0.07 });
        note(58.3, { len: 3, type: 'sawtooth', vol: 0.06 });
        note(82.4, { at: 0.4, len: 2.6, type: 'triangle', vol: 0.06, slide: 77.8 });
    },
    // One heartbeat: lub-dub.
    heartbeat: () => {
        note(62, { len: 0.16, vol: 0.22, slide: 40 });
        note(55, { at: 0.2, len: 0.2, vol: 0.16, slide: 36 });
    },
    // Distant thunder, to go with the lightning on the map.
    thunder: () => noise({ len: 2.2, vol: 0.18, freq: 260 }),
};

export function play(name) {
    if (muted.value) return;
    try { SOUNDS[name]?.(); } catch { /* audio unavailable */ }
}
