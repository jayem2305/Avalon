<?php

use App\Game\GameError;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        // Hosts like Vercel terminate HTTPS in front of the app; trust their
        // forwarded headers so generated URLs (assets, redirects) use https.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Rule violations are expected: show them to the player, don't log them.
        $exceptions->dontReport(GameError::class);
        $exceptions->render(fn (GameError $e, Request $request) => response()->json([
            'message' => $e->getMessage(),
            'kind' => $e->kind,
        ], 422));
    })->create();
