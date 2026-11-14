<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages;

use App\Features\Ai\Actions\CreateAiModelPricingAction;
use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiModelPricing extends CreateRecord
{
    #[\Override]
    protected static string $resource = AiModelPricingResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateAiModelPricingAction::class)->handle($data);
    }

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['provider'] = strtolower(trim((string) ($data['provider'] ?? '*')));
        $data['model_pattern'] = trim((string) ($data['model_pattern'] ?? '*'));
        $data['operation'] = trim((string) ($data['operation'] ?? '*'));
        $data['tier'] = filled($data['tier'] ?? null) ? strtolower(trim((string) $data['tier'])) : null;
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? 'USD')));

        return $data;
    }
}
