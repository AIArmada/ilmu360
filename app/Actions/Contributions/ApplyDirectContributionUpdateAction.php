<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Models\Event;
use App\Services\ContributionEntityMutationService;
use App\Services\ModerationService;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class ApplyDirectContributionUpdateAction
{
    use AsAction;

    public function __construct(
        private readonly ContributionEntityMutationService $contributionEntityMutationService,
        private readonly ModerationService $moderationService,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(Model $entity, array $changes): Model
    {
        $dirtyBeforeSave = $this->contributionEntityMutationService->apply($entity, $changes);

        if ($entity instanceof Event && $dirtyBeforeSave !== []) {
            $this->moderationService->handleSensitiveChange($entity, $dirtyBeforeSave);
        }

        return $entity;
    }
}
