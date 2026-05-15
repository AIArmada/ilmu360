<?php

declare(strict_types=1);

use App\Support\Documentation\DocumentationLibrary;

it('exposes the full curated documentation catalog through the public documentation api', function (): void {
    $documentationLibrary = app(DocumentationLibrary::class);
    $expectedDocuments = collect($documentationLibrary->apiLibrary());

    $response = $this->getJson('/api/v1/documentation')
        ->assertOk();

    $documents = collect($response->json('data.documents') ?? []);

    expect($documents->pluck('id')->all())
        ->toBe($expectedDocuments->pluck('id')->all())
        ->and($documents->firstWhere('id', 'docs-technical-documentation'))
        ->toMatchArray([
            'relative_path' => 'docs/ilmu360_technical_documentation.md',
            'resource_uri' => 'file://docs/ilmu360_technical_documentation.md',
        ]);

    $this->getJson('/api/v1/documentation/docs-technical-documentation')
        ->assertOk()
        ->assertJsonPath('data.id', 'docs-technical-documentation')
        ->assertJsonPath('data.relative_path', 'docs/ilmu360_technical_documentation.md')
        ->assertJsonPath('data.resource_uri', 'file://docs/ilmu360_technical_documentation.md');
});

it('includes the full curated documentation catalog in the frontend api manifest', function (): void {
    $documentationLibrary = app(DocumentationLibrary::class);
    $expectedDocumentIds = collect($documentationLibrary->apiLibrary())->pluck('id')->all();

    $frontendResponse = $this->getJson('/api/v1/manifest')
        ->assertOk();

    expect(collect($frontendResponse->json('data.docs.library') ?? [])->pluck('id')->all())
        ->toBe($expectedDocumentIds)
        ->and($frontendResponse->json('data.docs.catalog_endpoint'))
        ->toContain('/api/v1/documentation')
        ->and($frontendResponse->json('data.docs.document_endpoint_template'))
        ->toContain('/api/v1/documentation/documentId');
});
