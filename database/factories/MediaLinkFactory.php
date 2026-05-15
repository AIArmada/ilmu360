<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\MediaLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaLink>
 */
class MediaLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement(['youtube', 'facebook', 'zoom', 'twitch']);

        return [
            'mediable_type' => Event::class,
            'mediable_id' => Event::factory(),
            'type' => fake()->randomElement(['livestream', 'recording', 'playlist', 'slides', 'other']),
            'provider' => $provider,
            'url' => 'https://'.$provider.'.com/'.Str::random(12),
            'is_primary' => fake()->boolean(20),
        ];
    }
}
