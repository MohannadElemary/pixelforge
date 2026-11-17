<?php

namespace App\Exceptions;

use RuntimeException;

class ProviderRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('The provider request budget is temporarily exhausted.');
    }
}
