<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\ShareTracking\Models\ShareBrowserSelection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareBrowserSelection>
 */
class ShareBrowserSelectionFactory extends Factory
{
    #[\Override]
    protected $model = ShareBrowserSelection::class;

    public function definition(): array
    {
        return [
            'browser_key' => (string) Str::ulid(),
            'current_attribution_id' => null,
            'accepted_at' => null,
        ];
    }
}
