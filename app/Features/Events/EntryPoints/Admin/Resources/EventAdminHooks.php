<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Admin\Resources;

use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Http\Data\EventPayloadData;
use App\Features\Prayer\Api\PrayerReference;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use Illuminate\Database\Eloquent\Model;

final readonly class EventAdminHooks implements AdminResourceHooks
{
    public function supports(string $resourceClass): bool
    {
        return $resourceClass === EventResource::class;
    }

    public function filters(string $resourceClass): array
    {
        if (! $this->supports($resourceClass)) {
            return [];
        }

        return [
            [
                'key' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'options' => [
                    'draft' => 'Draft',
                    'pending' => 'Pending Review',
                    'needs_changes' => 'Needs Changes',
                    'approved' => 'Approved',
                    'cancelled' => 'Cancelled',
                    'rejected' => 'Rejected',
                ],
            ],
            [
                'key' => 'visibility',
                'label' => 'Visibility',
                'type' => 'select',
                'options' => collect(EventVisibility::cases())->mapWithKeys(
                    static fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()]
                )->all(),
            ],
            [
                'key' => 'event_format',
                'label' => 'Event Format',
                'type' => 'select',
                'options' => collect(EventFormat::cases())->mapWithKeys(
                    static fn (EventFormat $format): array => [$format->value => $format->getLabel()]
                )->all(),
            ],
            [
                'key' => 'event_category_ids',
                'label' => 'Event Categories',
                'type' => 'select',
                'options' => app(EventCategoryCatalog::class)->options(),
            ],
            [
                'key' => 'timing_mode',
                'label' => 'Timing Mode',
                'type' => 'select',
                'options' => collect(TimingMode::cases())->mapWithKeys(
                    static fn (TimingMode $timingMode): array => [$timingMode->value => $timingMode->label()]
                )->all(),
            ],
            [
                'key' => 'prayer_reference',
                'label' => 'Prayer Reference',
                'type' => 'select',
                'options' => collect(PrayerReference::cases())->mapWithKeys(
                    static fn (PrayerReference $reference): array => [$reference->value => $reference->label()]
                )->all(),
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'type' => 'boolean',
            ],
        ];
    }

    public function recordAttributes(Model $record): ?array
    {
        return $record instanceof \App\Features\Events\Models\Event
            ? EventPayloadData::fromModel($record)->toArray()
            : null;
    }

    public function recordTitle(Model $record): ?string
    {
        return $record instanceof \App\Features\Events\Models\Event
            ? (string) ($record->title ?? '')
            : null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        return $attributes;
    }

    public function dateSemantics(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'storage_timezone' => 'UTC',
            'viewer_timezone' => 'resolved at request time',
            'local_fields' => ['starts_at_local', 'starts_on_local_date', 'ends_at_local'],
            'local_date_filter' => 'starts_on_local_date',
        ];
    }
}
