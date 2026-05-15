<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\ReferenceSearchService;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ResourceSearchDispatcher
{
    public function __construct(
        private readonly InstitutionSearchService $institutionSearchService,
        private readonly ReferenceSearchService $referenceSearchService,
        private readonly SpeakerSearchService $speakerSearchService,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, string $search): bool
    {
        $model = $query->getModel();

        if ($model instanceof Speaker) {
            /** @var Builder<Speaker> $speakerQuery */
            $speakerQuery = $query;

            return $this->applyMatchingIds($query, $this->speakerSearchService->scopedSearchIds($speakerQuery, $search));
        }

        if ($model instanceof Institution) {
            /** @var Builder<Institution> $institutionQuery */
            $institutionQuery = $query;

            return $this->applyMatchingIds($query, $this->institutionSearchService->scopedSearchIds($institutionQuery, $search));
        }

        if ($model instanceof Reference) {
            /** @var Builder<Reference> $referenceQuery */
            $referenceQuery = $query;

            return $this->applyMatchingIds($query, $this->referenceSearchService->scopedSearchIds($referenceQuery, $search));
        }

        return false;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $matchingIds
     */
    private function applyMatchingIds(Builder $query, array $matchingIds): bool
    {
        if ($matchingIds === []) {
            $query->whereRaw('1 = 0');

            return true;
        }

        $model = $query->getModel();

        $query->whereIn($model->qualifyColumn($model->getKeyName()), $matchingIds);

        return true;
    }
}
