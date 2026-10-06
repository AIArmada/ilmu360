<?php

declare(strict_types=1);

namespace Tests\Support;

use AIArmada\CommerceSupport\Concerns\ParsesPostgresTimestamps;
use Illuminate\Database\Eloquent\Model;

class OptimizedDateModel extends Model
{
    use ParsesPostgresTimestamps;

    public function parseDate(mixed $value): mixed
    {
        return $this->asDateTime($value);
    }
}
