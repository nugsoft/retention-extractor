<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant that soft-deletes when it closes, as School Monitor's branches do.
 */
class ClosableBusiness extends Business
{
    use SoftDeletes;

    protected $table = 'closable_businesses';
}
