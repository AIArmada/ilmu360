<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Models\EventReferencePivot;

/** Canonical event-reference pivot fields for newly attached references. */
final readonly class InitializeEventReferencePivotAction
{
    public function handle(EventReferencePivot $pivot): void
    {
        foreach ([
            'referenceable_type' => 'reference',
            'reference_type' => 'book',
            'visibility' => 'public',
            'sort_order' => 0,
        ] as $field => $default) {
            if ($pivot->getAttribute($field) === null) {
                $pivot->setAttribute($field, $default);
            }
        }
    }
}
