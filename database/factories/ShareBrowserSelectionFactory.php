<?php

namespace Database\Factories;

use App\Models\ShareBrowserSelection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShareBrowserSelection>
 */
class ShareBrowserSelectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'browser_key' => (string) Str::ulid(),
            'current_attribution_id' => null,
            'accepted_at' => null,
        ];
    }
}
