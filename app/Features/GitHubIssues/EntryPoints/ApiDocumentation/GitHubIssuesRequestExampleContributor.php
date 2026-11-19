<?php

declare(strict_types=1);

namespace App\Features\GitHubIssues\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class GitHubIssuesRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post github-issues' => [
                'category' => 'docs_mismatch',
                'title' => 'Clarify the MCP issue creation contract',
                'summary' => 'The MCP guide and the runtime tool behavior describe slightly different required fields for issue submission.',
                'platform' => 'chatgpt',
                'client_name' => 'ChatGPT',
                'client_version' => 'GPT-5.4',
                'current_endpoint' => '/mcp/member',
                'tool_name' => 'member-create-github-issue',
                'expected_behavior' => 'The guide should match the live tool schema exactly.',
                'actual_behavior' => 'The guide still references an older contract shape.',
                'proposal' => 'Document the final shared API and MCP payload shape in one canonical section.',
            ],
        ];
    }
}
