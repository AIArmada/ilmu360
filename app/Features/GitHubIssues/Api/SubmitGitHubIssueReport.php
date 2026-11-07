<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Api;

use App\Shared\Auth\IntegrationFeedbackIdentity;

interface SubmitGitHubIssueReport
{
    /** @return array<string, mixed> */
    public function handle(IntegrationFeedbackIdentity $actor, GitHubIssueSubmissionData $submission, string $transport, ?string $requestId = null): array;
}
