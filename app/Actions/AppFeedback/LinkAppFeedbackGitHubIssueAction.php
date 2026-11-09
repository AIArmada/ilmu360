<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackGitHubStatus;
use App\Models\AppFeedback;
use App\Shared\Auth\AccountActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class LinkAppFeedbackGitHubIssueAction
{
    use AsAction;

    public function handle(AccountActor $actor, AppFeedback $feedback, string $issueUrl): AppFeedback
    {
        Gate::forUser($actor)->authorize('linkGitHubIssue', $feedback);

        /** @var array{issue_url: string} $validated */
        $validated = Validator::make([
            'issue_url' => $issueUrl,
        ], [
            'issue_url' => ['required', 'string', 'url', 'max:2048'],
        ])->validate();

        $issueUrl = trim($validated['issue_url']);
        $issueNumber = $this->issueNumberFromUrl($issueUrl);

        if ($issueNumber === null) {
            throw ValidationException::withMessages([
                'issue_url' => __('app-feedback.validation.github_issue_url_invalid'),
            ]);
        }

        $feedback = DB::transaction(function () use ($feedback, $issueNumber, $issueUrl): AppFeedback {
            $feedback = AppFeedback::query()
                ->lockForUpdate()
                ->findOrFail($feedback->getKey());

            if ($feedback->github_escalation_status === AppFeedbackGitHubStatus::Created) {
                throw ValidationException::withMessages([
                    'issue_url' => __('app-feedback.validation.github_already_escalated'),
                ]);
            }

            $feedback->forceFill([
                'github_escalation_status' => AppFeedbackGitHubStatus::Created,
                'github_issue_number' => $issueNumber,
                'github_issue_url' => $issueUrl,
                'github_created_at' => now(),
                'github_unknown_at' => null,
            ])->save();

            return $feedback;
        });

        return $feedback->fresh(['reporter', 'handler']) ?? $feedback;
    }

    private function issueNumberFromUrl(string $issueUrl): ?int
    {
        $parts = parse_url($issueUrl);

        if (! is_array($parts)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['github.com', 'www.github.com'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if (preg_match('#^/([^/]+)/([^/]+)/issues/([1-9][0-9]*)$#', $path, $matches) !== 1) {
            return null;
        }

        $owner = (string) config('services.github.issues.repository_owner', 'AIArmada');
        $repository = (string) config('services.github.issues.repository_name', 'ilmu360');

        if (strcasecmp($matches[1], $owner) !== 0 || strcasecmp($matches[2], $repository) !== 0) {
            return null;
        }

        return (int) $matches[3];
    }
}
