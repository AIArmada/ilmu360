<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Institution;
use App\Models\Speaker;

final class OrganizerResolver
{
    public static function find(?string $id): Institution|Speaker|null
    {
        if ($id === null) {
            return null;
        }

        return Institution::query()->find($id)
            ?? Speaker::query()->find($id);
    }
}
