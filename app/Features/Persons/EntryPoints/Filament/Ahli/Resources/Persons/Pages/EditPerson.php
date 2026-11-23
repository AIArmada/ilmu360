<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\Pages;

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\EntryPoints\Filament\Ahli\Resources\Persons\PersonResource;
use App\Features\Persons\Support\PersonBioContent;
use App\Shared\Contributions\Support\RedirectsDirectoryEditsForReview;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPerson extends EditRecord
{
    use RedirectsDirectoryEditsForReview;

    #[\Override]
    protected static string $resource = PersonResource::class;

    #[\Override]
    public function mount(int|string $record): void
    {
        if (! $this->redirectDirectoryEditForReview($record)) {
            parent::mount($record);
        }
    }

    #[\Override]
    protected function authorizeAccess(): void
    {
        $this->requireDirectoryPublishingPermission();

        parent::authorizeAccess();
    }

    protected function contributionSubjectRouteSegment(): string
    {
        return ContributionSubjectType::Person->publicRouteSegment();
    }

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['bio'] = PersonBioContent::toRichContent($data['bio'] ?? null);

        return $data;
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actorId = auth()->id();

        app(PersonWorkflow::class)->saveFromAdmin(
            Arr::only($data, [
                'name',
                'middle_name',
                'family_name',
                'gender',
                'bio',
                'status',
                'speaker_status',
                'is_featured',
                'allow_public_event_submission',
            ]),
            (string) $actorId,
            (string) $record->getKey(),
        );

        $record->refresh();

        return $record;
    }
}
