<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Admin\Resources;

use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): bool
    {
        if (! $this->supports($resourceClass) || $filters === []) {
            return false;
        }

        $model = $query->getModel();

        if (array_key_exists('status', $filters)) {
            $rawStatus = $filters['status'];

            if ($rawStatus !== null && $rawStatus !== '') {
                $status = $this->normalizeStatusFilter($rawStatus);

                if ($status === null || ! in_array($status, ['pending', 'verified', 'rejected', 'inactive'], true)) {
                    $query->whereRaw('1 = 0');

                    return true;
                }

                $query->where($model->qualifyColumn('status'), $status);
            }
        }

        if (array_key_exists('has_events', $filters)) {
            $rawHasEvents = $filters['has_events'];

            if ($rawHasEvents !== null && $rawHasEvents !== '') {
                $hasEvents = $this->normalizeBooleanFilter($rawHasEvents);

                if ($hasEvents === null) {
                    $query->whereRaw('1 = 0');

                    return true;
                }

                if ($hasEvents) {
                    $query->whereHas('events');

                    return true;
                }

                $query->whereDoesntHave('events');
            }
        }

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function imageUploadMcpTools(string $resourceClass, string $key): array
    {
        return [];
    }

    public function imageUploadMcpToolKeys(): array
    {
        return [];
    }

    private function normalizeStatusFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($normalized) ? $normalized : null;
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
        $attributes['address']['area_assignments'] = \AIArmada\Addressing\Support\AddressAssignments::forAddress(
            $record->primaryAddress()
        );

        return $attributes;
    }
}
