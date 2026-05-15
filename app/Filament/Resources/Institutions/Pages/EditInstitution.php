<?php

namespace App\Filament\Resources\Institutions\Pages;

use App\Actions\Institutions\SaveInstitutionAction;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Institution;
use App\Models\User;
use App\Support\Submission\PublicSubmissionUiEvents;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class EditInstitution extends EditRecord
{
    protected static string $resource = InstitutionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = $this->currentUser();

        if (! $actor instanceof User || ! $record instanceof Institution) {
            abort(403);
        }

        return app(SaveInstitutionAction::class)->handle(
            $data,
            $actor,
            $record,
            'data.allow_public_event_submission',
        );
    }

    #[On(PublicSubmissionUiEvents::REFRESH_TOGGLE)]
    public function refreshPublicSubmissionToggleState(): void
    {
        $this->institutionRecord()->refresh();
        $this->refreshFormData(['allow_public_event_submission']);
    }

    private function institutionRecord(): Institution
    {
        $record = $this->getRecord();

        if (! $record instanceof Institution) {
            throw new \RuntimeException('Expected Filament record to be an Institution instance.');
        }

        return $record;
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
