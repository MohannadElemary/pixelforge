<?php

use Laravel\Telescope\Http\Middleware\Authorize;

return [
    'enabled' => env('TELESCOPE_ENABLED', true),
    'middleware' => ['web', 'auth', Authorize::class],
    'ignore_paths' => ['up'],
];
