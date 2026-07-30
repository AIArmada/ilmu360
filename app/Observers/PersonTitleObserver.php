<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Models\Person;
use App\Support\Search\PersonSearchService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class PersonTitleObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private PersonSearchService $personSearchService,
    ) {}

    public function saved(Title|TitleAssignment $model): void
    {
        if ($model instanceof Title) {
            $this->syncTitle($model);

            return;
        }

        $this->syncPerson($model->titleable);
    }

    public function deleted(Title|TitleAssignment $model): void
    {
        if ($model instanceof Title) {
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
