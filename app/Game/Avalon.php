<?php

namespace App\Game;

use Random\Randomizer;

/**
 * Avalon rules engine. A room's whole game is one state array ($s) that is
 * stored as JSON. Every player action goes through apply(), which validates
 * it against the rules; view() builds what one specific player may see.
 */
final class Avalon
{
    public const MIN_PLAYERS = 5;

    public const MAX_PLAYERS = 10;

    /** Players per quest, by player count. */
    public const TEAM_SIZES = [
        5 => [2, 3, 2, 3, 3],
        6 => [2, 3, 4, 3, 4],
        7 => [2, 3, 3, 4, 4],
        8 => [3, 4, 4, 5, 5],
        9 => [3, 4, 4, 5, 5],
        10 => [3, 4, 4, 5, 5],
    ];

    public const EVIL_COUNT = [5 => 2, 6 => 2, 7 => 3, 8 => 3, 9 => 3, 10 => 4];

    public const EVIL_ROLES = ['assassin', 'morgana', 'mordred', 'oberon', 'minion'];

    public const SETTING_KEYS = ['percival', 'morgana', 'mordred', 'oberon', 'lady'];

    /** Knights to name bots after; there are enough for a full table of bots. */
    private const BOT_NAMES = ['Kay', 'Gawain', 'Tristan', 'Galahad', 'Gareth', 'Bors', 'Bedivere', 'Lamorak', 'Ector', 'Lionel', 'Pelleas'];

    public const ACTIONS = ['addBot', 'settings', 'kick', 'leave', 'start', 'propose', 'vote', 'quest', 'lady', 'assassinate', 'chat', 'restart', 'rematch'];

    public static function isEvil(?string $role): bool
    {
        return $role !== null && in_array($role, self::EVIL_ROLES, true);
    }

    private static function rng(): Randomizer
    {
        static $r = null;

        return $r ??= new Randomizer;
    }

    public static function cleanName(mixed $name): string
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

    public static function cleanCode(mixed $code): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $code));
        if (strlen($code) !== 4) {
            throw new GameError('Enter the 4-letter room code.');
        }

        return $code;
    }

    public static function randomCode(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I or O
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $letters[random_int(0, strlen($letters) - 1)];
        }

        return $code;
    }

    public static function newPlayer(string $name): array
    {
        return [
            'id' => 'p'.bin2hex(random_bytes(4)),
            'token' => bin2hex(random_bytes(16)),
            'name' => $name,
            'role' => null,
        ];
    }

    /** @return array{0: array, 1: array} [state, host player] */
    public static function newRoom(mixed $hostName): array
    {
        $host = self::newPlayer(self::cleanName($hostName));
        $s = [
            'code' => '',
            'version' => 0,
            'hostId' => $host['id'],
            'phase' => 'lobby',
            'settings' => ['percival' => true, 'morgana' => true, 'mordred' => false, 'oberon' => false, 'lady' => false],
            'players' => [$host],
            'chat' => [],
            'log' => [],
        ];
        self::resetGame($s);
        self::log($s, "{$host['name']} created the room.");

        return [$s, $host];
    }

    public static function resetGame(array &$s): void
    {
        foreach ($s['players'] as &$p) {
            $p['role'] = null;
        }
        unset($p);
        $s['leaderIdx'] = 0;
        $s['questNum'] = 0;      // 0-based index of the current quest
        $s['rejectCount'] = 0;   // consecutive rejected proposals for this quest
        $s['team'] = [];
        $s['votes'] = [];
        $s['questCards'] = [];
        $s['questResults'] = [];
        $s['proposals'] = [];
        $s['lady'] = null;
        $s['ladyResults'] = [];
        $s['assassinId'] = null;
        $s['assassinTarget'] = null;
        $s['winner'] = null;
        $s['winReason'] = null;
    }

    private static function log(array &$s, string $text): void
    {
        $s['log'][] = ['t' => time(), 'text' => $text];
        $s['log'] = array_slice($s['log'], -100);
    }

    public static function findPlayer(array $s, mixed $id): ?int
    {
        foreach ($s['players'] as $i => $p) {
            if ($p['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private static function requireTarget(array $s, mixed $id): array
    {
        $i = self::findPlayer($s, $id);
        if ($i === null) {
            throw new GameError('Choose a player.');
        }

        return $s['players'][$i];
    }

    public static function playerByToken(array $s, ?string $token): ?array
    {
        if (! $token) {
            return null;
        }
        foreach ($s['players'] as $p) {
            if (hash_equals($p['token'], $token)) {
                return $p;
            }
        }

        return null;
    }

    public static function requirePlayer(array $s, ?string $token): array
    {
        return self::playerByToken($s, $token)
            ?? throw new GameError('You are not in this room.', 'not_player');
    }

    private static function leader(array $s): array
    {
        return $s['players'][$s['leaderIdx']];
    }

    private static function teamSize(array $s): int
    {
        return self::TEAM_SIZES[count($s['players'])][$s['questNum']];
    }

    private static function failsNeeded(array $s, int $quest): int
    {
        return count($s['players']) >= 7 && $quest === 3 ? 2 : 1;
    }

    private static function needPhase(array $s, string $phase): void
    {
        if ($s['phase'] !== $phase) {
            throw new GameError('That action is not available right now.');
        }
    }

    private static function needHost(array $s, array $me): void
    {
        if ($s['hostId'] !== $me['id']) {
            throw new GameError('Only the host can do that.');
        }
    }

    public static function join(array &$s, mixed $name, ?string $token): array
    {
        if ($existing = self::playerByToken($s, $token)) {
            return $existing;
        }
        $name = self::cleanName($name);
        if ($s['phase'] !== 'lobby') {
            throw new GameError('This game has already started.');
        }
        if (count($s['players']) >= self::MAX_PLAYERS) {
            throw new GameError('This room is full (10 players max).');
        }
        foreach ($s['players'] as $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                throw new GameError('That name is already taken in this room.');
            }
        }
        $p = self::newPlayer($name);
        $s['players'][] = $p;
        self::log($s, "$name joined.");

        return $p;
    }

    /** Applies a player's action, then lets any bots whose turn it is play. */
    public static function apply(array &$s, array $me, string $action, array $in): void
    {
        self::perform($s, $me, $action, $in);
        self::runBots($s);
    }

    private static function perform(array &$s, array $me, string $action, array $in): void
    {
        switch ($action) {
            case 'addBot':
                self::needHost($s, $me);
                self::needPhase($s, 'lobby');
                if (count($s['players']) >= self::MAX_PLAYERS) {
                    throw new GameError('This room is full (10 players max).');
                }
                $taken = array_map('mb_strtolower', array_column($s['players'], 'name'));
                $name = current(array_filter(self::BOT_NAMES, fn ($n) => ! in_array(mb_strtolower($n), $taken, true)));
                $bot = self::newPlayer($name) + ['bot' => true];
                $s['players'][] = $bot;
                self::log($s, "$name (a bot) joined.");

                return;

            case 'settings':
                self::needHost($s, $me);
                self::needPhase($s, 'lobby');
                foreach (self::SETTING_KEYS as $key) {
                    if (array_key_exists($key, $in)) {
                        $s['settings'][$key] = (bool) $in[$key];
                    }
                }
                // Test mode: the host chooses their own role (the controller only allows this locally).
                if (array_key_exists('hostRole', $in)) {
                    $role = $in['hostRole'] ?: null;
                    if ($role !== null && ! in_array($role, [...self::EVIL_ROLES, 'merlin', 'percival', 'servant'], true)) {
                        throw new GameError('Unknown role.');
                    }
                    $s['settings']['hostRole'] = $role;
                }

                return;

            case 'kick':
                self::needHost($s, $me);
                self::needPhase($s, 'lobby');
                $target = self::requireTarget($s, $in['target'] ?? null);
                if ($target['id'] === $me['id']) {
                    throw new GameError('You cannot remove yourself.');
                }
                self::removePlayer($s, $target['id']);
                self::log($s, "{$target['name']} was removed by the host.");

                return;

            case 'leave':
                self::needPhase($s, 'lobby');
                self::removePlayer($s, $me['id']);
                self::log($s, "{$me['name']} left.");

                return;

            case 'start':
                self::needHost($s, $me);
                self::needPhase($s, 'lobby');
                self::start($s);

                return;

            case 'propose':
                self::propose($s, $me, $in['team'] ?? null);

                return;

            case 'vote':
                self::vote($s, $me, $in['approve'] ?? null);

                return;

            case 'quest':
                self::playCard($s, $me, $in['card'] ?? null);

                return;

            case 'lady':
                self::useLady($s, $me, $in['target'] ?? null);

                return;

            case 'assassinate':
                self::assassinate($s, $me, $in['target'] ?? null);

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
                self::needHost($s, $me);
                if ($s['phase'] === 'lobby') {
                    return;
                }
                self::resetGame($s);
                $s['phase'] = 'lobby';
                self::log($s, 'The host returned everyone to the lobby.');

                return;

            case 'rematch':
                // Straight into a new game: same players and settings, new roles.
                self::needHost($s, $me);
                self::needPhase($s, 'ended');
                self::log($s, 'The host started a new game with the same table.');
                self::start($s);

                return;
        }
        throw new GameError('Unknown action.');
    }

    private static function removePlayer(array &$s, string $id): void
    {
        $s['players'] = array_values(array_filter($s['players'], fn ($p) => $p['id'] !== $id));
        if ($s['hostId'] === $id && $s['players']) {
            $s['hostId'] = $s['players'][0]['id'];
            self::log($s, "{$s['players'][0]['name']} is now the host.");
        }
    }

    private static function start(array &$s): void
    {
        $n = count($s['players']);
        if ($n < self::MIN_PLAYERS || $n > self::MAX_PLAYERS) {
            throw new GameError('Avalon needs 5 to 10 players.');
        }
        self::resetGame($s);
        $set = $s['settings'];
        $evilCount = self::EVIL_COUNT[$n];

        $evil = array_values(array_filter(['morgana', 'mordred', 'oberon'], fn ($r) => $set[$r]));
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

        $roles = self::rng()->shuffleArray(array_merge($good, $evil));
        $wanted = $set['hostRole'] ?? null;
        if ($wanted) {
            $at = array_search($wanted, $roles, true);
            if ($at === false && $wanted === 'assassin') {
                // No separate Assassin card in this setup: take an Evil role that can
                // carry the Assassin's duty (assigned to the host below).
                $at = array_search(true, array_map(fn ($r) => self::isEvil($r) && $r !== 'oberon', $roles), true);
            }
            if ($at === false) {
                throw new GameError('Your chosen role is not in this setup. Turn it on, or pick another role.');
            }
            $hostAt = self::findPlayer($s, $s['hostId']);
            [$roles[$at], $roles[$hostAt]] = [$roles[$hostAt], $roles[$at]];
            // The pick applies to this game only; the next one is random again.
            $s['settings']['hostRole'] = null;
        }
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
            if (self::isEvil($p['role']) && $p['role'] !== 'oberon') {
                $candidates[] = $p['id'];
            }
        }
        $s['assassinId'] = $wanted === 'assassin'
            ? $s['hostId']
            : $candidates[self::rng()->getInt(0, count($candidates) - 1)];

        $s['leaderIdx'] = self::rng()->getInt(0, $n - 1);
        $s['phase'] = 'proposing';
        self::log($s, "The game has begun: $n players, $evilCount of them serve Mordred.");

        if ($set['lady']) {
            $holder = $s['players'][($s['leaderIdx'] + $n - 1) % $n];
            $s['lady'] = ['holderId' => $holder['id'], 'previous' => [$holder['id']]];
            self::log($s, "{$holder['name']} holds the Lady of the Lake.");
        }
        self::log($s, self::leader($s)['name'].' is the first leader.');
    }

    private static function advanceLeader(array &$s): void
    {
        $s['leaderIdx'] = ($s['leaderIdx'] + 1) % count($s['players']);
    }

    private static function propose(array &$s, array $me, mixed $team): void
    {
        self::needPhase($s, 'proposing');
        if (self::leader($s)['id'] !== $me['id']) {
            throw new GameError('Only the leader can propose a team.');
        }
        if (! is_array($team)) {
            throw new GameError('Choose the players for the team.');
        }
        $team = array_values(array_unique(array_map('strval', $team)));
        $size = self::teamSize($s);
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
        $names = implode(', ', array_map(fn ($id) => $s['players'][self::findPlayer($s, $id)]['name'], $ordered));
        self::log($s, "{$me['name']} proposed $names for Quest ".($s['questNum'] + 1).'.');
    }

    private static function vote(array &$s, array $me, mixed $approve): void
    {
        self::needPhase($s, 'voting');
        if (! is_bool($approve)) {
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
            'quest' => $s['questNum'],
            'attempt' => $s['rejectCount'] + 1,
            'leaderId' => self::leader($s)['id'],
            'team' => $s['team'],
            'votes' => $votes,
            'approved' => $approved,
        ];
        $s['votes'] = [];

        if ($approved) {
            self::log($s, "The team was approved ({$yes}–{$no}). The quest begins.");
            $s['rejectCount'] = 0;
            $s['questCards'] = [];
            $s['phase'] = 'questing';

            return;
        }

        $s['rejectCount']++;
        self::log($s, "The team was rejected ({$yes}–{$no}).");
        if ($s['rejectCount'] >= 5) {
            self::end($s, 'evil', 'Five team proposals in a row were rejected.');

            return;
        }
        self::advanceLeader($s);
        $s['team'] = [];
        $s['phase'] = 'proposing';
    }

    private static function playCard(array &$s, array $me, mixed $card): void
    {
        self::needPhase($s, 'questing');
        if (! in_array($me['id'], $s['team'], true)) {
            throw new GameError('You are not on this quest.');
        }
        if (isset($s['questCards'][$me['id']])) {
            throw new GameError('You have already played your card.');
        }
        if ($card !== 'success' && $card !== 'fail') {
            throw new GameError('Play Success or Fail.');
        }
        if ($card === 'fail' && ! self::isEvil($me['role'])) {
            throw new GameError('Loyal servants of Arthur must play Success.');
        }
        $s['questCards'][$me['id']] = $card;
        if (count($s['questCards']) < count($s['team'])) {
            return;
        }

        $q = $s['questNum'];
        $fails = count(array_filter($s['questCards'], fn ($c) => $c === 'fail'));
        $needed = self::failsNeeded($s, $q);
        $success = $fails < $needed;
        $s['questResults'][] = ['quest' => $q, 'team' => $s['team'], 'fails' => $fails, 'needed' => $needed, 'success' => $success];
        $cards = $fails === 1 ? '1 Fail card' : "$fails Fail cards";
        self::log($s, 'Quest '.($q + 1).($success ? ' succeeded' : ' failed')." ($cards).");

        $s['questCards'] = [];
        $s['team'] = [];
        $s['questNum']++;
        self::advanceLeader($s);

        $wins = count(array_filter($s['questResults'], fn ($r) => $r['success']));
        $losses = count($s['questResults']) - $wins;
        if ($losses >= 3) {
            self::end($s, 'evil', 'Three quests failed.');
        } elseif ($wins >= 3) {
            $s['phase'] = 'assassin';
            self::log($s, 'Good has completed three quests. Evil is revealed, and the Assassin must now find Merlin.');
        } elseif ($s['lady'] && in_array(count($s['questResults']), [2, 3, 4], true)) {
            $s['phase'] = 'lady';
        } else {
            $s['phase'] = 'proposing';
        }
    }

    private static function useLady(array &$s, array $me, mixed $targetId): void
    {
        self::needPhase($s, 'lady');
        if ($s['lady']['holderId'] !== $me['id']) {
            throw new GameError('Only the holder of the Lady of the Lake can do that.');
        }
        $target = self::requireTarget($s, $targetId);
        if ($target['id'] === $me['id']) {
            throw new GameError('You cannot examine yourself.');
        }
        if (in_array($target['id'], $s['lady']['previous'], true)) {
            throw new GameError('That player has already held the Lady of the Lake.');
        }
        $s['ladyResults'][] = [
            'holderId' => $me['id'],
            'targetId' => $target['id'],
            'evil' => self::isEvil($target['role']),
            'afterQuest' => count($s['questResults']),
        ];
        $s['lady']['holderId'] = $target['id'];
        $s['lady']['previous'][] = $target['id'];
        self::log($s, "{$me['name']} used the Lady of the Lake on {$target['name']}.");
        $s['phase'] = 'proposing';
    }

    private static function assassinate(array &$s, array $me, mixed $targetId): void
    {
        self::needPhase($s, 'assassin');
        if ($s['assassinId'] !== $me['id']) {
            throw new GameError('Only the Assassin can choose.');
        }
        $target = self::requireTarget($s, $targetId);
        if (self::isEvil($target['role'])) {
            throw new GameError('Choose a player on the side of Good.');
        }
        $s['assassinTarget'] = $target['id'];
        if ($target['role'] === 'merlin') {
            self::end($s, 'evil', "The Assassin struck {$target['name']}, who was Merlin.");
        } else {
            self::end($s, 'good', "The Assassin struck {$target['name']}, who was not Merlin. Merlin stays safe.");
        }
    }

    /** Plays every pending bot move until the game waits on a human (or ends). */
    private static function runBots(array &$s): void
    {
        for ($guard = 0; $guard < 500 && ($move = self::nextBotMove($s)); $guard++) {
            [$bot, $action, $in] = $move;
            self::perform($s, $bot, $action, $in);
        }
    }

    /** @return array{0: array, 1: string, 2: array}|null [bot, action, input] */
    private static function nextBotMove(array $s): ?array
    {
        $chance = fn (int $percent) => self::rng()->getInt(1, 100) <= $percent;
        $pick = fn (array $ids, int $n) => $n > 0 ? (array) self::rng()->pickArrayKeys(array_flip($ids), $n) : [];
        $isBot = fn (?array $p) => $p !== null && ! empty($p['bot']);
        $byId = array_column($s['players'], null, 'id');

        switch ($s['phase']) {
            case 'proposing':
                $leader = self::leader($s);
                if (! $isBot($leader)) {
                    return null;
                }
                $others = array_values(array_diff(array_keys($byId), [$leader['id']]));
                $team = [$leader['id'], ...$pick($others, self::teamSize($s) - 1)];

                return [$leader, 'propose', ['team' => $team]];

            case 'voting':
                $teamHasEvil = (bool) array_filter($s['team'], fn ($id) => self::isEvil($byId[$id]['role']));
                foreach ($s['players'] as $p) {
                    if (! $isBot($p) || isset($s['votes'][$p['id']])) {
                        continue;
                    }
                    $evil = self::isEvil($p['role']);
                    $approve = match (true) {
                        $s['rejectCount'] === 4 => ! $evil || $teamHasEvil, // Good never throws the game on the 5th vote
                        $evil => $teamHasEvil || $chance(30),
                        default => in_array($p['id'], $s['team'], true) || $chance(60),
                    };

                    return [$p, 'vote', ['approve' => $approve]];
                }

                return null;

            case 'questing':
                foreach ($s['team'] as $id) {
                    $p = $byId[$id];
                    if ($isBot($p) && ! isset($s['questCards'][$id])) {
                        $fail = self::isEvil($p['role']) && $chance(75);

                        return [$p, 'quest', ['card' => $fail ? 'fail' : 'success']];
                    }
                }

                return null;

            case 'lady':
                $holder = $byId[$s['lady']['holderId']];
                if (! $isBot($holder)) {
                    return null;
                }
                $eligible = array_values(array_diff(array_keys($byId), $s['lady']['previous']));

                return [$holder, 'lady', ['target' => $pick($eligible, 1)[0]]];

            case 'assassin':
                $assassin = $byId[$s['assassinId']];
                if (! $isBot($assassin)) {
                    return null;
                }
                $good = array_keys(array_filter($byId, fn ($p) => ! self::isEvil($p['role'])));

                return [$assassin, 'assassinate', ['target' => $pick($good, 1)[0]]];
        }

        return null;
    }

    private static function end(array &$s, string $winner, string $reason): void
    {
        $s['phase'] = 'ended';
        $s['winner'] = $winner;
        $s['winReason'] = $reason;
        self::log($s, ($winner === 'good' ? 'Good wins! ' : 'Evil wins! ').$reason);
    }

    /** What $me knows about the other players from their role at game start. */
    public static function knowledge(array $s, array $me): array
    {
        $mine = $me['role'];
        $morganaInGame = in_array('morgana', array_column($s['players'], 'role'), true);
        $out = [];
        foreach ($s['players'] as $p) {
            if ($p['id'] === $me['id']) {
                continue;
            }
            $r = $p['role'];
            if ($mine === 'merlin' && self::isEvil($r) && $r !== 'mordred') {
                $out[$p['id']] = 'evil';
            } elseif ($mine === 'percival' && ($r === 'merlin' || $r === 'morgana')) {
                $out[$p['id']] = $morganaInGame ? 'merlin?' : 'merlin';
            } elseif (self::isEvil($mine) && $mine !== 'oberon' && self::isEvil($r) && $r !== 'oberon') {
                $out[$p['id']] = 'evil';
            }
        }

        return $out;
    }

    /** The state as seen by one player: other players' secrets are removed. */
    public static function view(array $s, array $me): array
    {
        // Use the player's current record: $me may have been captured before
        // this change (e.g. before "start" dealt the roles).
        $me = $s['players'][self::findPlayer($s, $me['id'])] ?? $me;

        $phase = $s['phase'];
        $inGame = $phase !== 'lobby';
        $ended = $phase === 'ended';
        $evilRevealed = $phase === 'assassin' || $ended;
        $n = count($s['players']);

        $players = [];
        foreach ($s['players'] as $i => $p) {
            $entry = ['id' => $p['id'], 'name' => $p['name'], 'seat' => $i, 'bot' => ! empty($p['bot'])];
            if ($ended) {
                $entry['role'] = $p['role'];
            }
            if ($evilRevealed && self::isEvil($p['role'])) {
                $entry['evil'] = true;
            }
            $players[] = $entry;
        }

        $mine = null;
        if ($inGame) {
            $mine = [
                'role' => $me['role'],
                'evil' => self::isEvil($me['role']),
                'isAssassin' => $s['assassinId'] === $me['id'],
                'knows' => (object) self::knowledge($s, $me),
                'ladyFindings' => array_values(array_filter($s['ladyResults'], fn ($r) => $r['holderId'] === $me['id'])),
            ];
        }

        return [
            'code' => $s['code'],
            'version' => $s['version'],
            'phase' => $phase,
            'hostId' => $s['hostId'],
            'meId' => $me['id'],
            'settings' => $me['id'] === $s['hostId'] ? $s['settings'] : array_diff_key($s['settings'], ['hostRole' => 0]),
            'players' => $players,
            'teamSizes' => self::TEAM_SIZES[$n] ?? null,
            'twoFailQuest' => $n >= 7 ? 3 : null,
            'questNum' => $s['questNum'],
            'rejectCount' => $s['rejectCount'],
            'leaderId' => $inGame ? self::leader($s)['id'] : null,
            'team' => $s['team'],
            'voted' => $phase === 'voting' ? array_keys($s['votes']) : [],
            'played' => $phase === 'questing' ? array_keys($s['questCards']) : [],
            'myVote' => $phase === 'voting' ? ($s['votes'][$me['id']] ?? null) : null,
            'myCard' => $phase === 'questing' ? ($s['questCards'][$me['id']] ?? null) : null,
            'proposals' => array_map(fn ($p) => ['votes' => (object) $p['votes']] + $p, $s['proposals']),
            'questResults' => $s['questResults'],
            'lady' => $s['lady'],
            'ladyChecks' => array_map(fn ($r) => ['holderId' => $r['holderId'], 'targetId' => $r['targetId'], 'afterQuest' => $r['afterQuest']], $s['ladyResults']),
            'assassinId' => ($evilRevealed || $s['assassinId'] === $me['id']) ? $s['assassinId'] : null,
            'assassinTarget' => $s['assassinTarget'],
            'winner' => $s['winner'],
            'winReason' => $s['winReason'],
            'me' => $mine,
            'log' => array_slice($s['log'], -40),
            'chat' => array_slice($s['chat'], -100),
        ];
    }
}
