<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nugsoft\RetentionExtractor\Http\Controllers\LicenceWebhookController;
use Nugsoft\RetentionExtractor\Http\Middleware\VerifyLicenceSignature;

/*
 * Where Retention Intel tells this product that a client has been switched on
 * or off. The path is configurable because products mount their APIs
 * differently; the signature check is not optional anywhere.
 */
Route::post(
    (string) config('retention-extractor.licence.route', 'api/retention/licence'),
    LicenceWebhookController::class,
)
    ->middleware(VerifyLicenceSignature::class)
    ->name('retention.licence.receive');
