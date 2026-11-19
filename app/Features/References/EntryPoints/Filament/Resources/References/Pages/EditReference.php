<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Filament\Resources\References\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\References\Api\ReferenceWorkflow;
use App\Features\References\EntryPoints\Filament\Forms\ReferenceFormSchema;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\Models\Reference;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReference extends EditRecord
{
    #[\Override]
    protected static string $resource = ReferenceResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    public function mount(int|string $record): void
    {
        OwnerContext::withOwner(null, function () use ($record): void {
            parent::mount($record);
        });
    }

    /**
     * @return array<int, DeleteAction>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (DeleteAction $action, Reference $record): void {
                    app(ReferenceWorkflow::class)->delete([(string) $record->getKey()]);
                    $action->success();
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return OwnerContext::withOwner(null, function () use ($data, $record): Model {
                ReferenceFormSchema::validateDirectInput($data);

                $hasSocialProfiles = array_key_exists('socialProfiles', $data);
                $socialProfiles = $data['socialProfiles'] ?? null;
                unset($data['socialProfiles']);

                app(ReferenceWorkflow::class)->saveFromAdmin($data, (string) $record->getKey());

                if ($hasSocialProfiles) {
                    app(ReferenceWorkflow::class)->syncRelations((string) $record->getKey(), [
                        'social_media' => $socialProfiles,
                    ]);
                }

                $record->refresh();

                return $record;
            });
        } catch (ValidationException $exception) {
            ReferenceFormSchema::throwSchemaErrors($exception, $this->form);
        }
    }
}
