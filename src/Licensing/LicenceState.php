<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Licensing;

use Illuminate\Support\Carbon;

/**
 * What NugsoftOS says about one client's licence, or one branch's.
 *
 * `branchExternalId` is set where the product licenses each branch on its own
 * — PosCream bills a branch, not a business — and names the branch exactly as
 * this product pushed it. Null means the licence covers the whole client.
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
        public ?string $branchExternalId = null,
    ) {}

    public function isForBranch(): bool
    {
        return $this->branchExternalId !== null;
    }

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
            branchExternalId: filled($payload['branch_external_id'] ?? null)
                ? (string) $payload['branch_external_id']
                : null,
        );
    }

    private static function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
    }
}
