<?php

namespace App\Features\Contributions\Queries;

use App\Features\Contributions\Support\ContributionModel;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionSubjectAction
{
    use AsAction;

    public function __construct(
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    public function handle(string $subjectType, string $subjectId): Model
    {
        $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

        return match ($resolvedSubjectType) {
            ContributionSubjectType::Event => $this->resolveSlugOrUuid(ContributionModel::query('event'), 'events.slug', $subjectId),
            ContributionSubjectType::Institution => $this->resolveSlugOrUuid(ContributionModel::query('institution'), 'institutions.slug', $subjectId),
            ContributionSubjectType::Person => $this->resolveSlugOrUuid(ContributionModel::query('person'), 'persons.slug', $subjectId),
            ContributionSubjectType::Reference => $this->resolveSlugOrUuid(ContributionModel::query('reference'), 'references.slug', $subjectId),
            default => abort(404),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function resolveSlugOrUuid(Builder $query, string $slugColumn, string $subjectId): Model
    {
        /** @var Model $record */
        $record = $this->slugOrUuidResolver->firstOrFail($query, $slugColumn, $subjectId);

        return $record;
    }
}
