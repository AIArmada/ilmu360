<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A user may have permission to edit a record without permission to read its
 * full details. The admin API must never substitute any mutation ability for view.
 */
final class DeniedAdminPersonReadPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return true;
    }
}
