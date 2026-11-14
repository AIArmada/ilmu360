<?php

declare(strict_types=1);

namespace App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\Pages;

use App\Features\Ai\EntryPoints\Filament\Resources\AiModelPricings\AiModelPricingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiModelPricings extends ListRecords
{
    #[\Override]
    protected static string $resource = AiModelPricingResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
