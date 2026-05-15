<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Support\Mcp\MemberMcpDocumentationPreflight;
use App\Support\Mcp\MemberVerifiedDocumentationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[IsReadOnly]
#[IsIdempotent]
class MemberDocumentationFetchTool extends AbstractMemberTool
{
    protected string $name = 'fetch';

    protected string $title = 'Fetch Verified Documentation Page';

    protected string $description = 'Use this when you need the full text of a verified ilmu360 member documentation page returned by the search tool. Accepts a stable documentation id only, not a url or file:// resource URI. Do not use this for Ahli record fetches; use the member record tools for those.';

    public function __construct(
        private readonly MemberVerifiedDocumentationCatalog $documentationCatalog,
        private readonly MemberMcpDocumentationPreflight $documentationPreflight,
    ) {
        $this->setMeta([
            'openai/toolInvocation/invoking' => 'Loading verified doc…',
            'openai/toolInvocation/invoked' => 'Documentation page ready.',
        ]);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'id' => ['required', 'string', 'min:1'],
            ]);

            $document = $this->documentationCatalog->fetch((string) $validated['id']);

            if ($document === null) {
                throw new NotFoundHttpException('Documentation page not found.');
            }

            if ((string) $validated['id'] === MemberMcpDocumentationPreflight::GUIDE_DOCUMENT_ID) {
                $this->documentationPreflight->markGuideInContext($request->sessionId());
            }

            return $document;
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()->min(1)->description('Stable documentation identifier returned by the search tool, such as docs-member-mcp-guide. Do not pass the document url or file:// resource URI.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->required()->description('Stable documentation identifier.'),
            'title' => $schema->string()->required()->description('Documentation title.'),
            'text' => $schema->string()->required()->description('Full document body.'),
            'url' => $schema->string()->required()->format('uri')->description('Canonical document URL.'),
            'metadata' => $schema->object([
                'description' => $schema->string()->required()->description('Human-readable document summary.'),
                'mime_type' => $schema->string()->required()->description('Document MIME type.'),
                'resource_uri' => $schema->string()->required()->format('uri')->description('Canonical MCP resource URI.'),
                'relative_path' => $schema->string()->required()->description('Repository-relative source path.'),
                'last_modified' => $schema->string()->required()->format('date-time')->description('UTC timestamp of the last modification.'),
            ])->required()->withoutAdditionalProperties(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->documentationCatalog->hasAnyDocuments();
    }
}
