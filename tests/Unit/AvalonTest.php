<?php

namespace Tests\Unit;

use App\Game\Avalon;
use App\Game\GameError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AvalonTest extends TestCase
{
    private const ALL_ROLES = ['percival' => true, 'morgana' => true, 'mordred' => true, 'oberon' => true, 'lady' => true];

    private const CLASSIC = ['percival' => true, 'morgana' => true, 'mordred' => false, 'oberon' => false, 'lady' => false];

    /** Creates a started game with $n players. Returns [state, players by id]. */
    private function startedGame(int $n, array $settings): array
    {
        [$s, $host] = Avalon::newRoom('P0');
        for ($i = 1; $i < $n; $i++) {
            Avalon::join($s, "P$i", null);
        }
        Avalon::apply($s, $host, 'settings', $settings);
        Avalon::apply($s, $host, 'start', []);

        return [$s, $this->byId($s)];
    }

    private function byId(array $s): array
    {
        return array_column($s['players'], null, 'id');
    }

    private function act(array &$s, string $id, string $action, array $in = []): void
    {
        Avalon::apply($s, $this->byId($s)[$id], $action, $in);
    }

    private function assertRejected(callable $fn, string $why): void
    {
        try {
            $fn();
        } catch (GameError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("Expected a rule violation: $why");
    }

    public static function games(): array
    {
        return [
            '5p, Evil fails quests' => [5, self::CLASSIC, 'evil'],
            '5p, every team rejected' => [5, self::CLASSIC, 'reject'],
            '7p, all roles, Assassin misses' => [7, self::ALL_ROLES, 'good'],
            '8p, Evil fails quests' => [8, ['mordred' => true, 'oberon' => false, 'lady' => true] + self::CLASSIC, 'evil'],
            '10p, all roles, Assassin finds Merlin' => [10, self::ALL_ROLES, 'merlin'],
            '10p, all roles, random votes' => [10, self::ALL_ROLES, 'mixed'],
        ];
    }

    #[DataProvider('games')]
    public function test_full_game_follows_the_rules(int $n, array $settings, string $strategy): void
    {
        [$s, $players] = $this->startedGame($n, $settings);
        $roles = array_map(fn ($p) => $p['role'], $players);
        $ids = array_keys($players);

        $this->assertCount(Avalon::EVIL_COUNT[$n], array_filter($roles, [Avalon::class, 'isEvil']));
        $this->assertContains('merlin', $roles);
        $this->assertKnowledgeIsCorrect($s, $roles);

        for ($guard = 0; $guard < 200 && $s['phase'] !== 'ended'; $guard++) {
            $v = Avalon::view($s, $players[$ids[0]]);
            switch ($s['phase']) {
                case 'proposing':
                    $size = $v['teamSizes'][$v['questNum']];
                    $leader = $v['leaderId'];
                    $other = $ids[0] === $leader ? $ids[1] : $ids[0];
                    $this->assertRejected(fn () => $this->act($s, $other, 'propose', ['team' => array_slice($ids, 0, $size)]), 'non-leader proposes');
                    $this->assertRejected(fn () => $this->act($s, $leader, 'propose', ['team' => array_slice($ids, 0, $size + 1)]), 'wrong team size');
                    $team = $ids;
                    shuffle($team);
                    $this->act($s, $leader, 'propose', ['team' => array_slice($team, 0, $size)]);
                    break;

                case 'voting':
                    $before = count($s['proposals']);
                    foreach ($ids as $i => $id) {
                        $approve = match ($strategy) {
                            'reject' => false,
                            'mixed' => mt_rand(0, 2) > 0,
                            default => true,
                        };
                        $this->act($s, $id, 'vote', ['approve' => $approve]);
                        if ($i < $n - 1) {
                            $this->assertCount($before, $s['proposals'], 'votes stay hidden until everyone has voted');
                        }
                    }
                    break;

                case 'questing':
                    foreach ($s['team'] as $id) {
                        $evil = Avalon::isEvil($roles[$id]);
                        if (! $evil) {
                            $this->assertRejected(fn () => $this->act($s, $id, 'quest', ['card' => 'fail']), 'Good plays Fail');
                        }
                        $fail = $evil && in_array($strategy, ['evil', 'mixed'], true);
                        $this->act($s, $id, 'quest', ['card' => $fail ? 'fail' : 'success']);
                    }
                    break;

                case 'lady':
                    $holder = $s['lady']['holderId'];
                    $this->assertRejected(fn () => $this->act($s, $holder, 'lady', ['target' => $s['lady']['previous'][0]]), 'Lady on a previous holder');
                    $target = current(array_diff($ids, $s['lady']['previous']));
                    $this->act($s, $holder, 'lady', ['target' => $target]);
                    $findings = Avalon::view($s, $players[$holder])['me']['ladyFindings'];
                    $this->assertSame(Avalon::isEvil($roles[$target]), end($findings)['evil']);
                    break;

                case 'assassin':
                    $assassin = $s['assassinId'];
                    $this->assertTrue(Avalon::isEvil($roles[$assassin]));
                    $this->assertNotSame('oberon', $roles[$assassin]);
                    $merlin = array_search('merlin', $roles, true);
                    $servant = array_search('servant', $roles, true);
                    $this->act($s, $assassin, 'assassinate', ['target' => $strategy === 'merlin' ? $merlin : $servant]);
                    $this->assertSame($strategy === 'merlin' ? 'evil' : 'good', $s['winner']);
                    break;
            }
        }

        $this->assertSame('ended', $s['phase']);
        if ($strategy === 'reject') {
            $this->assertSame('evil', $s['winner']);
            $this->assertCount(5, $s['proposals']);
        }
        foreach (Avalon::view($s, $players[$ids[1]])['players'] as $p) {
            $this->assertArrayHasKey('role', $p, 'roles are revealed at the end');
        }

        $host = $players[$ids[0]];
        Avalon::apply($s, $host, 'restart', []);
        $this->assertSame('lobby', $s['phase']);
        $this->assertCount($n, $s['players']);
    }

    private function assertKnowledgeIsCorrect(array $s, array $roles): void
    {
        foreach ($s['players'] as $me) {
            $view = Avalon::view($s, $me);
            foreach ($view['players'] as $p) {
                $this->assertArrayNotHasKey('role', $p, 'no roles leak before the end');
            }
            $knows = (array) $view['me']['knows'];
            $role = $me['role'];
            foreach ($roles as $id => $r) {
                if ($id === $me['id']) {
                    continue;
                }
                $expected = match (true) {
                    $role === 'merlin' => Avalon::isEvil($r) && $r !== 'mordred',
                    $role === 'percival' => in_array($r, ['merlin', 'morgana'], true),
                    Avalon::isEvil($role) && $role !== 'oberon' => Avalon::isEvil($r) && $r !== 'oberon',
                    default => false,
                };
                $this->assertSame($expected, isset($knows[$id]), "$role seeing $r");
            }
        }
    }

    public function test_quest_four_needs_two_fails_with_seven_players(): void
    {
        [$s] = $this->startedGame(7, self::CLASSIC);
        $s['questNum'] = 3;
        $s['phase'] = 'questing';
        $evil = array_values(array_filter($s['players'], fn ($p) => Avalon::isEvil($p['role'])));
        $good = array_values(array_filter($s['players'], fn ($p) => ! Avalon::isEvil($p['role'])));
        $s['team'] = [$evil[0]['id'], $good[0]['id'], $good[1]['id'], $good[2]['id']];

        $this->act($s, $evil[0]['id'], 'quest', ['card' => 'fail']);
        foreach (array_slice($s['team'], 1) as $id) {
            $this->act($s, $id, 'quest', ['card' => 'success']);
        }

        $this->assertTrue(end($s['questResults'])['success'], 'one Fail is not enough on quest 4');
    }

    public function test_too_many_evil_roles_cannot_start(): void
    {
        [$s, $host] = Avalon::newRoom('Host');
        for ($i = 1; $i < 5; $i++) {
            Avalon::join($s, "P$i", null);
        }
        Avalon::apply($s, $host, 'settings', self::ALL_ROLES);

        $this->expectException(GameError::class);
        Avalon::apply($s, $host, 'start', []);
    }

    public function test_one_human_can_play_a_whole_game_against_bots(): void
    {
        for ($game = 0; $game < 20; $game++) {
            [$s, $host] = Avalon::newRoom('Human');
            for ($i = 0; $i < 6; $i++) {
                Avalon::apply($s, $host, 'addBot', []);
            }
            Avalon::apply($s, $host, 'settings', self::ALL_ROLES);
            $this->assertSame(['Kay', 'Gawain'], array_slice(array_column($s['players'], 'name'), 1, 2));
            $this->assertTrue(Avalon::view($s, $host)['players'][1]['bot']);
            Avalon::apply($s, $host, 'start', []);

            // The human only ever makes their own moves; bots handle everything else.
            for ($guard = 0; $guard < 100 && $s['phase'] !== 'ended'; $guard++) {
                $me = $s['players'][0];
                $v = Avalon::view($s, $me);
                match ($s['phase']) {
                    'proposing' => $this->assertSame($me['id'], $v['leaderId'], 'game waits only on the human'),
                    'voting' => $this->assertArrayNotHasKey($me['id'], $s['votes']),
                    'questing' => $this->assertContains($me['id'], $s['team']),
                    'lady' => $this->assertSame($me['id'], $s['lady']['holderId']),
                    'assassin' => $this->assertSame($me['id'], $s['assassinId']),
                };
                $others = array_values(array_diff(array_column($s['players'], 'id'), [$me['id']]));
                match ($s['phase']) {
                    'proposing' => Avalon::apply($s, $me, 'propose', ['team' => array_slice([$me['id'], ...$others], 0, $v['teamSizes'][$v['questNum']])]),
                    'voting' => Avalon::apply($s, $me, 'vote', ['approve' => true]),
                    'questing' => Avalon::apply($s, $me, 'quest', ['card' => 'success']),
                    'lady' => Avalon::apply($s, $me, 'lady', ['target' => current(array_diff($others, $s['lady']['previous']))]),
                    'assassin' => Avalon::apply($s, $me, 'assassinate', ['target' => current(array_filter($others, fn ($id) => ! Avalon::isEvil($s['players'][Avalon::findPlayer($s, $id)]['role'])))]),
                };
            }
            $this->assertSame('ended', $s['phase']);
        }
    }

    public function test_host_can_choose_their_role_for_testing(): void
    {
        foreach (['merlin', 'percival', 'morgana', 'assassin', 'servant', 'minion'] as $role) {
            [$s, $host] = Avalon::newRoom('Host');
            for ($i = 0; $i < 6; $i++) {
                Avalon::apply($s, $host, 'addBot', []);
            }
            Avalon::apply($s, $host, 'settings', ['hostRole' => $role]);
            $this->assertArrayNotHasKey('hostRole', Avalon::view($s, $s['players'][1])['settings'], 'others cannot see the choice');
            Avalon::apply($s, $host, 'start', []);
            $this->assertSame($role, $s['players'][0]['role']);
            $this->assertNull($s['settings']['hostRole'], 'the pick is used up; the next game is random');
        }

        // Every Evil seat is a named role (Morgana + Mordred at 5 players), so there's
        // no separate Assassin card: picking Assassin still gives the host the duty.
        [$s, $host] = Avalon::newRoom('Host');
        for ($i = 0; $i < 4; $i++) {
            Avalon::apply($s, $host, 'addBot', []);
        }
        Avalon::apply($s, $host, 'settings', ['mordred' => true, 'hostRole' => 'assassin']);
        Avalon::apply($s, $host, 'start', []);
        $this->assertContains($s['players'][0]['role'], ['morgana', 'mordred']);
        $this->assertSame($host['id'], $s['assassinId']);
        $this->assertTrue(Avalon::view($s, $s['players'][0])['me']['isAssassin']);

        [$s, $host] = Avalon::newRoom('Host');
        for ($i = 0; $i < 4; $i++) {
            Avalon::apply($s, $host, 'addBot', []);
        }
        Avalon::apply($s, $host, 'settings', ['hostRole' => 'oberon']); // Oberon is off by default
        $this->expectException(GameError::class);
        Avalon::apply($s, $host, 'start', []);
    }

    public function test_roles_are_dealt_randomly(): void
    {
        // Over many deals every seat should receive Merlin sometimes, and Evil sometimes.
        $merlinSeats = [];
        $evilSeats = [];
        for ($game = 0; $game < 300; $game++) {
            [$s, $host] = Avalon::newRoom('Host');
            for ($i = 0; $i < 4; $i++) {
                Avalon::apply($s, $host, 'addBot', []);
            }
            Avalon::apply($s, $host, 'start', []);
            foreach ($s['players'] as $seat => $p) {
                if ($p['role'] === 'merlin') {
                    $merlinSeats[$seat] = true;
                }
                if (Avalon::isEvil($p['role'])) {
                    $evilSeats[$seat] = true;
                }
            }
        }
        $this->assertCount(5, $merlinSeats, 'every seat, the host included, can be Merlin');
        $this->assertCount(5, $evilSeats, 'every seat, the host included, can be Evil');
    }

    public function test_play_again_deals_a_new_game_straight_away(): void
    {
        [$s, $host] = Avalon::newRoom('Host');
        for ($i = 0; $i < 6; $i++) {
            Avalon::apply($s, $host, 'addBot', []);
        }
        Avalon::apply($s, $host, 'settings', ['oberon' => true, 'lady' => true]);
        Avalon::apply($s, $host, 'start', []);
        $this->assertRejected(fn () => Avalon::apply($s, $host, 'rematch', []), 'rematch before the game ends');

        $s['phase'] = 'ended';
        $s['winner'] = 'good';
        $s['questResults'] = [['quest' => 0, 'team' => [], 'fails' => 0, 'needed' => 1, 'success' => true]];
        $this->assertRejected(fn () => Avalon::apply($s, $s['players'][1], 'rematch', []), 'only the host restarts');

        Avalon::apply($s, $host, 'rematch', []);
        $this->assertNotContains($s['phase'], ['lobby', 'ended'], 'a new game is under way');
        $this->assertCount(7, $s['players'], 'same table');
        $this->assertTrue($s['settings']['oberon'], 'same settings');
        $this->assertContains('oberon', array_column($s['players'], 'role'));
        $this->assertNotNull($s['lady'], 'Lady of the Lake dealt again');
        $this->assertNull($s['winner']);
        $this->assertEmpty($s['questResults'], 'fresh quest track');
    }

    public function test_join_rules(): void
    {
        [$s, $host] = Avalon::newRoom('Host');
        $this->assertRejected(fn () => Avalon::join($s, 'host', null), 'duplicate name, any case');

        $rejoined = Avalon::join($s, 'Whatever', $host['token']);
        $this->assertSame($host['id'], $rejoined['id'], 'a known token rejoins the same seat');

        for ($i = 1; $i < 10; $i++) {
            Avalon::join($s, "P$i", null);
        }
        $this->assertRejected(fn () => Avalon::join($s, 'Eleventh', null), 'room full');
    }
}
