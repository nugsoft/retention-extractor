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
 *
 * A successful reply carries `grants_access`: what this product believes about
 * the client once the write has landed, read back from its own tables. The
 * sender compares that against what it decided, and the comparison is worth
 * far more here than it is a day later — this is the one moment where both
 * sides are known to be talking about the same version of the licence.
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
                // Refused, but still answered. This is the one exchange where
                // the two sides are known to disagree — the sender thinks this
                // licence is current and here it is old — so saying nothing
                // would withhold the reading at the moment it is worth most.
                'grants_access' => $applier->grantsAccess($licence->externalId),
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
            // Read back out of this product's own tables, after the write.
            // Not an echo of what we were sent: it is what the next scheduled
            // push would report, answered now, so the sender does not have to
            // wait a day to find out whether the change took. Null where this
            // product cannot be read back, which is every product without a
            // licence mapping configured.
            'grants_access' => $applier->grantsAccess($licence->externalId),
        ]);
    }
}
