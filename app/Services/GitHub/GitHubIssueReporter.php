<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Exceptions\GitHubIssueReportingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * @phpstan-type GitHubIssueResponse array{
 *     issue: array{
 *         repository: string,
 *         number: int,
 *         title: string,
 *         api_url: string,
 *         html_url: string,
 *         assigned_to_copilot: bool,
 *         copilot_model: string|null,
 *         attempted_models: list<string>
 *     }
 * }
 */
class GitHubIssueReporter
{
    public function isEnabled(): bool
    {
        return (bool) config('services.github.issues.enabled', false);
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled($this->token())
            && filled($this->repositoryOwner())
            && filled($this->repositoryName());
    }

    /**
     * @return GitHubIssueResponse
     */
    public function createPlainIssue(string $title, string $body): array
    {
        $this->ensureConfigured();

        $response = $this->client()->post($this->issuesEndpoint(), [
            'title' => $title,
            'body' => $body,
        ]);

        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response, 'GitHub rejected the issue creation request.');
        }

        return $this->normalizeIssueResponse($response, false, null, []);
    }

    /**
     * @return GitHubIssueResponse
     */
    public function createAdminIssue(string $title, string $body): array
    {
        $this->ensureConfigured();

        if (! $this->adminCopilotAssignmentEnabled()) {
            return $this->createPlainIssue($title, $body);
        }

        $attemptedModels = [];
        $modelErrors = [];

        foreach ($this->adminModelCandidates() as $candidateModel) {
            $attemptedModels[] = $candidateModel;

            $response = $this->client()->post($this->issuesEndpoint(), [
                'title' => $title,
                'body' => $body,
                'assignees' => [$this->copilotAssignee()],
                'agent_assignment' => $this->agentAssignmentPayload($candidateModel),
            ]);

            if ($response->successful()) {
                return $this->normalizeIssueResponse($response, true, $candidateModel, $attemptedModels);
            }

            if ($this->shouldRetryWithNextModel($response)) {
                $modelErrors[] = $this->responseErrorSummary($response);

                continue;
            }

            throw $this->exceptionFromResponse($response, 'GitHub rejected the Copilot issue assignment request.');
        }

        throw new GitHubIssueReportingException(
            message: 'GitHub rejected every configured Copilot model candidate.',
            status: 422,
            errorCode: 'github_issue_model_rejected',
            details: [
                'attempted_models' => $attemptedModels,
                'errors' => $modelErrors,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function adminModelCandidates(): array
    {
        $candidates = array_filter([
            $this->configuredAdminModel(),
            ...$this->configuredAdminModelFallbacks(),
            'Auto',
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '');

        return array_values(array_unique(array_map(trim(...), $candidates)));
    }

    public function repositoryFullName(): string
    {
        return $this->repositoryOwner().'/'.$this->repositoryName();
    }

    /**
     * @return array<string, mixed>
     */
    private function agentAssignmentPayload(string $candidateModel): array
    {
        return array_filter([
            'target_repo' => $this->repositoryFullName(),
            'base_branch' => config('services.github.issues.base_branch'),
            'custom_instructions' => $this->customInstructions(),
            'custom_agent' => config('services.github.issues.custom_agent'),
            'model' => $candidateModel,
        ], static fn (mixed $value): bool => match (true) {
            is_string($value) => trim($value) !== '',
            default => $value !== null,
        });
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->asJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken($this->token())
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $this->apiVersion(),
            ]);
    }

    private function ensureConfigured(): void
    {
        if (! $this->isEnabled()) {
            throw new GitHubIssueReportingException(
                message: 'GitHub issue reporting is not enabled.',
                status: 503,
                errorCode: 'github_issue_reporting_disabled',
            );
        }

        if (! $this->isConfigured()) {
            throw new GitHubIssueReportingException(
                message: 'GitHub issue reporting is not configured correctly.',
                status: 503,
                errorCode: 'github_issue_reporting_not_configured',
            );
        }
    }

    private function shouldRetryWithNextModel(Response $response): bool
    {
        if (! $response->unprocessableEntity() && ! $response->badRequest()) {
            return false;
        }

        $message = strtolower($this->responseErrorSummary($response));

        return str_contains($message, 'model') || str_contains($message, 'agent_assignment');
    }

    /**
     * @param  list<string>  $attemptedModels
     * @return GitHubIssueResponse
     */
    private function normalizeIssueResponse(Response $response, bool $assignedToCopilot, ?string $usedModel, array $attemptedModels): array
    {
        return [
            'issue' => [
                'repository' => $this->repositoryFullName(),
                'number' => (int) $response->json('number'),
                'title' => (string) $response->json('title'),
                'api_url' => (string) $response->json('url'),
                'html_url' => (string) $response->json('html_url'),
                'assigned_to_copilot' => $assignedToCopilot,
                'copilot_model' => $usedModel,
                'attempted_models' => $attemptedModels,
            ],
        ];
    }

    private function exceptionFromResponse(Response $response, string $fallbackMessage): GitHubIssueReportingException
    {
        return new GitHubIssueReportingException(
            message: $this->responseMessage($response, $fallbackMessage),
            status: $response->status(),
            errorCode: 'github_issue_request_failed',
            details: [
                'status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ],
        );
    }

    private function responseMessage(Response $response, string $fallbackMessage): string
    {
        $message = $response->json('message');

        return is_string($message) && trim($message) !== '' ? trim($message) : $fallbackMessage;
    }

    private function responseErrorSummary(Response $response): string
    {
        $message = $this->responseMessage($response, 'GitHub request failed.');
        $errors = $response->json('errors');

        if (! is_array($errors) || $errors === []) {
            return $message;
        }

        return $message.' '.json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function issuesEndpoint(): string
    {
        return '/repos/'.$this->repositoryOwner().'/'.$this->repositoryName().'/issues';
    }

    private function customInstructions(): ?string
    {
        $instructions = config('services.github.issues.custom_instructions');

        if (! is_string($instructions)) {
            return null;
        }

        $trimmed = trim($instructions);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('services.github.issues.api_base', 'https://api.github.com'), '/');
    }

    private function apiVersion(): string
    {
        return (string) config('services.github.issues.api_version', '2026-03-10');
    }

    private function token(): string
    {
        return (string) config('services.github.issues.token', '');
    }

    private function repositoryOwner(): string
    {
        return (string) config('services.github.issues.repository_owner', '');
    }

    private function repositoryName(): string
    {
        return (string) config('services.github.issues.repository_name', '');
    }

    private function configuredAdminModel(): ?string
    {
        $model = config('services.github.issues.admin_model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * @return list<string>
     */
    private function configuredAdminModelFallbacks(): array
    {
        $fallbacks = config('services.github.issues.admin_model_fallbacks', []);

        if (! is_array($fallbacks)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $fallbacks),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function copilotAssignee(): string
    {
        return (string) config('services.github.issues.copilot_assignee', 'copilot-swe-agent[bot]');
    }

    private function adminCopilotAssignmentEnabled(): bool
    {
        return (bool) config('services.github.issues.admin_copilot_assignment_enabled', true);
    }
}
