<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages;

use App\Features\Ai\Actions\UpdateAiModelPricingAction;
use App\Features\Ai\Actions\DeleteAiModelPricingAction;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Features\Ai\Models\AiModelPricing;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiModelPricing extends EditRecord
{
    #[\Override]
    protected static string $resource = AiModelPricingResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof AiModelPricing) {
            throw new \RuntimeException('Expected Filament record to be an AI model pricing instance.');
        }

        return app(UpdateAiModelPricingAction::class)->handle($record, $data);
    }

    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['provider'] = strtolower(trim((string) ($data['provider'] ?? '*')));
        $data['model_pattern'] = trim((string) ($data['model_pattern'] ?? '*'));
        $data['operation'] = trim((string) ($data['operation'] ?? '*'));
        $data['tier'] = filled($data['tier'] ?? null) ? strtolower(trim((string) $data['tier'])) : null;
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? 'USD')));

        return $data;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (AiModelPricing $record): bool {
                    app(DeleteAiModelPricingAction::class)->handle($record);

                    return true;
                }),
        ];
    }
}
