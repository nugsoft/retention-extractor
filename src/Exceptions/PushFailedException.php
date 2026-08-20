<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Exceptions;

use RuntimeException;

class PushFailedException extends RuntimeException
{
    public static function fromResponse(string $endpoint, int $status, string $body): self
    {
        $explanation = match ($status) {
            401 => 'The API key was not recognised. Check RETENTION_API_KEY.',
            403 => 'The API key is inactive, or is scoped to a different product than RETENTION_PRODUCT_CODE.',
            422 => "Retention Intel rejected the payload: {$body}",
            default => "Retention Intel returned {$status}: {$body}",
        };

        return new self("POST {$endpoint} failed. {$explanation}");
    }
}
