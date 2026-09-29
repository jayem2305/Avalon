// Static game data shared by the Vue components. The server (app/Game/Avalon.php)
// is the authority on rules; these copies are only for display.
// `short` is used on the small player-card tags.

export const ROLES = {
    merlin: { name: 'Merlin', side: 'good', text: 'You know who serves Evil, except Mordred. Guide Good without giving yourself away: if the Assassin finds you at the end, Evil wins.' },
    percival: { name: 'Percival', side: 'good', text: 'You know who Merlin is. If Morgana is in play she looks the same to you, so you see two candidates. Protect the real Merlin.' },
    servant: { name: 'Loyal Servant of Arthur', short: 'Loyal Servant', side: 'good', text: 'You know only that you are Good. Find the traitors and get three quests to succeed.' },
    assassin: { name: 'The Assassin', short: 'Assassin', side: 'evil', text: 'You serve Mordred. If Good completes three quests, you get one chance to name Merlin and steal the win.' },
    morgana: { name: 'Morgana', side: 'evil', text: 'You serve Mordred. To Percival you look exactly like Merlin, so use that to confuse him.' },
    mordred: { name: 'Mordred', side: 'evil', text: 'You lead the forces of Evil. Merlin cannot see you.' },
    oberon: { name: 'Oberon', side: 'evil', text: 'You serve Mordred, but you do not know the other minions and they do not know you.' },
    minion: { name: 'Minion of Mordred', short: 'Minion', side: 'evil', text: 'You know your fellow minions (except Oberon). Sabotage quests without getting caught.' },
};

export const TEAM_SIZES = {
    5: [2, 3, 2, 3, 3], 6: [2, 3, 4, 3, 4], 7: [2, 3, 3, 4, 4],
    8: [3, 4, 4, 5, 5], 9: [3, 4, 4, 5, 5], 10: [3, 4, 4, 5, 5],
};

export const EVIL_COUNT = { 5: 2, 6: 2, 7: 3, 8: 3, 9: 3, 10: 4 };

const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`;

/** Describes the role line-up the lobby's settings would produce. */
export function setupSummary(n, s) {
    if (n < 5) return { ok: false, error: `Waiting for players: ${n}/5 minimum.` };
    if (n > 10) return { ok: false, error: 'Too many players (10 max).' };
    const evil = EVIL_COUNT[n];
    const good = n - evil;
    const evilSpecials = ['morgana', 'mordred', 'oberon'].filter((r) => s[r]);
    if (evilSpecials.length > evil) {
        return { ok: false, error: `Evil has only ${evil} players with ${n} players. Turn off one of Morgana, Mordred or Oberon.` };
    }
    const goodRoles = ['Merlin', s.percival && 'Percival'].filter(Boolean);
    const evilRoles = evilSpecials.map((r) => ROLES[r].name);
    if (evilRoles.length < evil) evilRoles.push('Assassin');
    const servants = good - goodRoles.length;
    const minions = evil - evilRoles.length;
    if (servants) goodRoles.push(plural(servants, 'Loyal Servant'));
    if (minions) evilRoles.push(plural(minions, 'Minion'));

    // Role keys this setup deals. The Assassin is always possible: without a
    // separate card, one of the Evil roles carries the Assassin's duty.
    const inPlay = [
        'merlin', s.percival && 'percival', servants && 'servant',
        ...evilSpecials, 'assassin', minions && 'minion',
    ].filter(Boolean);

    const notes = [];
    if (evilSpecials.length === evil) notes.push('One of the Evil roles (never Oberon) will also be the Assassin.');
    if (s.lady && n < 7) notes.push('The Lady of the Lake is usually used with 7 or more players.');
    return { ok: true, good, evil, goodRoles, evilRoles, inPlay, notes };
}
