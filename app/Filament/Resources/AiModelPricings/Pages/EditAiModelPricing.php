<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiModelPricings\Pages;

use App\Filament\Resources\AiModelPricings\AiModelPricingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiModelPricing extends EditRecord
{
    protected static string $resource = AiModelPricingResource::class;

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
            DeleteAction::make(),
        ];
    }
}
