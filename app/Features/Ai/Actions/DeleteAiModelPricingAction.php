<?php

namespace App\Features\Ai\Actions;

use App\Features\Ai\Models\AiModelPricing;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteAiModelPricingAction
{
    use AsAction;

    public function handle(AiModelPricing $pricing): void
    {
        $pricing->delete();
    }
}
