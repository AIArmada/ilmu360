<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Api\Frontend;

use App\Features\GitHubIssues\Api\GitHubIssueReportContract;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;

final readonly class GitHubIssueReportFormContractContributor implements FrontendFormContractContributor
{
    public function __construct(
        private GitHubIssueReporterContract $reporter,
    ) {}

    public function supports(string $key): bool
    {
        if ($key === FrontendFormContractKey::GITHUB_ISSUE_REPORT) {
            return true;
        }

        return $key === FrontendFormContractKey::GITHUB_ISSUE_REPORTING
            && $this->reporter->isConfigured();
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        return [
            'flow' => 'github_issue_report',
            'method' => 'POST',
            'endpoint' => route('api.client.github-issues.store'),
            'auth_required' => true,
            'defaults' => GitHubIssueReportContract::defaults(),
            'fields' => GitHubIssueReportContract::fields(),
            'conditional_rules' => [],
        ];
    }
}
