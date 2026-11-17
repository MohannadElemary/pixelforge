<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/local'));

        Telescope::hideRequestParameters(['_token', 'api_key', 'webhook_secret']);
        Telescope::hideRequestHeaders([
            'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'pixelforge-signature',
        ]);
    }

    protected function authorization(): void
    {
        Telescope::auth(fn (Request $request): bool => $request->user()?->is_admin === true);
    }
}
