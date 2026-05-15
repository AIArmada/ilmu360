<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Mcp\VerifiedDocumentationCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminDocumentationSearchTool extends AbstractAdminTool
{
    protected string $name = 'search';

    protected string $title = 'Search Verified Documentation';

    protected string $description = 'Use this when you need to search the verified ilmu360 admin MCP documentation exposed by this server. Do not use this for runtime event, speaker, institution, reference, or venue record searches.';

    public function __construct(
        private readonly VerifiedDocumentationCatalog $documentationCatalog,
    ) {
        $this->setMeta([
            'openai/toolInvocation/invoking' => 'Searching verified docs…',
            'openai/toolInvocation/invoked' => 'Documentation results ready.',
        ]);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'query' => ['required', 'string', 'min:1'],
            ]);

            return $this->documentationCatalog->search((string) $validated['query']);
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->min(1)->description('Natural-language query used to search the verified documentation pages exposed by this server.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->required()
                ->items(
                    $schema->object([
                        'id' => $schema->string()->required()->description('Stable documentation identifier.'),
                        'title' => $schema->string()->required()->description('Documentation title.'),
                        'url' => $schema->string()->required()->format('uri')->description('Canonical document URL.'),
                    ])->withoutAdditionalProperties()
                )
                ->default([])
                ->description('Search results from the verified documentation catalog.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->documentationCatalog->hasAnyDocuments();
    }
}
