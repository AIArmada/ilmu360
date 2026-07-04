<?php

namespace App\Filament\Resources\Institutions\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Institutions\SaveInstitutionAction;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInstitution extends CreateRecord
{
    protected static string $resource = InstitutionResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(): void
    {
        OwnerContext::withOwner(null, function (): void {
            parent::mount();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return OwnerContext::withOwner(null, fn (): Model => app(SaveInstitutionAction::class)->handle(
            $data,
            $user,
            validationErrorKey: 'data.allow_public_event_submission',
        ));
    }
}
