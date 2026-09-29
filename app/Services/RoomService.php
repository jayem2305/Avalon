<?php

namespace App\Services;

use App\Events\RoomUpdated;
use App\Game\Avalon;
use App\Game\GameError;
use App\Models\Room;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The database is the source of truth. Every change locks the room's row.
 * A copy of each room's latest state is also kept in the local cache so that
 * players' frequent state reloads don't each make a round trip to a remote
 * database. That copy is only written while the row lock is held, so it
 * always matches the database. This assumes a single app server, which is
 * true for this setup.
 */
class RoomService
{
    /** Rooms untouched for this long are deleted when a new room is created. */
    private const ROOM_TTL_HOURS = 24;

    /** @return array{0: Room, 1: array} [room, host player] */
    public function create(mixed $hostName): array
    {
        [$state, $host] = Avalon::newRoom($hostName);

        Room::where('updated_at', '<', now()->subHours(self::ROOM_TTL_HOURS))->delete();

        for ($try = 0; $try < 25; $try++) {
            $state['code'] = Avalon::randomCode();
            try {
                $room = Room::create(['code' => $state['code'], 'state' => $state]);
                $this->remember($state);

                return [$room, $host];
            } catch (UniqueConstraintViolationException) {
                // Code already in use: try another.
            }
        }
        throw new GameError('Could not allocate a room code. Please try again.');
    }

    /** The room's current state, from the local cache when possible. */
    public function state(string $code): array
    {
        $state = Cache::get($this->key($code));
        if ($state === null) {
            $state = Room::where('code', $code)->value('state')
                ?? throw new GameError('Room not found. Check the code and try again.', 'no_room');
            $state = is_array($state) ? $state : json_decode($state, true);
            $this->remember($state);
        }

        return $state;
    }

    /**
     * Runs $fn on the room's state with the row locked, saves it if anything
     * changed (bumping the version) and notifies the room's players.
     *
     * @param  callable(array &$state): mixed  $fn
     * @return array{0: mixed, 1: array} [$fn's result, final state]
     */
    public function mutate(string $code, callable $fn): array
    {
        try {
            [$result, $state, $changed] = DB::transaction(function () use ($code, $fn) {
                $room = Room::where('code', $code)->lockForUpdate()->first()
                    ?? throw new GameError('Room not found. Check the code and try again.', 'no_room');

                $state = $room->state;
                $before = json_encode($state);
                $result = $fn($state);
                $changed = json_encode($state) !== $before;

                if ($changed) {
                    $state['version']++;
                    $room->state = $state;
                    $room->save();
                    $this->remember($state); // still under the row lock, so writes stay in order
                }

                return [$result, $state, $changed];
            });
        } catch (Throwable $e) {
            // A failed save or commit may have left an uncommitted state in the cache.
            // Rule violations happen before anything is cached, so they're safe.
            if (! $e instanceof GameError) {
                Cache::forget($this->key($code));
            }
            throw $e;
        }

        if ($changed) {
            $this->announce($state);
        }

        return [$result, $state];
    }

    private function key(string $code): string
    {
        return "avalon.room.$code";
    }

    private function remember(array $state): void
    {
        Cache::put($this->key($state['code']), $state, now()->addHours(self::ROOM_TTL_HOURS));
    }

    /**
     * Broadcasting is best-effort: if Reverb is down the action still counts,
     * and clients catch up through their fallback polling. After a failure we
     * stop trying for a while, so actions don't each wait for a timeout.
     */
    private function announce(array $state): void
    {
        if (Cache::has('avalon.reverb-down')) {
            return;
        }
        try {
            broadcast(new RoomUpdated($state['code'], $state['version']));
        } catch (Throwable $e) {
            Cache::put('avalon.reverb-down', true, now()->addSeconds(30));
            logger()->warning('Reverb unreachable, pausing broadcasts for 30s. Start it with `php artisan reverb:start` (or use `composer run dev`).', ['error' => $e->getMessage()]);
        }
    }
}
