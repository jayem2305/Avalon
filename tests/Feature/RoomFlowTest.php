<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RoomFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Switches the test client to the given player's session. */
    private function as(string $code, ?string $token): static
    {
        $this->flushSession();

        return $this->withSession($token ? ['avalon.tokens' => [$code => $token]] : []);
    }

    private function tokens(string $code): array
    {
        return array_column(Room::where('code', $code)->first()->state['players'], 'token', 'name');
    }

    private function createRoomWithPlayers(int $n): array
    {
        $this->post('/rooms', ['name' => 'Arthur'])->assertRedirect();
        $code = Room::first()->code;
        for ($i = 1; $i < $n; $i++) {
            $this->as($code, null)->post('/join', ['code' => strtolower($code), 'name' => "Knight $i"])
                ->assertRedirect("/r/$code");
        }

        return [$code, $this->tokens($code)];
    }

    public function test_home_page_renders(): void
    {
        $this->get('/?room=abcd')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')->where('code', 'ABCD')->where('resume', null));
    }

    public function test_create_and_join_seat_players_in_their_session(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(3);

        $this->assertCount(3, $tokens);
        $this->as($code, $tokens['Knight 1'])->get("/r/$code")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Room')
                ->where('initial.phase', 'lobby')
                ->has('initial.players', 3));
    }

    public function test_visitors_without_a_seat_are_sent_to_the_join_form(): void
    {
        [$code] = $this->createRoomWithPlayers(1);

        $this->as($code, null)->get("/r/$code")->assertRedirect("/?room=$code");
        $this->as($code, null)->getJson("/r/$code/state")->assertStatus(422)->assertJson(['kind' => 'not_player']);
    }

    public function test_join_errors_are_reported_on_the_right_field(): void
    {
        [$code] = $this->createRoomWithPlayers(1);

        $this->as($code, null)->post('/join', ['code' => 'ZZZZ', 'name' => 'Kay'])->assertSessionHasErrors('code');
        $this->as($code, null)->post('/join', ['code' => $code, 'name' => 'arthur'])->assertSessionHasErrors('name');
        $this->as($code, null)->post('/rooms', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_actions_update_state_and_broadcast_only_a_version(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(5);
        Event::fake([RoomUpdated::class]);

        $this->as($code, $tokens['Knight 1'])->postJson("/r/$code/act/start")
            ->assertStatus(422)->assertJson(['message' => 'Only the host can do that.']);
        Event::assertNotDispatched(RoomUpdated::class);

        $res = $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/start")->assertOk();
        $this->assertSame('proposing', $res->json('state.phase'));
        $this->assertNotNull($res->json('state.me.role'), 'the start response already carries the dealt role');
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $e) => $e->code === $code
            && $e->version === $res->json('state.version')
            && $e->broadcastOn()->name === "room.$code");

        // The state endpoint only returns data when the version moved on.
        $version = $res->json('state.version');
        $this->as($code, $tokens['Knight 2'])->getJson("/r/$code/state?v=$version")->assertExactJson(['unchanged' => true]);
    }

    public function test_players_never_receive_each_others_roles(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(6);
        $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/start")->assertOk();

        foreach ($tokens as $token) {
            $json = $this->as($code, $token)->getJson("/r/$code/state")->assertOk()->json('state');
            foreach ($json['players'] as $p) {
                $this->assertArrayNotHasKey('role', $p);
            }
            $this->assertStringNotContainsString('"token"', json_encode($json), 'tokens never leave the server');
            $this->assertNotNull($json['me']['role']);
        }
    }

    public function test_leaving_the_lobby_clears_the_seat(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(2);

        $this->as($code, $tokens['Knight 1'])->postJson("/r/$code/act/leave")->assertExactJson(['left' => true]);
        $this->assertArrayNotHasKey('Knight 1', $this->tokens($code));
    }

    public function test_cached_state_matches_the_database(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(5);
        $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/start")->assertOk();

        $rooms = app(RoomService::class);
        $this->assertSame(Room::where('code', $code)->first()->state, $rooms->state($code));

        // A rule violation leaves both untouched.
        $this->as($code, $tokens['Knight 1'])->postJson("/r/$code/act/restart")->assertStatus(422);
        $this->assertSame('proposing', $rooms->state($code)['phase']);

        // With the cache cleared, reads fall back to the database.
        Cache::flush();
        $this->assertSame(Room::where('code', $code)->first()->state, $rooms->state($code));
    }

    public function test_choosing_a_role_is_refused_outside_local_mode(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(1);

        // The test environment is not "local", just like production.
        $this->assertFalse(app()->isLocal());
        $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/settings", ['hostRole' => 'merlin'])
            ->assertStatus(422)->assertJson(['message' => 'Choosing your role is only available in local test mode.']);

        $this->app['env'] = 'local';
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/settings", ['hostRole' => 'merlin'])
            ->assertOk()->assertJsonPath('state.settings.hostRole', 'merlin');
    }

    public function test_unknown_actions_are_not_routed(): void
    {
        [$code, $tokens] = $this->createRoomWithPlayers(1);

        $this->as($code, $tokens['Arthur'])->postJson("/r/$code/act/cheat")->assertNotFound();
    }
}
