<?php

use App\Game\Avalon;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RoomController::class, 'home'])->name('home');
Route::post('/rooms', [RoomController::class, 'create'])->name('rooms.create');
Route::post('/join', [RoomController::class, 'join'])->name('rooms.join');

Route::prefix('r/{code}')->where(['code' => '[A-Za-z]{4}'])->group(function () {
    Route::get('/', [RoomController::class, 'show'])->name('room.show');
    Route::get('/state', [RoomController::class, 'state'])->name('room.state');
    Route::post('/act/{action}', [RoomController::class, 'act'])
        ->whereIn('action', Avalon::ACTIONS)
        ->name('room.act');
});
