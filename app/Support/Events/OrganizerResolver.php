<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Institution;
use App\Models\Person;

final class OrganizerResolver
{
    public static function find(?string $id): Institution|Person|null
    {
        if ($id === null) {
            return null;
        }

        return Institution::query()->find($id)
            ?? Person::query()->find($id);
    }
}
