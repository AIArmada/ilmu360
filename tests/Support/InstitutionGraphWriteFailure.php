<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Process-local switch that makes the next institution-name write throw,
 * proving graph-write atomicity without touching the database state.
 */
final class InstitutionGraphWriteFailure
{
    public static bool $fail = false;
}
