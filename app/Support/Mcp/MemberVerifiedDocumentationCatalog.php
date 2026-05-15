<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use App\Support\Documentation\DocumentationLibrary;

/**
 * @phpstan-type DocumentationRecord array{
 *   id: string,
 *   title: string,
 *   description: string,
 *   resource_uri: string,
 *   url: string,
 *   relative_path: string,
 *   mime_type: string
 * }
 */
class MemberVerifiedDocumentationCatalog
{
    public function __construct(
        private readonly DocumentationLibrary $documentationLibrary,
    ) {}

    /**
     * @return list<DocumentationRecord>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $document): array => [
                'id' => $document['id'],
                'title' => $document['title'],
                'description' => $document['description'],
                'resource_uri' => $document['resource_uri'],
                'url' => $document['resource_uri'],
                'relative_path' => $document['relative_path'],
                'mime_type' => $document['mime_type'],
            ],
            $this->documentationLibrary->forAudience(DocumentationLibrary::AUDIENCE_MCP_MEMBER),
        ));
    }

    public function hasAnyDocuments(): bool
    {
        return $this->documentationLibrary->hasAnyDocumentsForAudience(DocumentationLibrary::AUDIENCE_MCP_MEMBER);
    }

    public function has(string $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * @return DocumentationRecord|null
     */
    public function find(string $id): ?array
    {
        $document = $this->documentationLibrary->findForAudience(DocumentationLibrary::AUDIENCE_MCP_MEMBER, $id);

        if ($document === null) {
            return null;
        }

        return [
            'id' => $document['id'],
            'title' => $document['title'],
            'description' => $document['description'],
            'resource_uri' => $document['resource_uri'],
            'url' => $document['resource_uri'],
            'relative_path' => $document['relative_path'],
            'mime_type' => $document['mime_type'],
        ];
    }

    /**
     * @return array{results: list<array{id: string, title: string, url: string}>}
     */
    public function search(string $query): array
    {
        return $this->documentationLibrary->searchForAudience(DocumentationLibrary::AUDIENCE_MCP_MEMBER, $query);
    }

    /**
     * @return array{id: string, title: string, text: string, url: string, metadata: array<string, string>}|null
     */
    public function fetch(string $id): ?array
    {
        return $this->documentationLibrary->fetchForAudience(DocumentationLibrary::AUDIENCE_MCP_MEMBER, $id);
    }
}
