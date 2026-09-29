<?php
declare(strict_types=1);

// Avalon rules engine. All game state lives in one array ($s) and every player
// action goes through apply_action(), which validates it against the rules.
// build_view() produces what one specific player is allowed to see.

final class GameError extends Exception
{
    public function __construct(string $message, public readonly string $kind = 'invalid')
    {
        parent::__construct($message);
    }
}

const MIN_PLAYERS = 5;
const MAX_PLAYERS = 10;

// Players per quest, by player count.
const TEAM_SIZES = [
    5  => [2, 3, 2, 3, 3],
    6  => [2, 3, 4, 3, 4],
    7  => [2, 3, 3, 4, 4],
    8  => [3, 4, 4, 5, 5],
    9  => [3, 4, 4, 5, 5],
    10 => [3, 4, 4, 5, 5],
];

const EVIL_COUNT = [5 => 2, 6 => 2, 7 => 3, 8 => 3, 9 => 3, 10 => 4];

const EVIL_ROLES = ['assassin', 'morgana', 'mordred', 'oberon', 'minion'];

const SETTING_KEYS = ['percival', 'morgana', 'mordred', 'oberon', 'lady'];

function is_evil(?string $role): bool
{
    return $role !== null && in_array($role, EVIL_ROLES, true);
}

function rng(): \Random\Randomizer
{
    static $r = null;
    return $r ??= new \Random\Randomizer();
}

function clean_name(mixed $name): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') {
        throw new GameError('Please enter your name.');
    }
    if (mb_strlen($name) > 20) {
        throw new GameError('Names can be at most 20 characters.');
    }
    return $name;
}

function clean_code(mixed $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code));
    if (strlen($code) !== 4) {
        throw new GameError('Enter the 4-letter room code.');
    }
    return $code;
}

function new_player(string $name): array
{
    return [
        'id'    => 'p' . bin2hex(random_bytes(4)),
        'token' => bin2hex(random_bytes(16)),
        'name'  => $name,
        'role'  => null,
    ];
}

function new_room_state(mixed $name): array
{
    $host = new_player(clean_name($name));
    $s = [
        'code'     => '',
        'version'  => 0,
        'hostId'   => $host['id'],
        'phase'    => 'lobby',
        'settings' => ['percival' => true, 'morgana' => true, 'mordred' => false, 'oberon' => false, 'lady' => false],
        'players'  => [$host],
        'chat'     => [],
        'log'      => [],
    ];
    reset_game($s);
    add_log($s, "{$host['name']} created the room.");
    return [$s, $host];
}

function reset_game(array &$s): void
{
    foreach ($s['players'] as &$p) {
        $p['role'] = null;
    }
    unset($p);
    $s['leaderIdx']      = 0;
    $s['questNum']       = 0;  // 0-based index of the current quest
    $s['rejectCount']    = 0;  // consecutive rejected proposals for this quest
    $s['team']           = [];
    $s['votes']          = [];
    $s['questCards']     = [];
    $s['questResults']   = [];
    $s['proposals']      = [];
    $s['lady']           = null;
    $s['ladyResults']    = [];
    $s['assassinId']     = null;
    $s['assassinTarget'] = null;
    $s['winner']         = null;
    $s['winReason']      = null;
}

function add_log(array &$s, string $text): void
{
    $s['log'][] = ['t' => time(), 'text' => $text];
    $s['log'] = array_slice($s['log'], -100);
}

function find_player(array $s, mixed $id): ?int
{
    foreach ($s['players'] as $i => $p) {
        if ($p['id'] === $id) {
            return $i;
        }
    }
    return null;
}

function require_target(array $s, mixed $id): array
{
    $i = find_player($s, $id);
    if ($i === null) {
        throw new GameError('Choose a player.');
    }
    return $s['players'][$i];
}

function player_by_token(array $s, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    foreach ($s['players'] as $p) {
        if (hash_equals($p['token'], $token)) {
            return $p;
        }
    }
    return null;
}

function require_player(array $s, mixed $token): array
{
    $p = player_by_token($s, (string) $token);
    if (!$p) {
        throw new GameError('You are not in this room.', 'not_player');
    }
    return $p;
}

function leader(array $s): array
{
    return $s['players'][$s['leaderIdx']];
}

function team_size(array $s): int
{
    return TEAM_SIZES[count($s['players'])][$s['questNum']];
}

function fails_needed(array $s, int $quest): int
{
    return count($s['players']) >= 7 && $quest === 3 ? 2 : 1;
}

function need_phase(array $s, string $phase): void
{
    if ($s['phase'] !== $phase) {
        throw new GameError('That action is not available right now.');
    }
}

function need_host(array $s, array $me): void
{
    if ($s['hostId'] !== $me['id']) {
        throw new GameError('Only the host can do that.');
    }
}

function join_room(array &$s, mixed $name, string $token): array
{
    $existing = player_by_token($s, $token);
    if ($existing) {
        return $existing;
    }
    $name = clean_name($name);
    if ($s['phase'] !== 'lobby') {
        throw new GameError('This game has already started.');
    }
    if (count($s['players']) >= MAX_PLAYERS) {
        throw new GameError('This room is full (10 players max).');
    }
    foreach ($s['players'] as $p) {
        if (mb_strtolower($p['name']) === mb_strtolower($name)) {
            throw new GameError('That name is already taken in this room.');
        }
    }
    $p = new_player($name);
    $s['players'][] = $p;
    add_log($s, "$name joined.");
    return $p;
}

function apply_action(array &$s, array $me, string $action, array $in): void
{
    switch ($action) {
        case 'settings':
            need_host($s, $me);
            need_phase($s, 'lobby');
            foreach (SETTING_KEYS as $key) {
                if (array_key_exists($key, $in)) {
                    $s['settings'][$key] = (bool) $in[$key];
                }
            }
            return;

        case 'kick':
            need_host($s, $me);
            need_phase($s, 'lobby');
            $target = require_target($s, $in['target'] ?? null);
            if ($target['id'] === $me['id']) {
                throw new GameError('You cannot remove yourself.');
            }
            remove_player($s, $target['id']);
            add_log($s, "{$target['name']} was removed by the host.");
            return;

        case 'leave':
            need_phase($s, 'lobby');
            remove_player($s, $me['id']);
            add_log($s, "{$me['name']} left.");
            return;

        case 'start':
            need_host($s, $me);
            need_phase($s, 'lobby');
            start_game($s);
            return;

        case 'propose':
            propose_team($s, $me, $in['team'] ?? null);
            return;

        case 'vote':
            cast_vote($s, $me, $in['approve'] ?? null);
            return;

        case 'quest':
            play_card($s, $me, $in['card'] ?? null);
            return;

        case 'lady':
            use_lady($s, $me, $in['target'] ?? null);
            return;

        case 'assassinate':
            assassinate($s, $me, $in['target'] ?? null);
            return;

        case 'chat':
            $text = trim((string) ($in['text'] ?? ''));
            if ($text === '') {
                return;
            }
            $s['chat'][] = ['pid' => $me['id'], 'name' => $me['name'], 'text' => mb_substr($text, 0, 300), 't' => time()];
            $s['chat'] = array_slice($s['chat'], -150);
            return;

        case 'restart':
            need_host($s, $me);
            if ($s['phase'] === 'lobby') {
                return;
            }
            reset_game($s);
            $s['phase'] = 'lobby';
            add_log($s, 'The host returned everyone to the lobby.');
            return;
    }
    throw new GameError('Unknown action.');
}

function remove_player(array &$s, string $id): void
{
    $s['players'] = array_values(array_filter($s['players'], fn($p) => $p['id'] !== $id));
    if ($s['hostId'] === $id && $s['players']) {
        $s['hostId'] = $s['players'][0]['id'];
        add_log($s, "{$s['players'][0]['name']} is now the host.");
    }
}

function start_game(array &$s): void
{
    $n = count($s['players']);
    if ($n < MIN_PLAYERS || $n > MAX_PLAYERS) {
        throw new GameError('Avalon needs 5 to 10 players.');
    }
    reset_game($s);
    $set = $s['settings'];
    $evilCount = EVIL_COUNT[$n];

    $evil = array_values(array_filter(['morgana', 'mordred', 'oberon'], fn($r) => $set[$r]));
    if (count($evil) > $evilCount) {
        throw new GameError("With $n players Evil has only $evilCount members. Turn off one of Morgana, Mordred or Oberon.");
    }
    if (count($evil) < $evilCount) {
        $evil[] = 'assassin';
    }
    while (count($evil) < $evilCount) {
        $evil[] = 'minion';
    }
    $good = $set['percival'] ? ['merlin', 'percival'] : ['merlin'];
    while (count($good) < $n - $evilCount) {
        $good[] = 'servant';
    }

    $roles = rng()->shuffleArray(array_merge($good, $evil));
    foreach ($s['players'] as $i => &$p) {
        $p['role'] = $roles[$i];
    }
    unset($p);

    // Assassin duty goes to the Assassin, or, if every Evil slot is a named
    // role, to a random Evil player other than Oberon.
    $candidates = [];
    foreach ($s['players'] as $p) {
        if ($p['role'] === 'assassin') {
            $candidates = [$p['id']];
            break;
        }
        if (is_evil($p['role']) && $p['role'] !== 'oberon') {
            $candidates[] = $p['id'];
        }
    }
    $s['assassinId'] = $candidates[rng()->getInt(0, count($candidates) - 1)];

    $s['leaderIdx'] = rng()->getInt(0, $n - 1);
    $s['phase'] = 'proposing';
    add_log($s, "The game has begun: $n players, $evilCount of them serve Mordred.");

    if ($set['lady']) {
        $holder = $s['players'][($s['leaderIdx'] + $n - 1) % $n];
        $s['lady'] = ['holderId' => $holder['id'], 'previous' => [$holder['id']]];
        add_log($s, "{$holder['name']} holds the Lady of the Lake.");
    }
    add_log($s, leader($s)['name'] . ' is the first leader.');
}

function advance_leader(array &$s): void
{
    $s['leaderIdx'] = ($s['leaderIdx'] + 1) % count($s['players']);
}

function propose_team(array &$s, array $me, mixed $team): void
{
    need_phase($s, 'proposing');
    if (leader($s)['id'] !== $me['id']) {
        throw new GameError('Only the leader can propose a team.');
    }
    if (!is_array($team)) {
        throw new GameError('Choose the players for the team.');
    }
    $team = array_values(array_unique(array_map('strval', $team)));
    $size = team_size($s);
    if (count($team) !== $size) {
        throw new GameError("The team must have exactly $size players.");
    }
    $ordered = [];
    foreach ($s['players'] as $p) {
        if (in_array($p['id'], $team, true)) {
            $ordered[] = $p['id'];
        }
    }
    if (count($ordered) !== $size) {
        throw new GameError('Unknown player on the team.');
    }
    $s['team'] = $ordered;
    $s['votes'] = [];
    $s['phase'] = 'voting';
    $names = implode(', ', array_map(fn($id) => $s['players'][find_player($s, $id)]['name'], $ordered));
    add_log($s, "{$me['name']} proposed $names for Quest " . ($s['questNum'] + 1) . '.');
}

function cast_vote(array &$s, array $me, mixed $approve): void
{
    need_phase($s, 'voting');
    if (!is_bool($approve)) {
        throw new GameError('Vote approve or reject.');
    }
    $s['votes'][$me['id']] = $approve;
    if (count($s['votes']) < count($s['players'])) {
        return;
    }

    $votes = [];
    foreach ($s['players'] as $p) {
        $votes[$p['id']] = $s['votes'][$p['id']];
    }
    $yes = count(array_filter($votes));
    $no = count($votes) - $yes;
    $approved = $yes > $no;

    $s['proposals'][] = [
        'quest'    => $s['questNum'],
        'attempt'  => $s['rejectCount'] + 1,
        'leaderId' => leader($s)['id'],
        'team'     => $s['team'],
        'votes'    => $votes,
        'approved' => $approved,
    ];
    $s['votes'] = [];

    if ($approved) {
        add_log($s, "The team was approved ({$yes}–{$no}). The quest begins.");
        $s['rejectCount'] = 0;
        $s['questCards'] = [];
        $s['phase'] = 'questing';
        return;
    }

    $s['rejectCount']++;
    add_log($s, "The team was rejected ({$yes}–{$no}).");
    if ($s['rejectCount'] >= 5) {
        end_game($s, 'evil', 'Five team proposals in a row were rejected.');
        return;
    }
    advance_leader($s);
    $s['team'] = [];
    $s['phase'] = 'proposing';
}

function play_card(array &$s, array $me, mixed $card): void
{
    need_phase($s, 'questing');
    if (!in_array($me['id'], $s['team'], true)) {
        throw new GameError('You are not on this quest.');
    }
    if (isset($s['questCards'][$me['id']])) {
        throw new GameError('You have already played your card.');
    }
    if ($card !== 'success' && $card !== 'fail') {
        throw new GameError('Play Success or Fail.');
    }
    if ($card === 'fail' && !is_evil($me['role'])) {
        throw new GameError('Loyal servants of Arthur must play Success.');
    }
    $s['questCards'][$me['id']] = $card;
    if (count($s['questCards']) < count($s['team'])) {
        return;
    }

    $q = $s['questNum'];
    $fails = count(array_filter($s['questCards'], fn($c) => $c === 'fail'));
    $needed = fails_needed($s, $q);
    $success = $fails < $needed;
    $s['questResults'][] = ['quest' => $q, 'team' => $s['team'], 'fails' => $fails, 'needed' => $needed, 'success' => $success];
    $cards = $fails === 1 ? '1 Fail card' : "$fails Fail cards";
    add_log($s, 'Quest ' . ($q + 1) . ($success ? ' succeeded' : ' failed') . " ($cards).");

    $s['questCards'] = [];
    $s['team'] = [];
    $s['questNum']++;
    advance_leader($s);

    $wins = count(array_filter($s['questResults'], fn($r) => $r['success']));
    $losses = count($s['questResults']) - $wins;
    if ($losses >= 3) {
        end_game($s, 'evil', 'Three quests failed.');
    } elseif ($wins >= 3) {
        $s['phase'] = 'assassin';
        add_log($s, 'Good has completed three quests. Evil is revealed, and the Assassin must now find Merlin.');
    } elseif ($s['lady'] && in_array(count($s['questResults']), [2, 3, 4], true)) {
        $s['phase'] = 'lady';
    } else {
        $s['phase'] = 'proposing';
    }
}

function use_lady(array &$s, array $me, mixed $targetId): void
{
    need_phase($s, 'lady');
    if ($s['lady']['holderId'] !== $me['id']) {
        throw new GameError('Only the holder of the Lady of the Lake can do that.');
    }
    $target = require_target($s, $targetId);
    if ($target['id'] === $me['id']) {
        throw new GameError('You cannot examine yourself.');
    }
    if (in_array($target['id'], $s['lady']['previous'], true)) {
        throw new GameError('That player has already held the Lady of the Lake.');
    }
    $s['ladyResults'][] = [
        'holderId'   => $me['id'],
        'targetId'   => $target['id'],
        'evil'       => is_evil($target['role']),
        'afterQuest' => count($s['questResults']),
    ];
    $s['lady']['holderId'] = $target['id'];
    $s['lady']['previous'][] = $target['id'];
    add_log($s, "{$me['name']} used the Lady of the Lake on {$target['name']}.");
    $s['phase'] = 'proposing';
}

function assassinate(array &$s, array $me, mixed $targetId): void
{
    need_phase($s, 'assassin');
    if ($s['assassinId'] !== $me['id']) {
        throw new GameError('Only the Assassin can choose.');
    }
    $target = require_target($s, $targetId);
    if (is_evil($target['role'])) {
        throw new GameError('Choose a player on the side of Good.');
    }
    $s['assassinTarget'] = $target['id'];
    if ($target['role'] === 'merlin') {
        end_game($s, 'evil', "The Assassin struck {$target['name']}, who was Merlin.");
    } else {
        end_game($s, 'good', "The Assassin struck {$target['name']}, who was not Merlin. Merlin stays safe.");
    }
}

function end_game(array &$s, string $winner, string $reason): void
{
    $s['phase'] = 'ended';
    $s['winner'] = $winner;
    $s['winReason'] = $reason;
    add_log($s, ($winner === 'good' ? 'Good wins! ' : 'Evil wins! ') . $reason);
}

/** What $me knows about the other players from their role at game start. */
function knowledge(array $s, array $me): array
{
    $mine = $me['role'];
    $morganaInGame = in_array('morgana', array_column($s['players'], 'role'), true);
    $out = [];
    foreach ($s['players'] as $p) {
        if ($p['id'] === $me['id']) {
            continue;
        }
        $r = $p['role'];
        if ($mine === 'merlin' && is_evil($r) && $r !== 'mordred') {
            $out[$p['id']] = 'evil';
        } elseif ($mine === 'percival' && ($r === 'merlin' || $r === 'morgana')) {
            $out[$p['id']] = $morganaInGame ? 'merlin?' : 'merlin';
        } elseif (is_evil($mine) && $mine !== 'oberon' && is_evil($r) && $r !== 'oberon') {
            $out[$p['id']] = 'evil';
        }
    }
    return $out;
}

function build_view(array $s, array $me): array
{
    $phase = $s['phase'];
    $inGame = $phase !== 'lobby';
    $ended = $phase === 'ended';
    $evilRevealed = $phase === 'assassin' || $ended;
    $n = count($s['players']);

    $players = [];
    foreach ($s['players'] as $i => $p) {
        $entry = ['id' => $p['id'], 'name' => $p['name'], 'seat' => $i];
        if ($ended) {
            $entry['role'] = $p['role'];
        }
        if ($evilRevealed && is_evil($p['role'])) {
            $entry['evil'] = true;
        }
        $players[] = $entry;
    }

    $mine = null;
    if ($inGame) {
        $mine = [
            'role'         => $me['role'],
            'evil'         => is_evil($me['role']),
            'isAssassin'   => $s['assassinId'] === $me['id'],
            'knows'        => (object) knowledge($s, $me),
            'ladyFindings' => array_values(array_filter($s['ladyResults'], fn($r) => $r['holderId'] === $me['id'])),
        ];
    }

    return [
        'code'           => $s['code'],
        'version'        => $s['version'],
        'phase'          => $phase,
        'hostId'         => $s['hostId'],
        'meId'           => $me['id'],
        'settings'       => $s['settings'],
        'players'        => $players,
        'teamSizes'      => TEAM_SIZES[$n] ?? null,
        'twoFailQuest'   => $n >= 7 ? 3 : null,
        'questNum'       => $s['questNum'],
        'rejectCount'    => $s['rejectCount'],
        'leaderId'       => $inGame ? leader($s)['id'] : null,
        'team'           => $s['team'],
        'voted'          => $phase === 'voting' ? array_keys($s['votes']) : [],
        'played'         => $phase === 'questing' ? array_keys($s['questCards']) : [],
        'myVote'         => $phase === 'voting' ? ($s['votes'][$me['id']] ?? null) : null,
        'myCard'         => $phase === 'questing' ? ($s['questCards'][$me['id']] ?? null) : null,
        'proposals'      => $s['proposals'],
        'questResults'   => $s['questResults'],
        'lady'           => $s['lady'],
        'ladyChecks'     => array_map(fn($r) => ['holderId' => $r['holderId'], 'targetId' => $r['targetId'], 'afterQuest' => $r['afterQuest']], $s['ladyResults']),
        'assassinId'     => ($evilRevealed || $s['assassinId'] === $me['id']) ? $s['assassinId'] : null,
        'assassinTarget' => $s['assassinTarget'],
        'winner'         => $s['winner'],
        'winReason'      => $s['winReason'],
        'me'             => $mine,
        'log'            => array_slice($s['log'], -40),
        'chat'           => array_slice($s['chat'], -100),
    ];
}
