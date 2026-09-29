'use strict';

// ---------- Static game data ----------

const ROLES = {
  merlin:   { name: 'Merlin', side: 'good', text: 'You know who serves Evil, except Mordred. Guide Good without giving yourself away: if the Assassin finds you at the end, Evil wins.' },
  percival: { name: 'Percival', side: 'good', text: 'You know who Merlin is. If Morgana is in play she looks the same to you, so you see two candidates. Protect the real Merlin.' },
  servant:  { name: 'Loyal Servant of Arthur', side: 'good', text: 'You know only that you are Good. Find the traitors and get three quests to succeed.' },
  assassin: { name: 'The Assassin', side: 'evil', text: 'You serve Mordred. If Good completes three quests, you get one chance to name Merlin and steal the win.' },
  morgana:  { name: 'Morgana', side: 'evil', text: 'You serve Mordred. To Percival you look exactly like Merlin, so use that to confuse him.' },
  mordred:  { name: 'Mordred', side: 'evil', text: 'You lead the forces of Evil. Merlin cannot see you.' },
  oberon:   { name: 'Oberon', side: 'evil', text: 'You serve Mordred, but you do not know the other minions and they do not know you.' },
  minion:   { name: 'Minion of Mordred', side: 'evil', text: 'You know your fellow minions (except Oberon). Sabotage quests without getting caught.' },
};

const TEAM_SIZES = {
  5: [2, 3, 2, 3, 3], 6: [2, 3, 4, 3, 4], 7: [2, 3, 3, 4, 4],
  8: [3, 4, 4, 5, 5], 9: [3, 4, 4, 5, 5], 10: [3, 4, 4, 5, 5],
};
const EVIL_COUNT = { 5: 2, 6: 2, 7: 3, 8: 3, 9: 3, 10: 4 };

const POLL_MS = 1500;
const STORE_KEY = 'avalon.session';

// ---------- State ----------

const app = document.getElementById('app');
const params = new URLSearchParams(location.search);

let session = params.has('fresh') ? null : readSession();
let view = null;
let screen = null;
let pollTimer = null;
let connected = true;

const ui = {
  selected: new Set(),
  selectKey: '',
  roleOpen: false,
  open: {},
  sideTab: 'chat',
  seenChat: 0,
};

// ---------- Helpers ----------

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function readSession() {
  for (const store of [sessionStorage, localStorage]) {
    try {
      const s = JSON.parse(store.getItem(STORE_KEY));
      if (s && s.code && s.token) return s;
    } catch { /* storage blocked or invalid */ }
  }
  return null;
}

function saveSession(s) {
  session = s;
  for (const store of [sessionStorage, localStorage]) {
    try { s ? store.setItem(STORE_KEY, JSON.stringify(s)) : store.removeItem(STORE_KEY); } catch { /* ignore */ }
  }
}

function toast(message, isError = false) {
  const t = document.createElement('div');
  t.className = 'toast' + (isError ? ' error' : '');
  t.textContent = message;
  document.getElementById('toasts').appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

async function api(action, data = {}) {
  const body = { action, ...data };
  if (session && action !== 'create' && action !== 'join') {
    body.code = session.code;
    body.token = session.token;
  }
  let json;
  try {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    json = await res.json();
  } catch {
    const err = new Error('Could not reach the server.');
    err.kind = 'network';
    throw err;
  }
  if (!json.ok) {
    const err = new Error(json.error || 'Something went wrong.');
    err.kind = json.kind;
    throw err;
  }
  return json;
}

// Sends a game action and applies the returned state.
async function act(action, data = {}) {
  try {
    const res = await api(action, data);
    if (res.left) return leaveRoom();
    if (res.state) setView(res.state);
  } catch (e) {
    handleError(e);
  }
}

function handleError(e) {
  if (e.kind === 'no_room' || e.kind === 'not_player') {
    toast(e.kind === 'no_room' ? 'That room no longer exists.' : 'You are no longer in this room.', true);
    leaveRoom();
  } else {
    toast(e.message, true);
  }
}

function player(id) {
  return view.players.find((p) => p.id === id);
}

function playerName(id) {
  return player(id)?.name ?? '?';
}

function names(ids) {
  return ids.map((id) => esc(playerName(id))).join(', ');
}

// ---------- Session & polling ----------

function enterRoom(res) {
  saveSession({ code: res.code, token: res.token, playerId: res.playerId });
  view = null;
  history.replaceState(null, '', '?room=' + res.code);
  poll();
}

function leaveRoom() {
  clearTimeout(pollTimer);
  const code = session?.code;
  saveSession(null);
  view = null;
  history.replaceState(null, '', location.pathname);
  renderHome(code);
}

async function poll() {
  clearTimeout(pollTimer);
  if (!session) return;
  try {
    const res = await api('state', { v: view ? view.version : -1 });
    if (res.state) setView(res.state);
    setConnected(true);
  } catch (e) {
    if (e.kind === 'no_room' || e.kind === 'not_player') return handleError(e);
    setConnected(false);
  }
  pollTimer = setTimeout(poll, document.hidden ? 5000 : POLL_MS);
}

function setConnected(ok) {
  if (ok === connected) return;
  connected = ok;
  document.querySelector('.conn')?.classList.toggle('off', !ok);
}

function setView(next) {
  // Ignore stale responses that arrive after a newer state.
  if (view && next.version < view.version) return;
  const prev = view;
  view = next;

  const key = [next.phase, next.questNum, next.proposals.length, next.ladyChecks.length].join('|');
  if (key !== ui.selectKey) {
    ui.selectKey = key;
    ui.selected.clear();
  }
  if (!prev || (prev.phase === 'lobby' && next.phase !== 'lobby')) {
    ui.roleOpen = false;
  }
  renderRoom();
}

document.addEventListener('visibilitychange', () => {
  if (!document.hidden && session) poll();
});

// ---------- Home screen ----------

function renderHome(lastCode) {
  screen = 'home';
  const code = (params.get('room') || lastCode || '').toUpperCase();
  app.innerHTML = `
    <div class="home">
      <div class="brand">
        <svg class="sigil" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.5" style="color:var(--gold)">
          <path d="M32 4v40M22 14h20M32 44l-8 8h16l-8-8z"/><circle cx="32" cy="32" r="28" opacity=".35"/>
        </svg>
        <h1>AVALON</h1>
        <p>A game of hidden loyalty for 5–10 players</p>
      </div>
      <div class="panel stack">
        <div>
          <label class="field-label" for="name">Your name</label>
          <input type="text" id="name" maxlength="20" autocomplete="nickname" placeholder="e.g. Lancelot">
        </div>
        <div>
          <label class="field-label" for="code">Room code</label>
          <input type="text" id="code" class="code-input" maxlength="4" placeholder="ABCD" value="${esc(code)}">
        </div>
        <button class="btn primary block big" id="joinBtn">Join room</button>
        <div class="divider">or</div>
        <button class="btn block" id="createBtn">Create a new room</button>
      </div>
      ${session ? `<p class="footnote"><button class="btn ghost small" id="resumeBtn">Return to room ${esc(session.code)}</button></p>` : ''}
      <p class="footnote">Everyone plays on their own phone or computer. Chat in the room, or talk over voice or in person.</p>
    </div>`;

  const nameInput = document.getElementById('name');
  const codeInput = document.getElementById('code');
  try { nameInput.value = localStorage.getItem('avalon.name') || ''; } catch { /* ignore */ }
  (nameInput.value ? codeInput : nameInput).focus();

  const withName = async (btn, fn) => {
    const name = nameInput.value.trim();
    if (!name) { nameInput.focus(); return toast('Enter your name first.', true); }
    try { localStorage.setItem('avalon.name', name); } catch { /* ignore */ }
    btn.disabled = true;
    try { enterRoom(await fn(name)); } catch (e) { toast(e.message, true); btn.disabled = false; }
  };

  const joinBtn = document.getElementById('joinBtn');
  joinBtn.onclick = () => {
    const c = codeInput.value.trim().toUpperCase();
    if (c.length !== 4) { codeInput.focus(); return toast('Enter the 4-letter room code.', true); }
    withName(joinBtn, (name) => api('join', { code: c, name }));
  };
  const createBtn = document.getElementById('createBtn');
  createBtn.onclick = () => withName(createBtn, (name) => api('create', { name }));
  codeInput.onkeydown = nameInput.onkeydown = (e) => { if (e.key === 'Enter') joinBtn.click(); };

  const resume = document.getElementById('resumeBtn');
  if (resume) resume.onclick = () => { history.replaceState(null, '', '?room=' + session.code); poll(); };
}

// ---------- Room screen (lobby + game share the layout) ----------

function renderRoom() {
  if (screen !== 'room') {
    screen = 'room';
    app.innerHTML = `
      <div class="wrap">
        <header class="topbar">
          <div class="title"><h1>AVALON</h1><span class="room-code" id="roomCode"></span><span class="conn" title="Connection"></span></div>
          <div class="row" id="topActions"></div>
        </header>
        <div class="room-layout">
          <main id="main"></main>
          <aside class="room-side panel">
            <div class="side-tabs">
              <button data-tab="chat">Chat<span class="dot" id="chatDot" hidden></span></button>
              <button data-tab="log">Game log</button>
            </div>
            <div class="feed" id="feed"></div>
            <form class="chat-form" id="chatForm">
              <input type="text" id="chatInput" maxlength="300" placeholder="Say something…" autocomplete="off">
              <button class="btn small primary">Send</button>
            </form>
          </aside>
        </div>
      </div>`;
    document.getElementById('chatForm').onsubmit = (e) => {
      e.preventDefault();
      const input = document.getElementById('chatInput');
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      ui.sideTab = 'chat';
      act('chat', { text });
    };
    connected = true;
  }

  document.getElementById('roomCode').textContent = view.code;
  document.getElementById('topActions').innerHTML = topActionsHTML();
  document.getElementById('main').innerHTML = view.phase === 'lobby' ? lobbyHTML() : gameHTML();
  renderFeed();
}

function topActionsHTML() {
  const isHost = view.hostId === view.meId;
  if (view.phase === 'lobby') return `<button class="btn small ghost" data-act="leave">Leave room</button>`;
  if (isHost && view.phase !== 'ended') return `<button class="btn small ghost" data-act="abort">End game</button>`;
  return '';
}

function renderFeed() {
  const feed = document.getElementById('feed');
  const nearBottom = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 40;
  document.querySelectorAll('.side-tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === ui.sideTab));

  if (ui.sideTab === 'chat') {
    ui.seenChat = view.chat.length ? view.chat[view.chat.length - 1].t + ':' + view.chat.length : 0;
    feed.innerHTML = view.chat.length
      ? view.chat.map((m) => `<div class="msg${m.pid === view.meId ? ' mine' : ''}"><b>${esc(m.name)}</b> ${esc(m.text)}</div>`).join('')
      : `<div class="empty">No messages yet.</div>`;
  } else {
    feed.innerHTML = view.log.map((l) => `<div class="event">${esc(l.text)}</div>`).join('');
  }
  const latest = view.chat.length ? view.chat[view.chat.length - 1].t + ':' + view.chat.length : 0;
  document.getElementById('chatDot').hidden = ui.sideTab === 'chat' || latest === ui.seenChat;
  if (nearBottom || !feed.dataset.init) feed.scrollTop = feed.scrollHeight;
  feed.dataset.init = '1';
}

// ---------- Lobby ----------

function setupCheck(n, s) {
  if (n < 5) return { ok: false, text: `Waiting for players: ${n}/5 minimum.` };
  if (n > 10) return { ok: false, text: 'Too many players (10 max).' };
  const evil = EVIL_COUNT[n];
  const good = n - evil;
  const evilSpecials = ['morgana', 'mordred', 'oberon'].filter((r) => s[r]);
  if (evilSpecials.length > evil) {
    return { ok: false, good, evil, text: `Evil has only ${evil} players with ${n} players. Turn off one of Morgana, Mordred or Oberon.` };
  }
  const goodRoles = ['Merlin', s.percival && 'Percival'].filter(Boolean);
  const evilRoles = evilSpecials.map((r) => ROLES[r].name);
  if (evilRoles.length < evil) evilRoles.push('Assassin');
  const servants = good - goodRoles.length;
  const minions = evil - evilRoles.length;
  if (servants) goodRoles.push(`${servants} Loyal Servant${servants > 1 ? 's' : ''}`);
  if (minions) evilRoles.push(`${minions} Minion${minions > 1 ? 's' : ''}`);
  let note = '';
  if (evilSpecials.length === evil) note = ' One of the Evil roles (never Oberon) will also be the Assassin.';
  if (s.lady && n < 7) note += ' The Lady of the Lake is usually used with 7 or more players.';
  return { ok: true, good, evil, text: `<span style="color:var(--good)">Good (${good}):</span> ${goodRoles.join(', ')}<br><span style="color:var(--evil)">Evil (${evil}):</span> ${evilRoles.join(', ')}${note ? `<div class="muted" style="margin-top:6px">${note.trim()}</div>` : ''}` };
}

function lobbyHTML() {
  const v = view;
  const isHost = v.hostId === v.meId;
  const n = v.players.length;
  const check = setupCheck(n, v.settings);
  const link = `${location.origin}${location.pathname}?room=${v.code}`;

  const players = v.players.map((p) => `
    <li>
      <span class="grow">${esc(p.name)}${p.id === v.meId ? ' <span class="muted">(you)</span>' : ''}</span>
      ${p.id === v.hostId ? '<span class="tag gold">Host</span>' : ''}
      ${isHost && p.id !== v.meId ? `<button class="btn small ghost" data-act="kick" data-id="${p.id}">Remove</button>` : ''}
    </li>`).join('');

  const option = (key, label, desc, side) => `
    <label class="option ${side}${isHost ? '' : ' locked'}">
      <input type="checkbox" data-setting="${key}" ${v.settings[key] ? 'checked' : ''} ${isHost ? '' : 'disabled'}>
      <span><strong>${label}</strong><small>${desc}</small></span>
    </label>`;

  const sizeRows = Object.keys(TEAM_SIZES).map((k) => `
    <tr class="${+k === n ? 'current' : ''}"><td>${k}</td><td>${k - EVIL_COUNT[k]}/${EVIL_COUNT[k]}</td>
    ${TEAM_SIZES[k].map((sz, i) => `<td>${sz}${k >= 7 && i === 3 ? '*' : ''}</td>`).join('')}</tr>`).join('');

  return `
    <div class="panel">
      <p class="muted" style="text-align:center">Share this code with the other players</p>
      <div class="big-code">${esc(v.code)}</div>
      <div class="row" style="justify-content:center">
        <button class="btn small" data-act="copy" data-link="${esc(link)}">Copy invite link</button>
      </div>
    </div>

    <div class="panel">
      <div class="row between"><h2>Players</h2><span class="muted">${n}/10</span></div>
      <ul class="lobby-players">${players}</ul>
    </div>

    <div class="panel">
      <div class="row between"><h2>Roles</h2>${isHost ? '' : '<span class="muted">The host chooses</span>'}</div>
      <div class="options">
        <label class="option good locked"><input type="checkbox" checked disabled><span><strong>Merlin &amp; the Assassin</strong><small>Always in the game.</small></span></label>
        ${option('percival', 'Percival', 'Good. Knows who Merlin is.', 'good')}
        ${option('morgana', 'Morgana', 'Evil. Looks like Merlin to Percival.', 'evil')}
        ${option('mordred', 'Mordred', 'Evil. Hidden from Merlin.', 'evil')}
        ${option('oberon', 'Oberon', 'Evil, but unknown to the other Evil players.', 'evil')}
        ${option('lady', 'Lady of the Lake', 'After quests 2, 3 and 4, the holder secretly checks one player’s loyalty.', 'neutral')}
      </div>
      <div class="setup-summary">${check.ok ? check.text : `<div class="warn">${esc(check.text)}</div>`}</div>
    </div>

    <div class="panel">
      ${isHost
        ? `<button class="btn primary block big" data-act="start" ${check.ok ? '' : 'disabled'}>Start game</button>`
        : `<p class="muted" style="text-align:center">Waiting for ${esc(playerName(v.hostId))} to start the game…</p>`}
    </div>

    <details class="panel" data-details="sizes" ${ui.open.sizes ? 'open' : ''}>
      <summary>Quest team sizes</summary>
      <table class="sizes">
        <tr><th>Players</th><th>Good/Evil</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Q5</th></tr>
        ${sizeRows}
      </table>
      <p class="muted" style="font-size:.8rem;margin-top:8px">* With 7 or more players, the 4th quest needs two Fail cards to fail.</p>
    </details>`;
}

// ---------- Game ----------

function selectionMode() {
  const v = view;
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
}

function gameHTML() {
  return [
    phaseBannerHTML(),
    actionHTML(),
    boardHTML(),
    playersHTML(),
    roleHTML(),
    historyHTML(),
  ].join('');
}

function phaseBannerHTML() {
  const v = view;
  const q = v.questNum + 1;
  const lines = {
    proposing: ['⚑', `Quest ${Math.min(q, 5)}: team proposal`, `${esc(playerName(v.leaderId))} is choosing ${v.teamSizes[v.questNum]} players.`],
    voting: ['⚖', `Quest ${q}: vote on the team`, 'Everyone votes to approve or reject.'],
    questing: ['⚔', `Quest ${q} is underway`, 'The team secretly decides the quest’s fate.'],
    lady: ['☾', 'Lady of the Lake', `${esc(playerName(v.lady?.holderId))} examines a player’s loyalty.`],
    assassin: ['🗡', 'The Assassin strikes', 'Good has won three quests. Evil now tries to find Merlin.'],
    ended: ['♛', 'Game over', esc(v.winReason)],
  }[v.phase];
  return `
    <div class="panel phase-banner">
      <div class="icon">${lines[0]}</div>
      <div><h2>${lines[1]}</h2><p>${lines[2]}</p></div>
    </div>`;
}

function boardHTML() {
  const v = view;
  const quests = v.teamSizes.map((size, i) => {
    const r = v.questResults[i];
    let cls = '';
    let lbl = `Quest ${i + 1}`;
    if (r) {
      cls = r.success ? 'success' : 'fail';
      lbl = r.fails === 0 ? 'Success' : `${r.fails} fail${r.fails > 1 ? 's' : ''}`;
    } else if (i === v.questNum && v.phase !== 'ended') {
      cls = 'current';
    }
    const two = v.twoFailQuest === i ? '<span class="two">2 fails</span>' : '';
    return `<div class="quest ${cls}"><div><div class="size">${size}</div><div class="lbl">${lbl}</div></div>${two}</div>`;
  }).join('');

  const pips = [0, 1, 2, 3, 4].map((i) => `<span class="pip${i < v.rejectCount ? ' on' : ''}${i === 4 ? ' last' : ''}">${i + 1}</span>`).join('');

  return `
    <div class="panel">
      <div class="track">${quests}</div>
      <div class="vote-track">
        <span>Rejected proposals</span><span class="pips">${pips}</span>
        <span>${v.rejectCount === 4 && v.phase !== 'ended' ? '<span class="tag evil">Next rejection hands Evil the win</span>' : '5 in a row and Evil wins'}</span>
      </div>
    </div>`;
}

function knowTag(label) {
  if (label === 'evil') return '<span class="tag evil">Evil</span>';
  if (label === 'merlin') return '<span class="tag good">Merlin</span>';
  if (label === 'merlin?') return '<span class="tag good">Merlin or Morgana</span>';
  return '';
}

function playersHTML() {
  const v = view;
  const mode = selectionMode();
  const last = v.proposals[v.proposals.length - 1];
  const showLastVote = last && v.phase !== 'voting';
  const ladyFindings = v.me?.ladyFindings ?? [];

  const cards = v.players.map((p) => {
    const tags = [];
    const classes = ['player'];
    if (p.id === v.meId) { classes.push('me'); tags.push('<span class="tag">You</span>'); }
    if (p.id === v.leaderId && v.phase !== 'ended' && v.phase !== 'assassin') tags.push('<span class="tag gold">♛ Leader</span>');
    if (v.lady && v.lady.holderId === p.id && v.phase !== 'ended') tags.push('<span class="tag gold">☾ Lady</span>');

    const onTeam = v.team.includes(p.id);
    if (onTeam) { classes.push('on-team'); tags.push('<span class="tag gold">On quest</span>'); }

    if (v.phase === 'voting') tags.push(v.voted.includes(p.id) ? '<span class="tag ok">Voted</span>' : '<span class="tag">Deciding…</span>');
    if (v.phase === 'questing' && onTeam) tags.push(v.played.includes(p.id) ? '<span class="tag ok">Card played</span>' : '<span class="tag">Choosing…</span>');

    if (v.phase === 'ended' && p.role) {
      const r = ROLES[p.role];
      tags.push(`<span class="tag ${r.side}">${esc(r.name)}</span>`);
      if (p.id === v.assassinId) tags.push('<span class="tag evil">Assassin</span>');
    } else if (p.evil) {
      classes.push('revealed-evil');
      tags.push(`<span class="tag evil">${p.id === v.assassinId ? 'Assassin' : 'Evil'}</span>`);
    } else if (v.me?.knows?.[p.id]) {
      tags.push(knowTag(v.me.knows[p.id]));
    }
    const finding = ladyFindings.find((f) => f.targetId === p.id);
    if (finding && v.phase !== 'ended') tags.push(`<span class="tag ${finding.evil ? 'evil' : 'good'}">☾ ${finding.evil ? 'Evil' : 'Good'}</span>`);
    if (v.assassinTarget === p.id) { classes.push('struck'); tags.push('<span class="tag evil">🗡 Struck</span>'); }

    let mark = '';
    const selectable = mode && mode.can(p);
    if (mode && !selectable) classes.push('dim');
    if (selectable) classes.push('selectable');
    if (ui.selected.has(p.id)) { classes.push('selected'); mark = '<span class="check">✓</span>'; }
    else if (showLastVote && last.votes[p.id] !== undefined) {
      mark = last.votes[p.id] ? '<span class="vote-mark yes" title="Approved last proposal">✓</span>' : '<span class="vote-mark no" title="Rejected last proposal">✗</span>';
    }

    return `<div class="${classes.join(' ')}" ${selectable ? `data-pick="${p.id}" role="button" tabindex="0"` : ''}>
      ${mark}<div class="name">${esc(p.name)}</div><div class="tags">${tags.join('')}</div></div>`;
  }).join('');

  const legend = showLastVote ? `<span class="muted" style="font-size:.8rem">✓/✗ = votes on the last proposal</span>` : '';
  return `<div class="panel"><div class="row between" style="margin-bottom:12px"><h2>The Round Table</h2>${legend}</div><div class="players">${cards}</div></div>`;
}

function actionHTML() {
  const v = view;
  const me = v.meId;
  const isHost = v.hostId === me;
  const size = v.teamSizes[v.questNum];
  let body = '';

  switch (v.phase) {
    case 'proposing': {
      const attempt = v.rejectCount + 1;
      const warn = attempt === 5 ? '<p class="hint" style="color:var(--evil)">This is the 5th proposal. If it is rejected, Evil wins.</p>' : '';
      if (v.leaderId === me) {
        const n = ui.selected.size;
        body = `<h2>You are the leader</h2>
          <p class="hint">Pick <b>${size}</b> players for Quest ${v.questNum + 1} by tapping them at the Round Table (you can include yourself). Proposal ${attempt} of 5.</p>${warn}
          <div class="choices" style="grid-template-columns:1fr">
            <button class="btn primary big" data-act="propose" ${n === size ? '' : 'disabled'}>Propose team (${n}/${size})</button>
          </div>`;
      } else {
        body = `<h2>Waiting for the leader</h2><p class="hint"><span class="names">${esc(playerName(v.leaderId))}</span> is choosing ${size} players for Quest ${v.questNum + 1}. Proposal ${attempt} of 5.</p>${warn}`;
      }
      break;
    }
    case 'voting': {
      const waiting = v.players.filter((p) => !v.voted.includes(p.id)).map((p) => p.id);
      const voteLine = v.myVote === null
        ? '<p class="hint">Your vote will be revealed once everyone has voted.</p>'
        : `<p class="hint">You voted <b>${v.myVote ? 'Approve' : 'Reject'}</b>. You can change it until the last vote is in. Waiting for: <span class="names">${names(waiting)}</span></p>`;
      body = `<h2>Approve this team?</h2>
        <p class="hint">${esc(playerName(v.leaderId))} proposes: <span class="names">${names(v.team)}</span></p>
        <div class="choices">
          <button class="btn ${v.myVote === true ? 'good' : ''} big" data-act="vote" data-approve="1">Approve</button>
          <button class="btn ${v.myVote === false ? 'evil' : ''} big" data-act="vote" data-approve="0">Reject</button>
        </div>${voteLine}`;
      break;
    }
    case 'questing': {
      const waiting = v.team.filter((id) => !v.played.includes(id));
      const needs = v.twoFailQuest === v.questNum ? ' This quest needs <b>two</b> Fail cards to fail.' : '';
      if (v.team.includes(me) && !v.myCard) {
        const evil = v.me.evil;
        body = `<h2>Choose your quest card</h2>
          <p class="hint">Your card is secret. Only the number of Fail cards is revealed.${needs}</p>
          <div class="choices">
            <button class="btn good big" data-act="quest" data-card="success">Success</button>
            <button class="btn evil big" data-act="quest" data-card="fail" ${evil ? '' : 'disabled title="Loyal servants must play Success"'}>Fail</button>
          </div>
          ${evil ? '' : '<p class="hint">Loyal servants of Arthur must always play Success.</p>'}`;
      } else if (v.team.includes(me)) {
        body = `<h2>Card played</h2><p class="hint">You played <b>${v.myCard === 'fail' ? 'Fail' : 'Success'}</b>. Waiting for: <span class="names">${names(waiting)}</span></p>`;
      } else {
        body = `<h2>The quest is underway</h2><p class="hint"><span class="names">${names(v.team)}</span> are choosing their cards.${needs}</p>`;
      }
      break;
    }
    case 'lady': {
      if (v.lady.holderId === me) {
        const pick = [...ui.selected][0];
        body = `<h2>Use the Lady of the Lake</h2>
          <p class="hint">Choose a player to secretly learn their loyalty. You can’t pick anyone who has already held the Lady. They receive the Lady next.</p>
          <div class="choices" style="grid-template-columns:1fr">
            <button class="btn primary big" data-act="lady" ${pick ? '' : 'disabled'}>${pick ? `Examine ${esc(playerName(pick))}` : 'Choose a player'}</button>
          </div>`;
      } else {
        body = `<h2>The Lady of the Lake</h2><p class="hint"><span class="names">${esc(playerName(v.lady.holderId))}</span> is choosing a player to examine.</p>`;
      }
      break;
    }
    case 'assassin': {
      if (v.assassinId === me) {
        const pick = [...ui.selected][0];
        body = `<h2>Name Merlin</h2>
          <p class="hint">Talk it over with your fellow minions in chat, then strike. If you hit Merlin, Evil wins.</p>
          <div class="choices" style="grid-template-columns:1fr">
            <button class="btn evil big" data-act="assassinate" ${pick ? '' : 'disabled'}>${pick ? `Strike ${esc(playerName(pick))}` : 'Choose a player'}</button>
          </div>`;
      } else if (v.me.evil) {
        body = `<h2>Help the Assassin</h2><p class="hint">Who is Merlin? Share your suspicions with <span class="names">${esc(playerName(v.assassinId))}</span> in chat.</p>`;
      } else {
        body = `<h2>Hold your breath</h2><p class="hint">Evil is now revealed. <span class="names">${esc(playerName(v.assassinId))}</span> (the Assassin) is trying to find Merlin.</p>`;
      }
      break;
    }
    case 'ended': {
      const iWon = v.me && (v.me.evil ? 'evil' : 'good') === v.winner;
      return `
        <div class="panel result-banner ${v.winner}">
          <h2>${v.winner === 'good' ? 'Good triumphs' : 'Evil prevails'}</h2>
          <p>${esc(v.winReason)}</p>
          <p style="margin-top:12px;color:var(--text)">${iWon ? 'Your side won.' : 'Your side lost.'}</p>
          <div class="row" style="justify-content:center;margin-top:16px">
            ${isHost ? '<button class="btn primary big" data-act="restart">Play again</button>' : `<span class="muted">Waiting for ${esc(playerName(v.hostId))} to start a new game…</span>`}
          </div>
        </div>`;
    }
  }
  return `<div class="panel action">${body}</div>`;
}

function roleHTML() {
  const v = view;
  if (!v.me) return '';
  const role = ROLES[v.me.role];
  let inner = '';

  if (ui.roleOpen) {
    const known = Object.entries(v.me.knows);
    let knowledge = '';
    if (v.me.role === 'merlin') {
      knowledge = known.length ? `<p><b>Evil players you can see:</b> ${names(known.map(([id]) => id))}</p>` : '<p>You see no Evil players.</p>';
      if (v.settings.mordred) knowledge += '<p class="muted">Mordred is hidden from you.</p>';
    } else if (v.me.role === 'percival') {
      const ids = known.map(([id]) => id);
      knowledge = ids.length === 1 ? `<p><b>Merlin is:</b> ${names(ids)}</p>` : `<p><b>One of these is Merlin, the other is Morgana:</b> ${names(ids)}</p>`;
    } else if (v.me.evil && v.me.role !== 'oberon') {
      knowledge = known.length ? `<p><b>Your fellow minions:</b> ${names(known.map(([id]) => id))}</p>` : '<p>You see no other minions.</p>';
      if (v.settings.oberon) knowledge += '<p class="muted">Oberon also serves Evil, but is hidden from you.</p>';
    }
    if (v.me.isAssassin && v.me.role !== 'assassin') knowledge += '<p><b>You are also the Assassin.</b> If Good wins three quests, you name Merlin.</p>';
    const findings = v.me.ladyFindings.map((f) => `<li>${esc(playerName(f.targetId))} is <b style="color:var(--${f.evil ? 'evil' : 'good'})">${f.evil ? 'Evil' : 'Good'}</b></li>`).join('');
    if (findings) knowledge += `<p style="margin-top:10px"><b>Lady of the Lake findings</b></p><ul>${findings}</ul>`;

    inner = `
      <div class="role-card ${role.side}">
        <div class="muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.1em">${role.side === 'good' ? 'Loyal to Arthur' : 'Serves Mordred'}</div>
        <div class="role-name">${esc(role.name)}</div>
        <p>${role.text}</p>
        ${knowledge}
      </div>`;
  }
  return `<div class="panel">
    <button class="btn block role-toggle" data-act="role">${ui.roleOpen ? 'Hide my role' : 'Reveal my secret role'}</button>
    ${inner}</div>`;
}

function historyHTML() {
  const v = view;
  if (!v.proposals.length && !v.ladyChecks.length) return '';
  const items = [];
  for (let q = 0; q <= Math.min(v.questNum, 4); q++) {
    for (const p of v.proposals.filter((x) => x.quest === q)) {
      const yes = Object.values(p.votes).filter(Boolean).length;
      const no = Object.values(p.votes).length - yes;
      const chips = Object.entries(p.votes).map(([id, ok]) => `<span class="tag ${ok ? 'ok' : 'evil'}">${ok ? '✓' : '✗'} ${esc(playerName(id))}</span>`).join('');
      items.push(`<li><b>Quest ${q + 1}, proposal ${p.attempt}</b>: ${esc(playerName(p.leaderId))} proposed ${names(p.team)}
        <span class="tag ${p.approved ? 'ok' : 'evil'}">${p.approved ? 'Approved' : 'Rejected'} ${yes}–${no}</span>
        <div class="votes">${chips}</div></li>`);
    }
    const r = v.questResults[q];
    if (r) {
      items.push(`<li><b>Quest ${q + 1} ${r.success ? 'succeeded' : 'failed'}</b>: ${r.fails} Fail card${r.fails === 1 ? '' : 's'} (team: ${names(r.team)})</li>`);
    }
    for (const c of v.ladyChecks.filter((x) => x.afterQuest === q + 1)) {
      items.push(`<li>☾ ${esc(playerName(c.holderId))} examined ${esc(playerName(c.targetId))}</li>`);
    }
  }
  return `<details class="panel" data-details="history" ${ui.open.history ? 'open' : ''}>
    <summary>History</summary><ul class="history">${items.reverse().join('')}</ul></details>`;
}

// ---------- Events ----------

document.addEventListener('click', async (e) => {
  const tab = e.target.closest('[data-tab]');
  if (tab) {
    ui.sideTab = tab.dataset.tab;
    return renderFeed();
  }

  const pick = e.target.closest('[data-pick]');
  if (pick && view) return togglePick(pick.dataset.pick);

  const btn = e.target.closest('[data-act]');
  if (!btn || !view || btn.disabled) return;
  const a = btn.dataset.act;

  switch (a) {
    case 'role':
      ui.roleOpen = !ui.roleOpen;
      return renderRoom();
    case 'copy':
      try { await navigator.clipboard.writeText(btn.dataset.link); toast('Invite link copied.'); }
      catch { prompt('Copy this invite link:', btn.dataset.link); }
      return;
    case 'leave':
      if (confirm('Leave this room?')) await act('leave');
      return;
    case 'abort':
      if (confirm('End the current game and return everyone to the lobby?')) await act('restart');
      return;
    case 'kick':
      if (confirm(`Remove ${playerName(btn.dataset.id)} from the room?`)) await act('kick', { target: btn.dataset.id });
      return;
    case 'start':
    case 'restart':
      btn.disabled = true;
      return act(a);
    case 'propose':
      btn.disabled = true;
      return act('propose', { team: [...ui.selected] });
    case 'vote':
      return act('vote', { approve: btn.dataset.approve === '1' });
    case 'quest':
      if (btn.dataset.card === 'fail' && !confirm('Play a FAIL card?')) return;
      btn.disabled = true;
      return act('quest', { card: btn.dataset.card });
    case 'lady':
      btn.disabled = true;
      return act('lady', { target: [...ui.selected][0] });
    case 'assassinate': {
      const target = [...ui.selected][0];
      if (!confirm(`Strike ${playerName(target)}? This ends the game.`)) return;
      btn.disabled = true;
      return act('assassinate', { target });
    }
  }
});

document.addEventListener('keydown', (e) => {
  const pick = e.target.closest?.('[data-pick]');
  if (pick && (e.key === 'Enter' || e.key === ' ')) {
    e.preventDefault();
    togglePick(pick.dataset.pick);
  }
});

document.addEventListener('change', (e) => {
  const key = e.target.dataset?.setting;
  if (key) act('settings', { [key]: e.target.checked });
});

// <details> toggles don't bubble, so listen in the capture phase.
document.addEventListener('toggle', (e) => {
  const key = e.target.dataset?.details;
  if (key) ui.open[key] = e.target.open;
}, true);

function togglePick(id) {
  const mode = selectionMode();
  if (!mode) return;
  if (ui.selected.has(id)) {
    ui.selected.delete(id);
  } else if (mode.max === 1) {
    ui.selected = new Set([id]);
  } else if (ui.selected.size < mode.max) {
    ui.selected.add(id);
  } else {
    return toast(`The team has only ${mode.max} spots. Deselect someone first.`);
  }
  renderRoom();
}

// ---------- Boot ----------

const urlRoom = (params.get('room') || '').toUpperCase();
if (session && (!urlRoom || urlRoom === session.code)) {
  history.replaceState(null, '', '?room=' + session.code);
  app.innerHTML = '<p class="footnote">Connecting…</p>';
  poll();
} else {
  renderHome();
}
