<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Events;

use Nugsoft\RetentionExtractor\Licensing\LicenceState;

/**
 * A licence from NugsoftOS has just been written into this product's tables.
 *
 * The write goes through the query builder, not the product's models, so
 * nothing the product hangs off its own model events runs for it — above all
 * the caches it clears when a row changes. School Monitor caches each
 * branch's access for an hour and forgets it only on a model save, so a
 * suspension written here went unenforced, and a grant unfelt, for up to that
 * hour. Listen for this and forget the same keys.
 *
 * Fired for a delivery and for the scheduled pull alike, every time something
 * was written, whether or not the value changed: the pull re-applies every
 * licence, and a cache filled a moment before the write is stale either way.
 */
final readonly class LicenceApplied
{
    public function __construct(
        public LicenceState $licence,
        public int $rowsWritten,
    ) {}
}
