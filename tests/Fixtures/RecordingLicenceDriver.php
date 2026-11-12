<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests\Fixtures;

use Nugsoft\RetentionExtractor\Licensing\LicenceDriver;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;

/**
 * A product's own licence class, keeping its decisions in memory.
 *
 * Knows one client, `1`, and its branches `10` and `11`. Anyone else is not
 * here, which is what a real driver says by returning 0. A branch's state is
 * kept under "client/branch".
 */
class RecordingLicenceDriver implements LicenceDriver
{
    /** @var array<string, bool> */
    public static array $access = [];

    public function apply(LicenceState $licence): int
    {
        if ($licence->externalId !== '1') {
            return 0;
        }

        if ($licence->isForBranch() && ! in_array($licence->branchExternalId, ['10', '11'], true)) {
            return 0;
        }

        self::$access[self::key($licence->externalId, $licence->branchExternalId)] = $licence->grantsAccess;

        return 1;
    }

    public function grantsAccess(string $externalId, ?string $branchExternalId = null): ?bool
    {
        return self::$access[self::key($externalId, $branchExternalId)] ?? null;
    }

    public static function key(string $externalId, ?string $branchExternalId = null): string
    {
        return $branchExternalId === null ? $externalId : "{$externalId}/{$branchExternalId}";
    }
}
