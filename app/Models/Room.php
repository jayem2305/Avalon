<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property array $state
 */
class Room extends Model
{
    protected $fillable = ['code', 'state'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
