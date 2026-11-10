<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests\Fixtures;

use Nugsoft\RetentionExtractor\Licensing\LicenceDriver;
use Nugsoft\RetentionExtractor\Licensing\LicenceState;

/**
 * A product's own licence class, keeping its decisions in memory.
 *
 * Knows one client, `1`. Anyone else is not here, which is what a real
 * driver says by returning 0.
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

        self::$access[$licence->externalId] = $licence->grantsAccess;

        return 1;
    }

    public function grantsAccess(string $externalId): ?bool
    {
        return self::$access[$externalId] ?? null;
    }
}
