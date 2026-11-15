<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Models\Reference;

/**
 * Delete a reference through the owning feature.
 */
final readonly class DeleteReferenceAction
{
    public function handle(Reference $reference): void
    {
        $reference->delete();
    }
}
