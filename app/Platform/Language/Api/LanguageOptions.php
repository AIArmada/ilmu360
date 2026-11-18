<?php

declare(strict_types=1);

namespace App\Platform\Language\Api;

interface LanguageOptions
{
    /** @return list<array{id: string, label: string}> */
    public function options(): array;
}
