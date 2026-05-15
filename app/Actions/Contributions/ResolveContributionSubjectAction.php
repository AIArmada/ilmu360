<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionSubjectAction
{
    use AsAction;

    public function __construct(
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    public function handle(string $subjectType, string $subjectId): Event|Institution|Reference|Speaker
    {
        $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

        return match ($resolvedSubjectType) {
            ContributionSubjectType::Event => $this->resolveSlugOrUuid(Event::query(), 'events.slug', $subjectId),
            ContributionSubjectType::Institution => $this->resolveSlugOrUuid(Institution::query(), 'institutions.slug', $subjectId),
            ContributionSubjectType::Speaker => $this->resolveSlugOrUuid(Speaker::query(), 'speakers.slug', $subjectId),
            ContributionSubjectType::Reference => $this->resolveSlugOrUuid(Reference::query(), 'references.slug', $subjectId),
            default => abort(404),
        };
    }

    /**
     * @template TModel of Event|Institution|Reference|Speaker
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function resolveSlugOrUuid(Builder $query, string $slugColumn, string $subjectId): Event|Institution|Reference|Speaker
    {
        /** @var Event|Institution|Reference|Speaker $record */
        $record = $this->slugOrUuidResolver->firstOrFail($query, $slugColumn, $subjectId);

        return $record;
    }
}
