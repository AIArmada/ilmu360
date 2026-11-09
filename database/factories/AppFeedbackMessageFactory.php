<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Models\AppFeedbackMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppFeedbackMessage>
 */
class AppFeedbackMessageFactory extends Factory
{
    protected $model = AppFeedbackMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feedback_id' => AppFeedback::factory(),
            'author_id' => User::factory(),
            'author_role' => 'reporter',
            'visibility' => 'reporter',
            'body' => fake()->paragraph(),
        ];
    }
}
