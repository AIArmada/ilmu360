<?php

namespace Database\Factories;

use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardProgramVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardEnrollmentAcceptance>
 */
class RewardEnrollmentAcceptanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrollment_id' => RewardEnrollment::factory(),
            'seq' => 1,
            'policy_version_id' => RewardProgramVersion::factory(),
            'material_terms_hash' => hash('sha256', 'test-material-terms'),
            'terms_label' => 'draft-1',
            'accepted_snapshot' => ['material_hash' => hash('sha256', 'test-material-terms')],
            'accepted_at' => now(),
            'accepted_via' => 'member-portal',
        ];
    }

    public function forVersion(RewardProgramVersion $version): static
    {
        return $this->state(fn (array $attributes): array => [
            'policy_version_id' => $version->getKey(),
            'material_terms_hash' => $version->material_terms_hash,
            'terms_label' => (string) ($version->terms['version_label'] ?? 'v'.$version->version),
        ]);
    }
}
