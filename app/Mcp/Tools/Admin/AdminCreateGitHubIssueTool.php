<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Actions\GitHub\SubmitGitHubIssueReportAction;
use App\Data\GitHub\GitHubIssueSubmissionData;
use App\Exceptions\GitHubIssueReportingException;
use App\Services\GitHub\GitHubIssueReporter;
use App\Support\GitHub\GitHubIssueReportContract;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
class AdminCreateGitHubIssueTool extends AbstractAdminTool
{
    protected string $name = 'admin-create-github-issue';

    protected string $description = 'Create a GitHub issue in the configured ilmu360 repository. Admin Copilot assignment is controlled by server config and can be disabled.';

    public function __construct(
        private readonly SubmitGitHubIssueReportAction $submitGitHubIssueReportAction,
        private readonly GitHubIssueReporter $gitHubIssueReporter,
    ) {
        $this->setMeta([
            'openai/toolInvocation/invoking' => 'Creating GitHub issue…',
            'openai/toolInvocation/invoked' => 'GitHub issue created.',
        ]);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->safeResponse(function () use ($request): ResponseFactory {
            $actor = $this->authorizeAdmin($request);
            $validated = $this->validateArguments($request, GitHubIssueReportContract::rules());

            try {
                return Response::structured([
                    'data' => $this->submitGitHubIssueReportAction->handle(
                        actor: $actor,
                        submission: GitHubIssueSubmissionData::fromValidated($validated),
                        transport: 'mcp',
                    ),
                ]);
            } catch (GitHubIssueReportingException $exception) {
                return $this->errorResponse($exception->getMessage(), $exception->errorCode, $exception->details);
            }
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()
                ->required()
                ->enum(GitHubIssueReportContract::categories())
                ->default(GitHubIssueReportContract::DEFAULT_CATEGORY)
                ->description(GitHubIssueReportContract::categoryDescription()),
            'title' => $schema->string()->required()->min(3),
            'summary' => $schema->string()->required()->min(10),
            'description' => $schema->string()->nullable(),
            'platform' => $schema->string()->required()->min(1),
            'client_name' => $schema->string()->nullable(),
            'client_version' => $schema->string()->nullable(),
            'current_endpoint' => $schema->string()->nullable(),
            'tool_name' => $schema->string()->nullable(),
            'steps_to_reproduce' => $schema->string()->nullable(),
            'expected_behavior' => $schema->string()->nullable(),
            'actual_behavior' => $schema->string()->nullable(),
            'proposal' => $schema->string()->nullable(),
            'additional_context' => $schema->string()->nullable(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user?->hasApplicationAdminAccess() === true && $this->gitHubIssueReporter->isConfigured();
    }
}
