<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Licensing;

use Illuminate\Support\Carbon;

/**
 * What NugsoftOS says about one client's licence.
 *
 * Deliberately small. `grantsAccess` is the field this product acts on; the
 * dates are here so a product can tell its own users when their licence ends
 * without asking again, and the version so a delivery that arrives after a
 * newer one can be dropped rather than applied.
 */
final readonly class LicenceState
{
    public function __construct(
        public string $externalId,
        public bool $grantsAccess,
        public string $status,
        public ?Carbon $endDate,
        public ?Carbon $effectiveEndDate,
        public int $licenceVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            externalId: (string) ($payload['external_id'] ?? ''),
            grantsAccess: (bool) ($payload['grants_access'] ?? false),
            status: (string) ($payload['status'] ?? 'unknown'),
            endDate: self::date($payload['end_date'] ?? null),
            effectiveEndDate: self::date($payload['effective_end_date'] ?? null),
            licenceVersion: (int) ($payload['licence_version'] ?? 0),
        );
    }

    private static function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
    }
}
