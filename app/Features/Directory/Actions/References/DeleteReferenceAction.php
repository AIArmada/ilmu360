<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use Illuminate\Database\Eloquent\Model;

/**
 * Delete a reference through the owning feature.
 */
final readonly class DeleteReferenceAction
{
    public function handle(Model $reference): void
    {
        $reference->delete();
    }
}
