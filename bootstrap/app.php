<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);

        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
