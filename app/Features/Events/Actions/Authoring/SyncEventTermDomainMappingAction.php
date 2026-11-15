<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\EventTerm;

final readonly class SyncEventTermDomainMappingAction
{
    public function handle(EventTerm $record, mixed $state): void
    {
        $ids = collect(is_array($state) ? $state : [$state])
            ->filter()
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
        $metadata = $record->metadata ?? [];

        if ($ids === []) {
            unset($metadata['domain_ids']);
        } else {
            $metadata['domain_ids'] = $ids;
        }

        $record->metadata = $metadata === [] ? null : $metadata;
        $record->save();
    }
}
