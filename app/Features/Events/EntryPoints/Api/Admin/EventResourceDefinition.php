<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Api\Admin;

use App\Features\Contributions\Api\ContributionEntityState;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\Models\Event;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\HookedAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Illuminate\Database\Eloquent\Model;

final class EventResourceDefinition extends FilamentResourceDefinition
{
    public function __construct(
        ResourceDefinitionContext $context,
        AdminMutationHookRegistry $hooks,
        ContributionEntityState $contributionState,
    ) {
        parent::__construct(
            $context,
            EventResource::class,
            new HookedAdminResourceWriter(
                $context,
                EventResource::class,
                $hooks,
                static fn (Model $record): array => $record->getMorphClass() === 'event'
                    ? $contributionState->stateFor('event', (string) $record->getKey())
                    : [],
            ),
        );
    }

    /** @return array<string, mixed> */
    protected function recordMediaData(string $resourceKey, string $recordKey, Model $record): array
    {
        $data = parent::recordMediaData($resourceKey, $recordKey, $record);

        if ($record instanceof Event) {
            $data['card_image_url'] = $record->getAttribute('card_image_url');
            $data['poster_url'] = $record->getFirstMediaUrl('poster', 'poster_thumb') ?: $record->getFirstMediaUrl('poster');
            $data['cover_url'] = $record->getFirstMediaUrl('cover', 'thumb') ?: $record->getFirstMediaUrl('cover');
        }

        return $data;
    }
}
