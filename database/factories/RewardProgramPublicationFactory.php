<?php

namespace Database\Factories;

use App\Models\RewardProgram;
use App\Models\RewardProgramPublication;
use App\Models\RewardProgramVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardProgramPublication>
 */
class RewardProgramPublicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'program_id' => RewardProgram::factory(),
            'version_id' => RewardProgramVersion::factory(),
            'previous_version_id' => null,
            'version' => 1,
            'effective_from' => now()->subDay(),
            'reason' => null,
            'published_by_type' => 'user',
            'published_by_id' => null,
            'published_at' => now()->subDay(),
        ];
    }
}
