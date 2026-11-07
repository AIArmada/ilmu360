<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\Actions;

use App\Features\GitHubIssues\Api\GitHubIssueReportContract;
use App\Features\GitHubIssues\Api\GitHubIssueReporterContract;
use App\Features\GitHubIssues\Api\GitHubIssueSubmissionData;
use App\Features\GitHubIssues\Api\SubmitGitHubIssueReport;
use App\Shared\Auth\IntegrationFeedbackIdentity;
use Lorisleiva\Actions\Concerns\AsAction;

class SubmitGitHubIssueReportAction implements SubmitGitHubIssueReport
{
    use AsAction;

    public function __construct(
        private readonly GitHubIssueReporterContract $gitHubIssueReporter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(IntegrationFeedbackIdentity $actor, GitHubIssueSubmissionData $submission, string $transport, ?string $requestId = null): array
    {
        $title = '['.GitHubIssueReportContract::categoryLabel($submission->category).'] '.$submission->title;
        $body = $this->issueBody($actor, $submission, $transport, $requestId);

        if ($actor->isAdmin) {
            return $this->gitHubIssueReporter->createAdminIssue($title, $body);
        }

        return $this->gitHubIssueReporter->createPlainIssue($title, $body);
    }

    private function issueBody(IntegrationFeedbackIdentity $actor, GitHubIssueSubmissionData $submission, string $transport, ?string $requestId): string
    {
        $sections = [
            '# ilmu360° Issue Report',
            '',
            '## Summary',
            $submission->summary,
            '',
            '## Classification',
            '- Category: '.GitHubIssueReportContract::categoryLabel($submission->category),
            '- Transport: '.strtoupper($transport),
            '- Platform: '.$submission->platform,
            '- Client: '.($submission->client_name ?? 'Not provided'),
            '- Client version: '.($submission->client_version ?? 'Not provided'),
            '',
            '## Reporter',
            '- User ID: '.$actor->id,
            '- Name: '.$actor->name,
            '- Has application admin access: '.($actor->isAdmin ? 'Yes' : 'No'),
            '- Has member MCP access: '.($actor->hasMemberMcpAccess ? 'Yes' : 'No'),
            '',
            '## Runtime context',
            '- Request ID: '.($requestId ?? 'Not provided'),
            '- Current endpoint: '.($submission->current_endpoint ?? 'Not provided'),
            '- MCP tool: '.($submission->tool_name ?? 'Not provided'),
        ];

        $this->appendOptionalSection($sections, 'Description', $submission->description);
        $this->appendOptionalSection($sections, 'Steps to reproduce', $submission->steps_to_reproduce);
        $this->appendOptionalSection($sections, 'Expected behavior', $submission->expected_behavior);
        $this->appendOptionalSection($sections, 'Actual behavior', $submission->actual_behavior);
        $this->appendOptionalSection($sections, 'Proposal', $submission->proposal);
        $this->appendOptionalSection($sections, 'Additional context', $submission->additional_context);

        return implode("\n", $sections)."\n";
    }

    /**
     * @param  list<string>  $sections
     */
    private function appendOptionalSection(array &$sections, string $heading, ?string $content): void
    {
        if ($content === null) {
            return;
        }

        $sections[] = '';
        $sections[] = '## '.$heading;
        $sections[] = $content;
    }
}
