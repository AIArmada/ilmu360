<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues;

use App\Features\GitHubIssues\Actions\SubmitGitHubIssueReportAction;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Features\GitHubIssues\Api\SubmitGitHubIssueReport;
use App\Features\GitHubIssues\EntryPoints\Mcp\Tools\AdminCreateGitHubIssueTool;
use App\Features\GitHubIssues\EntryPoints\Mcp\Tools\MemberCreateGitHubIssueTool;
use App\Features\GitHubIssues\Persistence\GitHubIssueReporter;
use App\Features\GitHubIssues\Services\NullGitHubIssueReporter;
use App\Platform\FeatureModule;
use App\Platform\Mcp\McpSurfaceRegistry;

class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(SubmitGitHubIssueReport::class, SubmitGitHubIssueReportAction::class);
        $this->app->singleton(function ($app): GitHubIssueReporterContract {
            $reporter = $app->make(GitHubIssueReporter::class);

            return $reporter->isConfigured()
                ? $reporter
                : $app->make(NullGitHubIssueReporter::class);
        });
    }

    public function boot(): void
    {
        parent::boot();

        $surfaces = $this->app->make(McpSurfaceRegistry::class);

        $surfaces->registerAdminTool(AdminCreateGitHubIssueTool::class);

        $surfaces->registerMemberTool(MemberCreateGitHubIssueTool::class);
    }
}
