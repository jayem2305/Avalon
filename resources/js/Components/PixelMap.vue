<script setup>
// A live pixel-art view of the game: every player is a little knight walking
// around the courtyard of Camelot. The scene follows the game state (quest
// teams march to the gate, votes pop up as bubbles, Evil plots before the
// assassination) and is purely a picture of the same data as the cards below;
// clicking a knight picks them, just like clicking their card.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoom } from '../composables/useRoom';
import { play } from '../lib/sound';

const { view, ui, scene, selection, togglePick } = useRoom();

// Logical resolution: everything is drawn on this pixel grid, then scaled up crisply.
const W = 256;
const BASE_H = 160; // the courtyard scene itself
const MAX_H = 420;
// The scene grows downward (more meadow) to fill tall spaces instead of leaving empty bars.
let H = BASE_H;
const TABLE = { x: 128, y: 104 };
const SPEED = 26; // pixels per second

const canvas = ref(null);
const hidden = ref(readHidden());
const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

function readHidden() {
    try { return localStorage.getItem('avalon.map') === 'hidden'; } catch { return false; }
}
watch(hidden, (h) => { try { localStorage.setItem('avalon.map', h ? 'hidden' : 'shown'); } catch { /* storage blocked */ } });

// ---------- Drawing helpers ----------

function seeded(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function px(g, x, y, color, w = 1, h = 1) {
    g.fillStyle = color;
    g.fillRect(Math.round(x), Math.round(y), w, h);
}

function ellipse(g, cx, cy, rx, ry, color) {
    for (let y = Math.floor(cy - ry); y <= cy + ry; y++) {
        for (let x = Math.floor(cx - rx); x <= cx + rx; x++) {
            const d = ((x + 0.5 - cx) / rx) ** 2 + ((y + 0.5 - cy) / ry) ** 2;
            if (d <= 1) {
                const c = typeof color === 'function' ? color(x, y, d) : color;
                if (c) px(g, x, y, c);
            }
        }
    }
}

function glyph(g, rows, x, y, color) {
    rows.forEach((row, j) => [...row].forEach((ch, i) => { if (ch === '#') px(g, x + i, y + j, color); }));
}

// ---------- Static background (built once) ----------

function buildBackground() {
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    const g = c.getContext('2d');
    const r = seeded(7);

    // Grass with some texture and flowers.
    px(g, 0, 0, '#3c7437', W, H);
    for (let i = 0; i < W * H * 0.14; i++) px(g, r() * W, r() * H, r() < 0.5 ? '#34672f' : '#47843f');
    for (let i = 0; i < 80 * (H / BASE_H); i++) px(g, r() * W, 44 + r() * (H - 44), ['#f3d488', '#f5f5f5', '#e98bb5', '#9fc2ff'][(r() * 4) | 0]);

    // Cobbled courtyard and the path to the gate.
    const cobble = (x, y) => (((x + ((y >> 2) & 1) * 3) % 6 === 0 || y % 4 === 0)
        ? '#857b69'
        : ['#a0957f', '#a89c86', '#968b76'][(r() * 3) | 0]);
    ellipse(g, TABLE.x, TABLE.y + 2, 72, 44, cobble);
    for (let y = 34; y < 70; y++) for (let x = 114; x < 143; x++) px(g, x, y, cobble(x, y));
    for (let y = 34; y < 66; y++) { px(g, 113, y, '#6f6656'); px(g, 143, y, '#6f6656'); }

    // Castle wall with crenellations.
    const brick = (x, y, base, mortar) => ((y % 4 === 3 || (x + ((y >> 2) & 1) * 4) % 8 === 7) ? mortar : base);
    for (let y = 0; y < 34; y++) {
        for (let x = 0; x < W; x++) px(g, x, y, brick(x, y, r() < 0.1 ? '#77737d' : '#6e6a75', '#55525c'));
    }
    for (let x = 0; x < W; x++) if (x % 10 >= 7) px(g, x, 0, '#2b2a33', 1, 3);
    px(g, 0, 34, '#2f5f2b', W, 2);

    // Corner towers.
    for (const tx of [0, 226]) {
        for (let y = 0; y < 46; y++) for (let x = tx; x < tx + 30; x++) px(g, x, y, brick(x, y, '#615d68', '#4a4751'));
        for (let x = tx; x < tx + 30; x++) if ((x - tx) % 8 >= 5) px(g, x, 0, '#23222a', 1, 4);
        px(g, tx + 13, 18, '#1d1a22', 3, 8);
        px(g, tx, 46, '#2f5f2b', 30, 2);
    }

    // Gate: stone frame, dark arch, portcullis.
    const inArch = (x, y, rad) => (y >= 26 || (x - 128) ** 2 + (y - 26) ** 2 <= rad * rad);
    for (let y = 12; y < 34; y++) {
        for (let x = 112; x < 145; x++) {
            if (inArch(x, y, 12)) px(g, x, y, '#1d1a22');
            else if (inArch(x, y, 15)) px(g, x, y, '#8b8794');
        }
    }
    for (let y = 14; y < 34; y++) for (let x = 117; x < 140; x += 3) if (inArch(x, y, 12)) px(g, x, y, '#4a4452');
    for (const y of [22, 28]) for (let x = 116; x < 141; x++) if (inArch(x, y, 12)) px(g, x, y, '#4a4452');

    // Torch brackets beside the gate.
    for (const tx of [104, 151]) px(g, tx, 20, '#5b3a22', 2, 5);

    // Trees and bushes along the sides.
    const trees = [[14, 72], [28, 98], [11, 124], [32, 150], [243, 74], [229, 101], [246, 128], [224, 152], [200, 150], [58, 156]];
    for (let y = BASE_H + 22; y < H - 4; y += 26) {
        for (let i = 0; i < 5; i++) trees.push([12 + r() * 232, y + r() * 12 - 6]);
    }
    // A few rocks in the meadow.
    for (let i = 0; i < (H - BASE_H) / 8; i++) {
        const x = r() * W;
        const y = BASE_H + 6 + r() * (H - BASE_H - 8);
        px(g, x, y, '#8a8577', 3, 2);
        px(g, x, y, '#a39e8f', 2, 1);
    }
    for (const [x, y] of trees.sort((a, b) => a[1] - b[1])) {
        ellipse(g, x, y + 4, 9, 3, '#2f5f2b');
        px(g, x - 1, y - 2, '#5b3a22', 3, 6);
        ellipse(g, x, y - 8, 10, 8, (qx, qy, d) => (d > 0.82 ? '#24491f' : (qx + qy) % 5 === 0 ? '#4f9446' : qy < y - 11 ? '#46873e' : '#34692e'));
    }

    // The Round Table.
    ellipse(g, TABLE.x, TABLE.y + 4, 28, 12, '#6f6656');
    ellipse(g, TABLE.x, TABLE.y, 26, 11, (x, y, d) => (d > 0.78 ? '#5a3820' : y % 3 === 0 ? '#84573a' : '#7a4e2c'));
    ellipse(g, TABLE.x, TABLE.y, 7, 3, (x, y, d) => (d > 0.55 ? '#d8ad4f' : null));
    px(g, TABLE.x - 1, TABLE.y - 1, '#e8edf5', 3, 2);

    return c;
}

// ---------- Knights and costumes ----------

// Every sprite is 8 wide and 14 tall (rows 0-1 leave room for hats and plumes).
// `top` is rows 0-10. The bottom is either legs (3 rows from LEGS) or a robe
// (2 rows of cloth plus feet from FEET). `back` is the colour key that covers
// the face when the character walks away from us. During the game everyone is
// dressed as a knight, so nobody's look gives their role away; at the end each
// player changes into the costume of their role.
const LEGS = {
    stand: ['.LL..LL.', '.LL..LL.', '.BB..BB.'],
    a: ['.LL..LL.', '.LL...LL', '.BB...BB'],
    b: ['.LL..LL.', 'LL...LL.', 'BB...BB.'],
};
const FEET = { stand: '.BB..BB.', a: '.BB...BB', b: 'BB...BB.' };

const KNIGHT_TOP = [
    '........', '........', '..HHHH..', '.HHHHHH.', '.HVVVVH.', '.HHHHHH.',
    '..GGGG..', '.ATTTTA.', 'AATTTTAA', 'GATTTTAG', '.DTTTTD.',
];
const withRows = (rows, changes) => rows.map((r, i) => changes[i] ?? r);

const COSTUMES = {
    knight: { top: KNIGHT_TOP, back: 'H' },
    percival: {
        top: withRows(KNIGHT_TOP, { 0: '....PP..', 1: '...PP...', 7: '.AYTTYA.', 10: '.YTTTTY.' }),
        back: 'H',
        colors: { T: '#e8edf5', D: '#b8c0cc', P: '#e0605a', Y: '#f3d488' },
    },
    servant: {
        top: withRows(KNIGHT_TOP, { 7: '.ATWWTA.', 8: 'AAWWWWAA', 9: 'GATWWTAG', 10: '.DTWWTD.' }),
        back: 'H',
        colors: { T: '#2c5aa8', D: '#1f437f', W: '#f4f1e8' },
    },
    merlin: {
        top: [
            '.....HH.', '....HHH.', '...HYHH.', '..HHHHH.', 'HHHHHHHH', '.SESSES.',
            '.WWWWWW.', '.RWWWWR.', 'RRRWWRRR', 'SRRWRRRS', '.RRRRRR.',
        ],
        robe: ['.RRRRRR.', 'RRRRRRRR'],
        back: 'H',
        colors: { H: '#3f6fc4', Y: '#f3d488', W: '#f4f1e8', R: '#2c4f8f' },
    },
    assassin: {
        top: [
            '........', '........', '..KKKK..', '.KKKKKK.', '.KEMMEK.', '.KMMMMK.',
            '..RRRR..', '.KKKKKK.', 'KKKKKKKK', 'SKKKKKKZ', '.KKKKKK.',
        ],
        back: 'K',
        colors: { K: '#1c1a24', M: '#3a3644', E: '#ff3b3b', R: '#b3262b', Z: '#dfe6f2', L: '#26222e' },
    },
    morgana: {
        top: [
            '........', '..Y.Y...', '..PPPP..', '.PPYPPP.', '.PSSSSP.', 'PSESSESP',
            'PPSSSSPP', 'PVVVVVVP', 'VVVVVVVV', 'SVVVVVVS', '.VVVVVV.',
        ],
        robe: ['.VVVVVV.', 'VVVVVVVV'],
        back: 'P',
        colors: { P: '#2b1838', Y: '#f3d488', V: '#8a3fc2', E: '#b576e0' },
    },
    mordred: {
        top: [
            '........', '.Y.YY.Y.', '.YYYYYY.', '.KKKKKK.', '.KRKKRK.', '.KKKKKK.',
            '..NNNN..', '.NKKKKN.', 'NNKRRKNN', 'GNKKKKNG', '.NKKKKN.',
        ],
        back: 'K',
        colors: { K: '#1e1b24', N: '#3b3746', R: '#ff3b3b', Y: '#f3d488', G: '#55505f', L: '#2d2a35' },
    },
    oberon: {
        top: [
            '........', '........', '..OOOO..', '.OOOOOO.', '.OKKKKO.', '.OKGKKO.',
            '.OOOOOO.', 'OOOOOOOO', 'OOOOOOOO', 'SOOOOOOS', '.OOOOOO.',
        ],
        robe: ['.OOOOOO.', 'OOOOOOOO'],
        back: 'O',
        colors: { O: '#5d5968', K: '#07060a', G: '#9dff8a' },
    },
    minion: {
        top: [
            '........', '.X....X.', '.XRRRRX.', '.RRRRRR.', '.RKKKKR.', '.RKEEKR.',
            '..RRRR..', '.DRRRRD.', 'DDRRRRDD', 'SDRRRRDS', '.DRRRRD.',
        ],
        back: 'R',
        colors: { R: '#8a2a2a', D: '#5a1a1a', K: '#150708', E: '#ff9f1c', X: '#e8dcc0', L: '#3a2626' },
    },
};

const SKINS = ['#f1c7a0', '#d9a577', '#a86f4a'];

function palette(p, costume, redEyes) {
    const hue = (p.seat * 137) % 360;
    return {
        // Knight armour; the tabard carries each player's own colour.
        H: '#c9d1dc', V: redEyes ? '#ff3b3b' : '#1b1d26', G: '#8a93a3', A: '#aeb7c4',
        T: `hsl(${hue} 60% 52%)`, D: `hsl(${hue} 60% 36%)`,
        S: SKINS[p.seat % 3], E: '#1b1b22', L: '#6f7888', B: '#2a211c',
        ...(COSTUMES[costume].colors ?? {}),
    };
}

// Sprites are 8x14 plus a 1px outline, rendered once and cached.
const spriteCache = new Map();
function sprite(p, costume, facing, legs, redEyes) {
    const key = `${p.id}|${costume}|${facing}|${legs}|${redEyes}`;
    let c = spriteCache.get(key);
    if (c) return c;
    const def = COSTUMES[costume];
    let top = def.top;
    if (facing === 'up') {
        // Seen from behind: the face area is covered by helmet, hood or hair.
        top = top.map((row, i) => (i >= 2 && i <= 6 ? row.replace(/[SEVMG]/g, def.back) : row));
    }
    const bottom = def.robe ? [...def.robe, FEET[legs]] : LEGS[legs];
    const rows = [...top, ...bottom];
    const pal = palette(p, costume, redEyes);
    c = document.createElement('canvas');
    c.width = 10;
    c.height = 16;
    const g = c.getContext('2d');
    rows.forEach((row, y) => [...row].forEach((ch, x) => {
        if (ch === '.') return;
        for (const [dx, dy] of [[-1, 0], [1, 0], [0, -1], [0, 1]]) px(g, x + 1 + dx, y + 1 + dy, 'rgba(10,8,14,.75)');
    }));
    rows.forEach((row, y) => [...row].forEach((ch, x) => { if (ch !== '.') px(g, x + 1, y + 1, pal[ch] ?? '#ff00ff'); }));
    spriteCache.set(key, c);
    return c;
}

const GLYPHS = {
    check: ['....#', '...#.', '#.#..', '.#...'],
    cross: ['#...#', '.#.#.', '..#..', '.#.#.', '#...#'],
    1: ['.#.', '##.', '.#.', '.#.', '###'],
    2: ['##.', '..#', '.#.', '#..', '###'],
    3: ['##.', '..#', '.#.', '..#', '##.'],
    4: ['#.#', '#.#', '###', '..#', '..#'],
    5: ['###', '#..', '##.', '..#', '##.'],
};

// ---------- Where everyone should be ----------

function seatOf(i, n) {
    const a = Math.PI / 2 + (i / n) * Math.PI * 2;
    return { x: TABLE.x + Math.cos(a) * 54, y: TABLE.y + 4 + Math.sin(a) * 32 };
}

const actors = new Map(); // player id -> { x, y, tx, ty, face, flip, moving, walkT, wanderAt }
let revealAt = -Infinity; // when the latest vote result appeared (for the ✓/✗ bubbles)
// A finished quest's team walks to the gate and back, even when bots played their cards instantly.
let trip = { team: [], until: -Infinity };

function placeAll(snap) {
    const v = view.value;
    for (const p of v.players) {
        const home = seatOf(p.seat, v.players.length);
        let a = actors.get(p.id);
        if (!a) {
            a = { x: home.x, y: home.y, tx: home.x, ty: home.y, face: 'down', flip: false, moving: false, walkT: 0, wanderAt: 0 };
            actors.set(p.id, a);
        }
        if (snap) Object.assign(a, { x: home.x, y: home.y, tx: home.x, ty: home.y });
    }
}

function assignTargets(now) {
    const v = view.value;
    const n = v.players.length;
    const evil = v.players.filter((p) => p.evil);
    for (const p of v.players) {
        const a = actors.get(p.id);
        const home = seatOf(p.seat, n);
        let spot = null;
        let face = 'down';

        const questTeam = v.phase === 'questing' ? v.team : now < trip.until ? trip.team : [];
        const teamAt = questTeam.indexOf(p.id);
        if (teamAt !== -1) {
            // March to the gate for the quest.
            spot = { x: TABLE.x + (teamAt - (questTeam.length - 1) / 2) * 15, y: 46 + (teamAt % 2) * 5 };
            face = 'up';
        } else if (v.phase === 'assassin' && p.evil) {
            // Evil huddles by the trees to plot.
            const k = evil.indexOf(p);
            const ang = (k / evil.length) * Math.PI * 2;
            spot = { x: 60 + Math.cos(ang) * 10, y: 132 + Math.sin(ang) * 5 };
        } else if (v.phase === 'ended' && v.assassinTarget && p.id === v.assassinId) {
            const target = v.players.find((x) => x.id === v.assassinTarget);
            const t = seatOf(target.seat, n);
            spot = { x: t.x + (t.x < TABLE.x ? 16 : -16), y: t.y + 3 };
        }

        if (spot) {
            a.tx = spot.x;
            a.ty = spot.y;
            a.restFace = face;
        } else {
            a.restFace = 'down';
            const offHome = Math.hypot(a.tx - home.x, a.ty - home.y) > 8;
            if (offHome || (!reducedMotion && now > a.wanderAt)) {
                // Idle: shuffle around your seat now and then.
                const r = reducedMotion ? 0 : 1;
                a.tx = home.x + (Math.random() * 8 - 4) * r;
                a.ty = home.y + (Math.random() * 6 - 3) * r;
                a.wanderAt = now + 2500 + Math.random() * 4000;
            }
        }
    }
}

function step(dt) {
    for (const a of actors.values()) {
        const dx = a.tx - a.x;
        const dy = a.ty - a.y;
        const dist = Math.hypot(dx, dy);
        if (dist < 0.4) {
            a.moving = false;
            a.face = a.restFace ?? 'down';
            continue;
        }
        const move = Math.min(dist, SPEED * dt * (reducedMotion ? 4 : 1));
        a.x += (dx / dist) * move;
        a.y += (dy / dist) * move;
        a.moving = true;
        a.walkT += dt;
        a.face = Math.abs(dy) > Math.abs(dx) && dy < 0 ? 'up' : 'down';
        if (Math.abs(dx) > 0.2) a.flip = dx < 0;
    }
}

// ---------- Per-frame drawing ----------

let background = null;
let scale = 2;
const confetti = Array.from({ length: 70 }, () => ({ x: Math.random() * W, y: Math.random() * H, s: 12 + Math.random() * 22, c: Math.random() }));

function drawScene(g, now, dt) {
    const v = view.value;
    const t = now / 1000;
    const mode = selection.value;
    g.setTransform(scale, 0, 0, scale, 0, 0);
    g.imageSmoothingEnabled = false;
    g.drawImage(background, 0, 0);

    // Torch flames flicker.
    for (const [i, tx] of [104, 151].entries()) {
        const f = Math.sin(t * 13 + i * 2) > 0;
        px(g, tx, 17, f ? '#ffd166' : '#ff9f1c', 2, 3);
        px(g, tx + (f ? 1 : 0), 16, '#ff6b35');
    }

    // Banners on the towers wave; they take the winner's colour at the end.
    const bannerColor = v.phase === 'ended' ? (v.winner === 'good' ? '#5b9cf0' : '#e0605a') : '#d8ad4f';
    for (const tx of [14, 240]) {
        px(g, tx, 2, '#3a3238', 1, 14);
        for (let y = 0; y < 5; y++) {
            const wave = Math.round(Math.sin(t * 4 + y * 0.9) * 0.8);
            px(g, tx + 1, 3 + y, bannerColor, 6 + wave, 1);
        }
    }

    // Quest shields on the wall: blue = success, red = fail, gold edge = current.
    v.teamSizes.forEach((size, i) => {
        const r = v.questResults[i];
        const x = TABLE.x + (i - 2) * 16 - 4;
        const current = !r && i === v.questNum && v.phase !== 'ended';
        const fill = r ? (r.success ? '#5b9cf0' : '#e0605a') : '#3a3f4d';
        const edge = current && Math.sin(t * 5) > -0.3 ? '#f3d488' : '#23222a';
        const shape = ['#########', '#########', '#########', '#########', '#########', '#########', '.#######.', '..#####..', '...###...', '....#....'];
        glyph(g, shape, x - 1, 1, edge);
        glyph(g, shape.slice(0, 9).map((row) => row.slice(1, 8)), x, 2, fill);
        glyph(g, GLYPHS[size], x + 2, 3, r ? '#0d1526' : '#c9cbd3');
        if (v.twoFailQuest === i) px(g, x + 3, 9, '#ff8a80', 1, 1);
    });

    // Candles on the table.
    for (const [i, cx] of [-12, 0, 12].entries()) {
        px(g, TABLE.x + cx, TABLE.y - 5 + (i === 1 ? -1 : 0), '#f5f0e6', 1, 2);
        px(g, TABLE.x + cx, TABLE.y - 6 + (i === 1 ? -1 : 0), Math.sin(t * 11 + i) > 0 ? '#ffd166' : '#ff9f1c');
    }

    // Knights, back to front.
    const lastProp = v.proposals[v.proposals.length - 1];
    const showVotes = lastProp && now - revealAt < 5000 && v.phase !== 'voting';
    const order = [...v.players].sort((p, q) => actors.get(p.id).y - actors.get(q.id).y);
    const labels = [];

    for (const p of order) {
        const a = actors.get(p.id);
        const x = Math.round(a.x);
        const y = Math.round(a.y);
        const struck = v.phase === 'ended' && v.assassinTarget === p.id;
        const winner = v.phase === 'ended' && p.role && ((['assassin', 'morgana', 'mordred', 'oberon', 'minion'].includes(p.role) ? 'evil' : 'good') === v.winner);
        const onTeam = v.team.includes(p.id) && (v.phase === 'voting' || v.phase === 'questing' || v.phase === 'proposing');

        // Ground marks: shadow, team ring, selection ring.
        px(g, x - 3, y, 'rgba(0,0,0,.28)', 7, 1);
        px(g, x - 2, y + 1, 'rgba(0,0,0,.2)', 5, 1);
        const picked = ui.selected.includes(p.id);
        const selectable = mode && mode.can(p);
        if (picked || onTeam || (selectable && Math.sin(t * 5) > 0) || p.evil) {
            const col = picked ? '#f3d488' : p.evil && !onTeam ? 'rgba(224,96,90,.8)' : onTeam ? 'rgba(216,173,79,.8)' : 'rgba(243,212,136,.5)';
            ellipse(g, x + 0.5, y + 0.5, 6.5, 3, (qx, qy, d) => (d > 0.55 ? col : null));
        }

        // The knight (lying down if struck by the Assassin).
        const legs = a.moving ? (Math.floor(a.walkT * 7) % 2 ? 'a' : 'b') : 'stand';
        let bob = a.moving && legs === 'a' ? -1 : 0;
        if (winner && !a.moving && !reducedMotion) bob -= Math.round(Math.abs(Math.sin(t * 5 + p.seat)) * 3);
        const costume = v.phase === 'ended' && p.role ? p.role : 'knight';
        const img = sprite(p, costume, a.face, legs, !!p.evil);
        g.save();
        if (struck) {
            g.translate(x + 7, y - 4);
            g.rotate(Math.PI / 2);
            g.drawImage(img, -5, -8);
        } else {
            g.translate(x, y + bob);
            if (a.flip) g.scale(-1, 1);
            g.drawImage(img, -5, -15);
        }
        g.restore();

        // Head decorations: crown for the leader, glowing orb for the Lady.
        const head = y + bob - 12; // top of the helmet
        if (!struck && p.id === v.leaderId && v.phase !== 'ended' && v.phase !== 'assassin') {
            glyph(g, ['#.#.#', '#####'], x - 3, head - 2, '#f3d488');
        }
        if (!struck && v.lady?.holderId === p.id && v.phase !== 'ended') {
            const f = Math.sin(t * 3 + p.seat);
            px(g, x + 5, head + 2 + Math.round(f), '#9fe3ff', 2, 2);
            px(g, x + 5, head + 2 + Math.round(f), 'rgba(159,227,255,.35)', 3, 3);
        }

        // Speech bubbles: voting status, then the revealed votes.
        let icon = null;
        if (v.phase === 'voting') icon = v.voted.includes(p.id) ? 'scroll' : 'dots';
        else if (showVotes && v.phase !== 'ended' && lastProp.votes[p.id] !== undefined) icon = lastProp.votes[p.id] ? 'check' : 'cross';
        if (icon && !struck) {
            const bx = x - 4;
            const by = head - 11;
            px(g, bx, by, '#f4f1e8', 9, 8);
            px(g, bx + 1, by - 1, '#f4f1e8', 7, 1);
            px(g, bx + 1, by + 8, '#f4f1e8', 7, 1);
            px(g, x - 1, by + 9, '#f4f1e8', 2, 1);
            if (icon === 'dots') {
                for (let i = 0; i < 3; i++) if (Math.floor(t * 3) % 4 > i) px(g, bx + 2 + i * 2, by + 4, '#6b6f7c');
            } else if (icon === 'scroll') {
                px(g, bx + 2, by + 2, '#c9b58a', 5, 4);
                px(g, bx + 3, by + 3, '#8a7650', 3, 1);
                px(g, bx + 3, by + 4, '#8a7650', 2, 1);
            } else {
                glyph(g, GLYPHS[icon], bx + 2, by + (icon === 'check' ? 2 : 1), icon === 'check' ? '#2f9e5f' : '#d64545');
            }
        }

        labels.push({ p, x, y, struck, bob });
    }

    // Confetti in the winner's colours.
    if (v.phase === 'ended' && !reducedMotion) {
        const colors = v.winner === 'good' ? ['#5b9cf0', '#9fc2ff', '#f3d488'] : ['#e0605a', '#ff9f8f', '#b576e0'];
        for (const c of confetti) {
            c.y += c.s * dt;
            c.x += Math.sin(t * 2 + c.c * 10) * 6 * dt;
            if (c.y > H) { c.y = -2; c.x = Math.random() * W; }
            px(g, c.x, c.y, colors[Math.floor(c.c * colors.length)], 1, 2);
        }
    }

    drawNight(g, now, dt, labels);

    // Name labels at full screen resolution so the text stays sharp.
    g.setTransform(1, 0, 0, 1, 0, 0);
    const font = Math.max(10 * (window.devicePixelRatio || 1), 4.4 * scale);
    g.font = `600 ${font}px Inter, system-ui, sans-serif`;
    g.textAlign = 'center';
    g.textBaseline = 'top';
    g.lineJoin = 'round';
    g.lineWidth = Math.max(2, font / 4);
    // Knights standing close together would have overlapping names: push a
    // clashing label down a line (front knights first, since they're drawn last).
    const placed = [];
    const lineH = font * 1.15;
    for (const { p, x, y, struck } of [...labels].reverse()) {
        const name = p.name.length > 10 ? `${p.name.slice(0, 9)}…` : p.name;
        const w = g.measureText(name).width + 4;
        const cx = x * scale;
        let ly = (struck ? y + 2 : y + 2.5) * scale;
        for (let tries = 0; tries < 4; tries++) {
            const clash = placed.some((b) => Math.abs(b.cx - cx) < (b.w + w) / 2 && Math.abs(b.ly - ly) < lineH);
            if (!clash) break;
            ly += lineH;
        }
        placed.push({ cx, ly, w });
        const color = p.id === v.meId ? '#f3d488' : p.evil ? '#ff9a90' : '#f4f1e8';
        g.strokeStyle = 'rgba(12,10,16,.85)';
        g.strokeText(name, cx, ly);
        g.fillStyle = color;
        g.fillText(name, cx, ly);
    }
}

// ---------- Night: the Assassin's hunt ----------

// 0 = day, 1 = full night. Eases in and out so the change feels like dusk.
let night = 0;
let flashAt = -Infinity;
let nextBolt = 0;

function glow(g, x, y, r, color) {
    const grd = g.createRadialGradient(x, y, 0, x, y, r);
    grd.addColorStop(0, color);
    grd.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = grd;
    g.fillRect(x - r, y - r, r * 2, r * 2);
}

function drawNight(g, now, dt, knights) {
    const target = scene.value ? 1 : 0;
    night += (target - night) * Math.min(1, dt * (reducedMotion ? 10 : 0.9));
    if (night < 0.01) return;
    const t = now / 1000;
    const v = view.value;

    // Darkness, then warm light from the torches and candles.
    g.fillStyle = `rgba(10,4,24,${0.64 * night})`;
    g.fillRect(0, 0, W, H);
    g.globalCompositeOperation = 'lighter';
    const flick = 0.9 + Math.sin(t * 13) * 0.06 + Math.sin(t * 7.3) * 0.04;
    glow(g, 105, 18, 28 * flick, `rgba(255,140,50,${0.4 * night})`);
    glow(g, 152, 18, 28 * flick, `rgba(255,140,50,${0.4 * night})`);
    glow(g, TABLE.x, TABLE.y - 5, 34 * flick, `rgba(255,170,80,${0.28 * night})`);

    // Evil eyes burn red in the dark.
    for (const { p, x, y, struck, bob } of knights) {
        if (!p.evil || struck) continue;
        const eyeY = y + bob - 10;
        glow(g, x, eyeY, 6, `rgba(255,40,40,${0.55 * night})`);
        px(g, x - 2, eyeY, `rgba(255,70,60,${night})`, 4, 1);
    }
    g.globalCompositeOperation = 'source-over';

    // Blood-red edges.
    const vig = g.createRadialGradient(W / 2, H / 2, H * 0.3, W / 2, H / 2, W * 0.7);
    vig.addColorStop(0, 'rgba(0,0,0,0)');
    vig.addColorStop(1, `rgba(110,0,12,${0.55 * night})`);
    g.fillStyle = vig;
    g.fillRect(0, 0, W, H);

    // Lightning now and then while the Assassin decides.
    if (scene.value === 'hunt' && !reducedMotion && v.phase === 'assassin') {
        if (!nextBolt) nextBolt = now + 2500;
        if (now > nextBolt) {
            flashAt = now;
            nextBolt = now + 6000 + Math.random() * 7000;
            setTimeout(() => play('thunder'), 350);
        }
    } else {
        nextBolt = 0;
    }
    const f = (now - flashAt) / 260;
    if (f < 1) {
        g.fillStyle = `rgba(215,220,255,${(1 - f) * 0.4 * night})`;
        g.fillRect(0, 0, W, H);
    }
}

// ---------- Loop, sizing and input ----------

let raf = 0;
let last = 0;
let visible = true;
let resizeObs = null;
let intersectObs = null;

function resize() {
    const el = canvas.value;
    if (!el) return;
    const dpr = window.devicePixelRatio || 1;
    // A tall space gets a taller scene (more meadow below the courtyard).
    const wantH = Math.round(Math.min(MAX_H, Math.max(BASE_H, (W * el.clientHeight) / Math.max(1, el.clientWidth))));
    if (wantH !== H) {
        H = wantH;
        background = buildBackground();
    }
    // Fit the scene inside the element; any tiny mismatch is letterboxed.
    scale = Math.max(1, Math.floor(Math.min(el.clientWidth / W, el.clientHeight / H) * dpr));
    el.width = W * scale;
    el.height = H * scale;
}

function frame(now) {
    raf = requestAnimationFrame(frame);
    if (!visible || document.hidden || hidden.value || !canvas.value) { last = now; return; }
    const dt = Math.min(0.1, (now - last) / 1000 || 0);
    last = now;
    placeAll(false);
    assignTargets(now);
    step(dt);
    drawScene(canvas.value.getContext('2d'), now, dt);
}

function actorAt(e) {
    // The scene is letterboxed inside the element (object-fit: contain).
    const rect = canvas.value.getBoundingClientRect();
    const k = Math.min(rect.width / W, rect.height / H);
    const lx = (e.clientX - rect.left - (rect.width - W * k) / 2) / k;
    const ly = (e.clientY - rect.top - (rect.height - H * k) / 2) / k;
    let best = null;
    for (const p of view.value.players) {
        const a = actors.get(p.id);
        if (a && Math.abs(lx - a.x) <= 6 && ly >= a.y - 15 && ly <= a.y + 3 && (!best || a.y > actors.get(best.id).y)) best = p;
    }
    return best;
}

const hoverPick = ref(false);
function onMove(e) {
    const p = actorAt(e);
    hoverPick.value = !!(p && selection.value?.can(p));
}
function onClick(e) {
    const p = actorAt(e);
    if (p && selection.value?.can(p)) togglePick(p.id);
}

// Vote results just came in: show everyone's ✓/✗ above their heads for a few seconds.
watch(() => view.value.proposals.length, (n, old) => { if (n > old) revealAt = performance.now(); });
watch(() => view.value.questResults.length, (n, old) => {
    if (n > old) trip = { team: view.value.questResults[n - 1].team, until: performance.now() + 4200 };
});
// New game: everyone back to their seats.
watch(() => view.value.phase, (now, before) => { if (before === 'lobby' || before === 'ended') placeAll(true); });

const mapTitle = computed(() => {
    if (view.value.phase === 'assassin') return 'Night falls on Camelot';
    if (view.value.phase === 'ended') return view.value.winner === 'good' ? 'Camelot celebrates' : 'Camelot has fallen';
    return 'Camelot';
});

onMounted(() => {
    background = buildBackground();
    placeAll(true);
    resize();
    resizeObs = new ResizeObserver(resize);
    resizeObs.observe(canvas.value);
    intersectObs = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; });
    intersectObs.observe(canvas.value);
    raf = requestAnimationFrame(frame);
});

watch(hidden, (h) => { if (!h) requestAnimationFrame(resize); });

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    resizeObs?.disconnect();
    intersectObs?.disconnect();
});
</script>

<template>
    <div class="panel pixel-map-panel">
        <div class="row between">
            <h2>{{ mapTitle }}</h2>
            <button class="btn small ghost" @click="hidden = !hidden">{{ hidden ? 'Show map' : 'Hide map' }}</button>
        </div>
        <canvas
            v-show="!hidden"
            ref="canvas"
            class="pixel-map"
            :class="{ pickable: hoverPick }"
            role="img"
            aria-label="Pixel-art courtyard showing the players as knights"
            @mousemove="onMove"
            @click="onClick"
        />
    </div>
</template>
