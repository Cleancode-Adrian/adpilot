<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // prywatny panel: blokada botów (wyszukiwarki, AI) + noindex na każdej odpowiedzi
        $middleware->prepend(\App\Http\Middleware\BlockCrawlers::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'vault.unlocked' => \App\Http\Middleware\EnsureVaultUnlocked::class,
        ]);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            \App\Http\Middleware\RequireTwoFactor::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
