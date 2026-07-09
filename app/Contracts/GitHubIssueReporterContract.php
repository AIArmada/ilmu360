<?php

declare(strict_types=1);

namespace App\Contracts;

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
interface GitHubIssueReporterContract
{
    public function isEnabled(): bool;

    public function isConfigured(): bool;

    /**
     * @return GitHubIssueResponse
     */
    public function createPlainIssue(string $title, string $body): array;

    /**
     * @return GitHubIssueResponse
     */
    public function createAdminIssue(string $title, string $body): array;
}
