<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', WebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class);
