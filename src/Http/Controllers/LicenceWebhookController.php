<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Nugsoft\RetentionExtractor\Licensing\LicenceApplier;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;
use Throwable;

/**
 * Receives a licence change from Retention Intel and applies it here.
 *
 * Answers 200 to anything it has understood, including a delivery it decided
 * not to apply. A non-200 tells the sender to retry, and retrying a message
 * that was correctly ignored as stale would achieve nothing but noise.
 */
class LicenceWebhookController extends Controller
{
    public function __invoke(Request $request, LicenceApplier $applier): JsonResponse
    {
        $licence = LicenceState::fromPayload($request->all());

        if ($licence->externalId === '') {
            return new JsonResponse(['message' => 'No client named.'], 422);
        }

        if (! $applier->isConfigured()) {
            // 503 rather than 200: this one *is* worth retrying, because the
            // product team wiring the config up is what fixes it.
            return new JsonResponse(
                ['message' => 'Licence sync is not configured on this product.'],
                503,
            );
        }

        if ($applier->isStale($licence)) {
            return new JsonResponse([
                'message' => 'Ignored: a newer licence has already been applied.',
                'applied' => false,
            ]);
        }

        try {
            $changed = $applier->apply($licence);
        } catch (Throwable $exception) {
            Log::error('Failed to apply a licence change from Retention Intel.', [
                'external_id' => $licence->externalId,
                'exception' => $exception->getMessage(),
            ]);

            return new JsonResponse(['message' => 'Could not apply the licence.'], 500);
        }

        return new JsonResponse([
            'message' => $changed > 0 ? 'Licence applied.' : 'No matching client here.',
            'applied' => true,
            'rows_changed' => $changed,
            'licence_version' => $licence->licenceVersion,
        ]);
    }
}
