<?php

declare(strict_types=1);

namespace App\Features\Persons\Observers;

use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Features\Persons\Models\Person;
use App\Features\Persons\Services\PersonSearchService;
use App\Shared\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class PersonTitleObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private PersonSearchService $personSearchService,
        private SelectionCatalogCache $selectionCatalogCache,
    ) {}

    public function saved(Title|TitleAssignment $model): void
    {
        if ($model instanceof Title) {
            $this->selectionCatalogCache->bustTitles();
            $this->syncTitle($model);

            return;
        }

        $this->syncPerson($model->titleable);
    }

    public function deleted(Title|TitleAssignment $model): void
    {
        if ($model instanceof Title) {
            $this->selectionCatalogCache->bustTitles();
            $this->syncTitle($model);

            return;
        }

        $this->syncPerson($model->titleable);
    }

    private function syncTitle(Title $title): void
    {
        $title->loadMissing('assignments.titleable');

        foreach ($title->assignments as $assignment) {
            $this->syncPerson($assignment->titleable);
        }
    }

    private function syncPerson(mixed $titleable): void
    {
        if ($titleable instanceof Person) {
            $this->personSearchService->syncPersonRecord($titleable->fresh());
        }
    }
}
