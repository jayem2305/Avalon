<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells everyone in a room that its state changed. Only the version number is
 * broadcast (on a public channel); each client then fetches its own filtered
 * view over HTTP, so secret roles never travel over the websocket.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public string $code, public int $version) {}

    public function broadcastOn(): Channel
    {
        return new Channel('room.'.$this->code);
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }
}
