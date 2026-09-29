<?php

namespace App\Game;

use Exception;

/**
 * A rule violation or bad request that should be shown to the player.
 * $kind lets the client react: 'no_room' and 'not_player' send the player
 * back to the home screen.
 */
class GameError extends Exception
{
    public function __construct(string $message, public readonly string $kind = 'invalid')
    {
        parent::__construct($message);
    }
}
