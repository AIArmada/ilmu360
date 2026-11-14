<?php

declare(strict_types=1);

namespace App\Features\Ai\Actions;

use App\Features\Ai\Models\AiModelPricing;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateAiModelPricingAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(AiModelPricing $pricing, array $attributes): AiModelPricing
    {
        $pricing->fill($attributes);
        $pricing->save();

        return $pricing;
    }
}
