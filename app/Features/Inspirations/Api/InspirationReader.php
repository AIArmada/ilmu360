<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Api;

interface InspirationReader
{
    public function random(?string $locale = null): ?InspirationCard;
}
