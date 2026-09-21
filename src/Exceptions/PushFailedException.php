<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Exceptions;

use RuntimeException;

class PushFailedException extends RuntimeException
{
    /**
     * Names the request that failed, rather than assuming it was a push.
     *
     * Every message used to begin "POST", because this class was written when
     * pushing was all it did. Three of the four callers are GETs — the metrics
     * contract, the mapping, and the licence pull — so a failure reading the
     * contract was reported as a POST to an endpoint that answers only GET,
     * and the obvious first guess was a method mismatch that did not exist.
     * A diagnostic that misdescribes the request costs more than no diagnostic.
     */
    public static function fromResponse(string $method, string $endpoint, int $status, string $body): self
    {
        $explanation = match ($status) {
            401 => 'The API key was not recognised. Check RETENTION_API_KEY — and remember a cached config keeps the old value until `php artisan config:clear`.',
            403 => 'The API key is inactive, or is scoped to a different product than RETENTION_PRODUCT_CODE.',
            405 => "NugsoftOS does not accept {$method} there. This is a bug in this package, not in your configuration.",
            422 => "NugsoftOS rejected the payload: {$body}",
            default => "NugsoftOS returned {$status}: {$body}",
        };

        return new self("{$method} {$endpoint} failed. {$explanation}");
    }
}
