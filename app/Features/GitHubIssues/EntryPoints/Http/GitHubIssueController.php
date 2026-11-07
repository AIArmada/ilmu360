<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\EntryPoints\Http;

use App\Features\GitHubIssues\Actions\SubmitGitHubIssueReportAction;
use App\Shared\Integrations\GitHubIssueReportingException;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

#[Group('GitHub Issue Reporting', 'Authenticated feedback endpoints that create GitHub issues in the ilmu360 repository for maintainers to triage. Use the paired form-contract endpoint first.')]
class GitHubIssueController extends Controller
{
    #[Endpoint(
        title: 'Create a GitHub issue report',
        description: 'Creates a GitHub issue in the configured ilmu360 repository. Non-admin users create a plain issue. Admin users create the issue and automatically assign Copilot when the GitHub integration is configured for it.',
    )]
    public function store(
        StoreGitHubIssueReportRequest $request,
        SubmitGitHubIssueReportAction $submitGitHubIssueReportAction,
    ): JsonResponse {
        try {
            $result = $submitGitHubIssueReportAction->handle(
                actor: $request->actor(),
                submission: $request->submissionData(),
                transport: 'api',
                requestId: $this->requestId($request),
            );
        } catch (GitHubIssueReportingException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'details' => $exception->details,
                ],
                'meta' => [
                    'request_id' => $this->requestId($request),
                ],
            ], $exception->status);
        }

        return response()->json([
            'message' => 'GitHub issue created successfully.',
            'data' => $result,
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    private function requestId(Request $request): string
    {
        return $request->header('X-Request-ID', (string) Str::uuid());
    }
}
