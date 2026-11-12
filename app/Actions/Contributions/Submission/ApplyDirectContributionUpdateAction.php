<?php

declare(strict_types=1);

namespace App\Actions\Contributions\Submission;

use App\Features\Events\Api\EventAuthoring;
use App\Models\Event;
use App\Services\ContributionEntityMutationService;
use App\Services\ModerationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class ApplyDirectContributionUpdateAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionEntityMutationService $contributionEntityMutationService,
        private readonly ModerationService $moderationService,
        private readonly EventAuthoring $eventAuthoring,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(Model $entity, array $changes): Model
    {
        return DB::transaction(function () use ($entity, $changes): Model {
            $dirtyBeforeSave = $entity instanceof Event
                ? $this->eventAuthoring->applyContributionUpdate((string) $entity->getKey(), $changes)
                : $this->contributionEntityMutationService->apply($entity, $changes);

            if ($entity instanceof Event) {
                // The feature action resolves its own model; refresh the
                // caller's instance so response presentation matches the save.
                $entity->refresh();

                if ($dirtyBeforeSave !== []) {
                    $this->moderationService->handleSensitiveChange($entity, $dirtyBeforeSave);
                }
            }

            return $entity;
        });
    }
}
