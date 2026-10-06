<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;

class NativeDateModel extends Model
{
    public function parseDate(mixed $value): mixed
    {
        return $this->asDateTime($value);
    }
}
