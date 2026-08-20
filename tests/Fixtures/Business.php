<?php

declare(strict_types=1);

namespace Nugsoft\RetentionExtractor\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    protected $table = 'businesses';

    protected $guarded = [];
}
