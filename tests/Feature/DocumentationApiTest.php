<?php

declare(strict_types=1);

it('lists the curated documentation library over the public api', function (): void {
    $response = $this->getJson('/api/v1/documentation')
        ->assertOk();

    $documents = collect($response->json('data.documents') ?? []);

    expect($documents->pluck('id')->all())
        ->toContain(
            'docs-mobile-api-reference',
            'docs-admin-mcp-guide',
            'docs-member-mcp-guide',
            'docs-review-and-enhancement-plan',
            'docs-api-mcp-filament-crud-comparison-json',
        )
        ->and($documents->firstWhere('id', 'docs-mobile-api-reference')['endpoint'] ?? null)
        ->toContain('/api/v1/documentation/docs-mobile-api-reference')
        ->and($documents->firstWhere('id', 'docs-mobile-api-reference')['relative_path'] ?? null)
        ->toBe('docs/ilmu360_mobile_api_reference.md')
        ->and($documents->firstWhere('id', 'docs-admin-mcp-guide')['resource_uri'] ?? null)
        ->toBe('file://docs/ilmu360_mcp_admin_agent_guide.md');
});

it('fetches a curated documentation page over the public api', function (): void {
    $expectedContents = file_get_contents(base_path('docs/ilmu360_mobile_api_reference.md'));

    expect($expectedContents)->toBeString();

    $response = $this->getJson('/api/v1/documentation/docs-mobile-api-reference')
        ->assertOk();

    expect($response->json('data.id'))->toBe('docs-mobile-api-reference')
        ->and($response->json('data.title'))->toBe('ilmu360° Mobile API Reference')
        ->and($response->json('data.relative_path'))->toBe('docs/ilmu360_mobile_api_reference.md')
        ->and($response->json('data.mime_type'))->toBe('text/markdown')
        ->and($response->json('data.resource_uri'))->toBe('file://docs/ilmu360_mobile_api_reference.md')
        ->and($response->json('data.audiences'))->toContain('api', 'mcp_admin', 'mcp_member')
        ->and($response->json('data.tags'))->toContain('api', 'mobile', 'reference', 'http')
        ->and($response->json('data.text'))->toBe($expectedContents);
});

it('returns not found for an unknown documentation page id', function (): void {
    $this->getJson('/api/v1/documentation/not-a-real-doc-id')
        ->assertNotFound();
});
