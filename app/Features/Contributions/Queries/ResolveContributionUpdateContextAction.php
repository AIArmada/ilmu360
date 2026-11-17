<?php

declare(strict_types=1);

namespace App\Features\Contributions\Queries;

use App\Features\Contributions\Api\ContributionUpdateContextReader;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Services\ContributionEntityMutationService;
use App\Shared\Contributions\Support\ContributionEntityMetadata;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionUpdateContextAction implements ContributionUpdateContextReader
{
    use AsAction;

    public function __construct(
        protected ContributionEntityMutationService $contributionEntityMutationService,
        protected ResolveContributionSubjectAction $resolveContributionSubjectAction,
    ) {}

    /**
     * @return array{
     *     entity_type: string,
     *     entity_id: string,
     *     initial_state: array<string, mixed>,
     *     contract: array{
     *         accepts_partial_updates: bool,
     *         fields: list<array<string, mixed>>,
     *         conditional_rules: list<array<string, mixed>>,
     *         direct_edit_media_fields: list<string>
     *     }
     * }
     */
    public function resolve(string $subjectType, string $subjectId): array
    {
        $context = $this->handle($subjectType, $subjectId);

        return [
            'entity_type' => $context['entity_type'],
            'entity_id' => $context['entity_id'],
            'initial_state' => $context['initial_state'],
            'contract' => $context['contract'],
        ];
    }

    /**
     * @return array{
     *     entity: Event|Institution|Reference|Person,
     *     entity_type: string,
     *     entity_id: string,
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
        $entityMetadata = ContributionEntityMetadata::for($entity);

        return [
            'entity' => $entity,
            'entity_type' => $entityMetadata['entity_type'],
            'entity_id' => $entityMetadata['entity_id'],
            'initial_state' => $this->contributionEntityMutationService->stateForEntity($entity),
            'contract' => $this->contributionEntityMutationService->contractFor($entity),
        ];
    }
}
