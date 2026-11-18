<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Filament\Resources\References\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\EntryPoints\Filament\Forms\ReferenceFormSchema;
use App\Features\References\Models\Reference;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateReference extends CreateRecord
{
    #[\Override]
    protected static string $resource = ReferenceResource::class;

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

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return OwnerContext::withOwner(null, function () use ($data): Model {
                ReferenceFormSchema::validateDirectInput($data);

                $hasSocialProfiles = array_key_exists('socialProfiles', $data);
                $socialProfiles = $data['socialProfiles'] ?? null;
                unset($data['socialProfiles']);

                $referenceId = app(ReferenceWorkflow::class)->saveFromAdmin($data);

                if ($hasSocialProfiles) {
                    app(ReferenceWorkflow::class)->syncRelations($referenceId, [
                        'social_media' => $socialProfiles,
                    ]);
                }

                return Reference::query()->withoutOwnerScope()->findOrFail($referenceId);
            });
        } catch (ValidationException $exception) {
            ReferenceFormSchema::throwSchemaErrors($exception, $this->form);
        }
    }
}
