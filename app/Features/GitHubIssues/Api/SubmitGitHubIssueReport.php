<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Api;

interface SubmitGitHubIssueReport
{
    /** @return array<string, mixed> */
    public function handle(IntegrationFeedbackIdentity $actor, GitHubIssueSubmissionData $submission, string $transport, ?string $requestId = null): array;
}
