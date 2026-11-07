<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Services;

use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Shared\Integrations\GitHubIssueReportingException;

/**
 * Null-object GitHub issue reporter when the integration is disabled.
 */
final class NullGitHubIssueReporter implements GitHubIssueReporterContract
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createPlainIssue(string $title, string $body): array
    {
        throw new GitHubIssueReportingException('GitHub issue reporting is not configured.');
    }

    public function createAdminIssue(string $title, string $body): array
    {
        throw new GitHubIssueReportingException('GitHub issue reporting is not configured.');
    }
}
