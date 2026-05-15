<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use OwenIt\Auditing\Contracts\AttributeRedactor;

class FixedValueRedactor implements AttributeRedactor
{
    public static function redact(mixed $value): string
    {
        return '[redacted]';
    }
}
