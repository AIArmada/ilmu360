<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackGitHubStatus;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Models\AppFeedback;
use App\Shared\Auth\AccountActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class EscalateAppFeedbackToGitHubAction
{
    use AsAction;

    public function __construct(
        private readonly GitHubIssueReporterContract $gitHubIssueReporter,
    ) {}

    public function handle(AccountActor $actor, AppFeedback $feedback, string $title, string $summary): AppFeedback
    {
        Gate::forUser($actor)->authorize('escalateToGitHub', $feedback);

        /** @var array{title: string, summary: string} $validated */
        $validated = Validator::make([
            'title' => $title,
            'summary' => $summary,
        ], [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'summary' => ['required', 'string', 'min:10', 'max:4000'],
        ])->validate();

        if (! $this->gitHubIssueReporter->isConfigured()) {
            throw ValidationException::withMessages([
                'github' => __('app-feedback.validation.github_not_configured'),
            ]);
        }

        $feedback = DB::transaction(function () use ($feedback): AppFeedback {
            $feedback = AppFeedback::query()
                ->lockForUpdate()
                ->findOrFail($feedback->getKey());

            if ($feedback->github_escalation_status !== AppFeedbackGitHubStatus::NotRequested) {
                throw ValidationException::withMessages([
                    'github' => __('app-feedback.validation.github_already_escalated'),
                ]);
            }

            $feedback->forceFill([
                'github_escalation_status' => AppFeedbackGitHubStatus::Pending,
                'github_escalation_token' => (string) str()->uuid(),
                'github_requested_at' => now(),
                'github_unknown_at' => null,
                'github_created_at' => null,
                'github_issue_number' => null,
                'github_issue_url' => null,
            ])->save();

            return $feedback;
        });

        $body = $this->issueBody($feedback, trim($validated['summary']));

        try {
            $response = $this->gitHubIssueReporter->createPlainIssue(trim($validated['title']), $body);
            $issue = $response['issue'];

            $feedback = $this->updatePendingEscalation($feedback, [
                'github_escalation_status' => AppFeedbackGitHubStatus::Created,
                'github_issue_number' => (int) $issue['number'],
                'github_issue_url' => (string) $issue['html_url'],
                'github_created_at' => now(),
                'github_unknown_at' => null,
            ]);
        } catch (Throwable $exception) {
            $feedback = $this->updatePendingEscalation($feedback, [
                'github_escalation_status' => AppFeedbackGitHubStatus::Unknown,
                'github_unknown_at' => now(),
            ]);

            Log::warning('Unable to create a GitHub issue for app feedback.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'github' => __('app-feedback.validation.github_escalation_failed'),
            ]);
        }

        return $feedback->fresh(['reporter', 'handler']) ?? $feedback;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updatePendingEscalation(AppFeedback $feedback, array $attributes): AppFeedback
    {
        return DB::transaction(function () use ($feedback, $attributes): AppFeedback {
            $current = AppFeedback::query()
                ->lockForUpdate()
                ->findOrFail($feedback->getKey());

            if ($current->github_escalation_status === AppFeedbackGitHubStatus::Pending
                && $current->github_escalation_token === $feedback->github_escalation_token) {
                $current->forceFill($attributes)->save();
            }

            return $current;
        });
    }

    private function issueBody(AppFeedback $feedback, string $summary): string
    {
        return implode("\n", [
            '# ilmu360° application feedback',
            '',
            '## Category',
            $feedback->category->value,
            '',
            '## Maintainer-reviewed summary',
            $summary,
            '',
            '<!-- ilmu360-app-feedback:'.$feedback->github_escalation_token.' -->',
        ]);
    }
}
