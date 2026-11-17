<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use stdClass;

class ProviderControlRepository
{
    public function lock(): stdClass
    {
        return DB::table('provider_controls')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    public function scheduleRequest(int $milliseconds): void
    {
        DB::table('provider_controls')->where('id', 1)->update(['next_request_at_ms' => $milliseconds]);
    }
}
