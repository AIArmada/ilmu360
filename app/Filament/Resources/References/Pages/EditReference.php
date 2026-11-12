<?php

declare(strict_types=1);

namespace App\Filament\Resources\References\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Filament\Resources\References\ReferenceResource;
use App\Forms\ReferenceFormSchema;
use App\Models\Reference;
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

                app(ReferenceWorkflow::class)->saveFromAdmin($data, (string) $record->getKey());
                $record->refresh();

                return $record;
            });
        } catch (ValidationException $exception) {
            ReferenceFormSchema::throwSchemaErrors($exception, $this->form);
        }
    }
}
