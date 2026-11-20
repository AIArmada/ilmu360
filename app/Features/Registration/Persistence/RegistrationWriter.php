<?php

declare(strict_types=1);

namespace App\Features\Registration\Persistence;

use App\Features\Registration\Models\Registration;
use Illuminate\Support\Facades\DB;

/**
 * Owns the transaction boundary around a single registration model delete.
 *
 * Eloquent still runs the model deleting hooks (descendant cleanup and
 * participant removal) exactly as before; this writer keeps the row delete and
 * those hook writes atomic for the app-owned deletion entrypoints.
 */
final readonly class RegistrationWriter
{
    public function delete(Registration $registration): ?bool
    {
        return DB::transaction(static fn (): ?bool => $registration->delete());
    }
}
