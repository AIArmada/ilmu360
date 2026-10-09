<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Models\RewardProgram;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardProgram>
 */
class RewardProgramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'family_key' => 'family-'.Str::lower(Str::random(8)),
            'title' => 'Test reward program',
            'mode' => RewardProgramMode::Points,
            'owner_type' => 'platform',
            'owner_id' => null,
            'purpose' => 'test-program-purpose',
            'status' => RewardProgramStatus::Draft,
            'current_version_id' => null,
            'ready_at' => null,
            'active_at' => null,
            'paused_at' => null,
            'closed_at' => null,
        ];
    }

    public function ofMode(RewardProgramMode $mode): static
    {
        return $this->state(fn (array $attributes): array => ['mode' => $mode]);
    }

    public function withStatus(RewardProgramStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }
}
