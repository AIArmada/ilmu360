<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Models\TitleAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Synchronize a person's title assignments.
 *
 * Title vocabulary/assignment tables stay package-owned; this action keeps the
 * person-side orchestration inside Directory so person relation writes have a
 * single named path.
 */
final readonly class SyncPersonTitleAssignmentsAction
{
    /**
     * @param  mixed  $assignmentsPayload  List of title assignment rows.
     */
    public function handle(Model $person, mixed $assignmentsPayload): void
    {
        $existing = $this->assignmentsQuery($person)
            ->get()
            ->keyBy(static fn (Model $assignment): string => (string) $assignment->getKey());
        $retainedIds = [];

        foreach (is_array($assignmentsPayload) ? $assignmentsPayload : [] as $assignmentPayload) {
            if (! is_array($assignmentPayload)) {
                continue;
            }

            $titleId = is_string($assignmentPayload['title_id'] ?? null) ? $assignmentPayload['title_id'] : null;

            if ($titleId === null || $titleId === '') {
                continue;
            }

            $status = $assignmentPayload['status'] ?? AssignmentStatus::Active->value;
            $status = $status instanceof AssignmentStatus ? $status->value : (string) $status;
            $attributes = [
                'title_id' => $titleId,
                'date_awarded' => $assignmentPayload['date_awarded'] ?? null,
                'date_expired' => $assignmentPayload['date_expired'] ?? null,
                'status' => $status,
            ];
            $assignmentId = is_string($assignmentPayload['id'] ?? null) ? $assignmentPayload['id'] : null;
            $assignment = $assignmentId !== null ? $existing->get($assignmentId) : null;

            if ($assignment instanceof Model) {
                $assignment->update($attributes);
                $retainedIds[] = (string) $assignment->getKey();

                continue;
            }

            $created = $this->assignmentsQuery($person)->create([
                'titleable_type' => $person->getMorphClass(),
                'titleable_id' => $person->getKey(),
                ...$attributes,
            ]);
            $retainedIds[] = (string) $created->getKey();
        }

        $existing
            ->reject(static fn (Model $assignment): bool => in_array((string) $assignment->getKey(), $retainedIds, true))
            ->each(static fn (Model $assignment): ?bool => $assignment->delete());
    }

    public function handleIds(Model $person, mixed $titleIds): void
    {
        $existingByTitleId = $this->assignmentsQuery($person)
            ->get()
            ->keyBy(static fn (Model $assignment): string => (string) $assignment->getAttribute('title_id'));
        $assignments = is_array($titleIds)
            ? array_map(static function (mixed $titleId) use ($existingByTitleId): array {
                $titleId = (string) $titleId;
                $assignment = $existingByTitleId->get($titleId);

                return [
                    'id' => $assignment instanceof Model ? (string) $assignment->getKey() : null,
                    'title_id' => $titleId,
                ];
            }, $titleIds)
            : [];

        $this->handle($person, $assignments);
    }

    /**
     * @return Builder<TitleAssignment>
     */
    private function assignmentsQuery(Model $person): Builder
    {
        return TitleAssignment::query()
            ->where('titleable_type', $person->getMorphClass())
            ->where('titleable_id', $person->getKey());
    }
}
