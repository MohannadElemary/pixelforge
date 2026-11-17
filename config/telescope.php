<?php

use Laravel\Telescope\Http\Middleware\Authorize;

return [
    'enabled' => env('TELESCOPE_ENABLED', true),
    'middleware' => ['web', 'auth', Authorize::class],
    'ignore_paths' => ['up'],
    // This custom daemon has no per-job flush boundary for Telescope's in-memory entries.
    'ignore_commands' => ['pixelforge:work'],
];
