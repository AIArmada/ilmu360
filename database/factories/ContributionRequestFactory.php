<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Contributions\Support\ContributionSubjectType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionRequest>
 */
class ContributionRequestFactory extends Factory
{
    #[\Override]
    protected $model = ContributionRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ContributionRequestType::Update,
            'subject_type' => ContributionSubjectType::Reference,
            'proposer_id' => User::factory(),
            'status' => ContributionRequestStatus::Pending,
            'proposed_data' => [
                'title' => fake()->sentence(),
            ],
            'original_data' => [
                'title' => fake()->sentence(),
            ],
            'proposer_note' => fake()->optional()->sentence(),
        ];
    }
}
