<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Admin\Resources;

use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final readonly class PersonAdminHooks implements AdminResourceHooks
{
    public function supports(string $resourceClass): bool
    {
        return $resourceClass === PersonResource::class;
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
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                    'inactive' => 'Inactive',
                ],
            ],
            [
                'key' => 'has_events',
                'label' => 'Has Events',
                'type' => 'boolean',
            ],
        ];
    }

    public function dateSemantics(string $resourceClass): ?array
    {
        return null;
    }

    public function recordAttributes(Model $record): ?array
    {
        return null;
    }

    public function recordTitle(Model $record): ?string
    {
        return null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        if (! $record instanceof \App\Features\Persons\Models\Person || ! is_array($attributes['address'] ?? null)) {
            return $attributes;
        }

        $address = $attributes['address'];
        $attributes['address'] = Arr::only($address, ['country_id', 'state_id', 'city_id']);
        $attributes['address']['area_assignments'] = \App\Platform\Location\AddressAssignments::forAddress(
            $record->primaryAddress()
        );

        return $attributes;
    }
}
