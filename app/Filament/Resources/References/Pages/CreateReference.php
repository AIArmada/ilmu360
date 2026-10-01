<?php

declare(strict_types=1);

namespace App\Filament\Resources\References\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\References\ReferenceResource;
use App\Forms\ReferenceFormSchema;
use App\Models\Reference;
use App\Services\ContributionEntityMutationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateReference extends CreateRecord
{
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
            ReferenceFormSchema::validateDirectInput($data);

            $hasAuthors = array_key_exists('author_ids', $data);
            $authorIds = $data['author_ids'] ?? null;
            unset($data['author_ids']);

            $record = parent::handleRecordCreation($data);

            if ($hasAuthors && $record instanceof Reference) {
                app(ContributionEntityMutationService::class)->syncReferenceRelations($record, ['author_ids' => $authorIds]);
            }

            return $record;
        } catch (ValidationException $exception) {
            ReferenceFormSchema::throwSchemaErrors($exception, $this->form);
        }
    }
}
