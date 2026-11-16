<?php

namespace App\Filament\Resources\Institutions\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Institution;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInstitution extends CreateRecord
{
    #[\Override]
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

        $relationPayload = [];

        if (array_key_exists('contactMethods', $data)) {
            $relationPayload['contactMethods'] = $data['contactMethods'];
            unset($data['contactMethods']);
        }

        if (array_key_exists('socialProfiles', $data)) {
            $relationPayload['social_media'] = $data['socialProfiles'];
            unset($data['socialProfiles']);
        }

        return OwnerContext::withOwner(null, function () use ($data, $relationPayload, $user): Model {
            $institutionId = app(InstitutionWorkflow::class)->saveFromAdmin(
                $data,
                (string) $user->getKey(),
            );

            app(InstitutionWorkflow::class)->syncRelations($institutionId, $relationPayload);

            return Institution::query()->findOrFail($institutionId);
        });
    }
}
