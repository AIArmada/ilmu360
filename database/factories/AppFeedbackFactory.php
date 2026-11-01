<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppFeedbackCategory;
use App\Enums\AppFeedbackGitHubStatus;
use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppFeedback>
 */
class AppFeedbackFactory extends Factory
{
    protected $model = AppFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'submission_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'category' => fake()->randomElement(AppFeedbackCategory::cases()),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'page_url' => null,
            'diagnostics' => null,
            'status' => AppFeedbackStatus::New,
            'handled_by' => null,
            'internal_notes' => null,
            'resolution_note' => null,
            'triaged_at' => null,
            'resolved_at' => null,
            'declined_at' => null,
            'last_state_change_at' => now(),
            'github_escalation_status' => AppFeedbackGitHubStatus::NotRequested,
            'github_escalation_token' => null,
            'github_requested_at' => null,
            'github_created_at' => null,
            'github_unknown_at' => null,
            'github_issue_number' => null,
            'github_issue_url' => null,
        ];
    }

    public function triaged(?User $handler = null): static
    {
        return $this->state(fn (): array => [
            'status' => AppFeedbackStatus::Triaged,
            'handled_by' => $handler?->getKey() ?? User::factory(),
            'triaged_at' => now(),
            'last_state_change_at' => now(),
        ]);
    }

    public function resolved(?User $handler = null, string $note = 'Selesai disemak.'): static
    {
        return $this->state(fn (): array => [
            'status' => AppFeedbackStatus::Resolved,
            'handled_by' => $handler?->getKey() ?? User::factory(),
            'triaged_at' => now()->subHour(),
            'resolved_at' => now(),
            'resolution_note' => $note,
            'last_state_change_at' => now(),
        ]);
    }

    public function declined(?User $handler = null, string $note = 'Tidak diterima untuk pelaksanaan.'): static
    {
        return $this->state(fn (): array => [
            'status' => AppFeedbackStatus::Declined,
            'handled_by' => $handler?->getKey() ?? User::factory(),
            'triaged_at' => now()->subHour(),
            'declined_at' => now(),
            'resolution_note' => $note,
            'last_state_change_at' => now(),
        ]);
    }

    public function githubCreated(int $number = 42, string $url = 'https://github.com/AIArmada/ilmu360/issues/42'): static
    {
        return $this->state(fn (): array => [
            'github_escalation_status' => AppFeedbackGitHubStatus::Created,
            'github_escalation_token' => (string) Str::uuid(),
            'github_requested_at' => now()->subMinutes(5),
            'github_created_at' => now(),
            'github_issue_number' => $number,
            'github_issue_url' => $url,
        ]);
    }

    public function githubUnknown(): static
    {
        return $this->state(fn (): array => [
            'github_escalation_status' => AppFeedbackGitHubStatus::Unknown,
            'github_escalation_token' => (string) Str::uuid(),
            'github_requested_at' => now()->subMinutes(5),
            'github_unknown_at' => now(),
        ]);
    }
}
