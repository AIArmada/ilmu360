<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Spaces\ChangeSpaceStatusAction;
use App\Features\Directory\Actions\Spaces\ChangeSpaceVisibilityAction;
use App\Features\Directory\Actions\Spaces\DeleteSpaceAction;
use App\Features\Directory\Actions\Spaces\SaveSpaceAction;
use App\Features\Directory\Api\SpaceWorkflow;
use App\Features\Directory\Support\DirectoryModel;
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
        private ChangeSpaceStatusAction $changeSpaceStatus,
        private ChangeSpaceVisibilityAction $changeSpaceVisibility,
    ) {}

    public function saveFromAdmin(array $payload, ?string $spaceId = null): string
    {
        $space = $spaceId !== null ? $this->space($spaceId) : null;

        return (string) $this->saveSpace->handle($payload, $space)->getKey();
    }

    public function delete(array $spaceIds): int
    {
        $deleted = 0;

        foreach (DirectoryModel::query('space')->whereKey($spaceIds)->get() as $space) {
            if (! $space instanceof Model) {
                continue;
            }

            $this->deleteSpace->handle($space);
            $deleted++;
        }

        return $deleted;
    }

    public function changeStatus(string $spaceId, string $status): void
    {
        $this->changeSpaceStatus->handle($spaceId, $status);
    }

    public function changeVisibility(string $spaceId, string $visibility): void
    {
        $this->changeSpaceVisibility->handle($spaceId, $visibility);
    }

    private function space(string $spaceId): Model
    {
        $space = DirectoryModel::query('space')->find($spaceId);

        if (! $space instanceof Model) {
            throw new RuntimeException("Space [{$spaceId}] was not found.");
        }

        return $space;
    }
}
