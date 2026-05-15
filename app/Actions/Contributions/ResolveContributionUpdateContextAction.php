<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Services\ContributionEntityMutationService;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionUpdateContextAction
{
    use AsAction;

    public function __construct(
        protected ContributionEntityMutationService $contributionEntityMutationService,
        protected ResolveContributionSubjectAction $resolveContributionSubjectAction,
    ) {}

    /**
     * @return array{
     *     entity: Event|Institution|Reference|Speaker,
     *     initial_state: array<string, mixed>,
     *     contract: array{
     *         accepts_partial_updates: bool,
     *         fields: list<array<string, mixed>>,
     *         conditional_rules: list<array<string, mixed>>,
     *         direct_edit_media_fields: list<string>
     *     }
     * }
     */
    public function handle(string $subjectType, string $subjectId): array
    {
        $entity = $this->resolveContributionSubjectAction->handle($subjectType, $subjectId);

        return [
            'entity' => $entity,
            'initial_state' => $this->contributionEntityMutationService->stateFor($entity),
            'contract' => $this->contributionEntityMutationService->contractFor($entity),
        ];
    }
}
