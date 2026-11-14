<?php

namespace App\Features\Ai\Actions;

use App\Features\Ai\Models\AiModelPricing;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteAiModelPricingsAction
{
    use AsAction;

    public function __construct(private DeleteAiModelPricingAction $deleteAiModelPricing) {}

    /**
     * @param  list<string>  $pricingIds
     */
    public function handle(array $pricingIds): void
    {
        AiModelPricing::query()
            ->whereKey($pricingIds)
            ->get()
            ->each(function (AiModelPricing $pricing): void {
                $this->deleteAiModelPricing->handle($pricing);
            });
    }
}
