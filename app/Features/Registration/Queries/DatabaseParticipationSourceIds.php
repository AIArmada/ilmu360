<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\ParticipationSourceIds;
use App\Platform\Models\MorphModelResolver;
use InvalidArgumentException;

final readonly class DatabaseParticipationSourceIds implements ParticipationSourceIds
{
    public function registrationIds(string $scope, string $scopeId): array
    {
        $modelClass = MorphModelResolver::resolve('registration');

        return $this->ids($modelClass, $this->scopeColumn($scope), $scopeId);
    }

    public function checkinIds(string $scope, string $scopeId): array
    {
        $modelClass = MorphModelResolver::resolve('checkin');

        return $this->ids($modelClass, $this->scopeColumn($scope), $scopeId);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @return list<string>
     */
    private function ids(string $modelClass, string $column, string $scopeId): array
    {
        return $modelClass::query()
            ->where($column, $scopeId)
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function scopeColumn(string $scope): string
    {
        return match ($scope) {
            'event' => 'event_id',
            'occurrence' => 'event_occurrence_id',
            'session' => 'event_session_id',
            default => throw new InvalidArgumentException("Unsupported participation scope [{$scope}]."),
        };
    }
}
