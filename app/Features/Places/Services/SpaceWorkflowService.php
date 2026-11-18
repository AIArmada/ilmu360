<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Places\Actions\Spaces\DeleteSpaceAction;
use App\Features\Places\Actions\Spaces\SaveSpaceAction;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Models\Space;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the space workflow through named Directory actions.
 */
final readonly class SpaceWorkflowService implements SpaceWorkflow
{
    public function __construct(
        private SaveSpaceAction $saveSpace,
        private DeleteSpaceAction $deleteSpace,
    ) {}

    public function saveFromAdmin(array $payload, ?string $spaceId = null): string
    {
        $space = $spaceId !== null ? $this->space($spaceId) : null;

        return (string) $this->saveSpace->handle($payload, $space)->getKey();
    }

    public function delete(array $spaceIds): int
    {
        $deleted = 0;

        foreach (Space::query()->whereKey($spaceIds)->get() as $space) {
            if (! $space instanceof Model) {
                continue;
            }

            $this->deleteSpace->handle($space);
            $deleted++;
        }

        return $deleted;
    }

    private function space(string $spaceId): Model
    {
        $space = Space::query()->find($spaceId);

        if (! $space instanceof Model) {
            throw new RuntimeException("Space [{$spaceId}] was not found.");
        }

        return $space;
    }
}
