<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves a licence webhook came from Retention Intel.
 *
 * This endpoint switches paying clients off, so an unsigned one would let
 * anybody who found the URL do the same. Verified over the raw body rather
 * than the decoded array: re-encoding to check is how a signature comes to
 * disagree about whitespace and every delivery starts failing at once.
 */
class VerifyLicenceSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('retention-extractor.licence.secret');

        if (blank($secret)) {
            return new JsonResponse(
                ['message' => 'Licence sync is not configured on this product.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $provided = (string) $request->header('X-Nugsoft-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), (string) $secret);

        // Constant-time: a plain comparison leaks how much of the signature was
        // right, one byte at a time.
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['message' => 'Bad signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
