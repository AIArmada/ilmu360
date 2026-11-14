<?php

declare(strict_types=1);

namespace App\Features\Ai\Actions;

use App\Features\Ai\Models\AiModelPricing;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateAiModelPricingAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): AiModelPricing
    {
        return AiModelPricing::query()->create($attributes);
    }
}
