<?php

declare(strict_types=1);

namespace App\Features\Mcp\EntryPoints\Mcp\Resources;

use App\Platform\Documentation\DocumentationLibrary;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Completions\CompletionResponse;
use Laravel\Mcp\Server\Contracts\Completable;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('verified-documentation')]
#[Title('ilmu360° Verified Documentation')]
#[Description('Read verified Markdown documentation using a file://docs/ URI returned by the documentation search tool.')]
#[MimeType('text/markdown')]
abstract class VerifiedDocumentationResource extends Resource implements Completable, HasUriTemplate
{
    public function __construct(private readonly DocumentationLibrary $library) {}

    abstract protected function audience(): string;

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('file://docs/{filename}');
    }

    public function shouldRegister(): bool
    {
        return $this->library->hasAnyDocumentsForAudience($this->audience());
    }

    public function handle(Request $request): Response
    {
        foreach ($this->library->forAudience($this->audience()) as $document) {
            if ($document['resource_uri'] === $request->uri()) {
                $payload = $this->library->fetchForAudience($this->audience(), $document['id']);

                if ($payload !== null) {
                    return Response::text($payload['text']);
                }

                break;
            }
        }

        throw ValidationException::withMessages(['uri' => 'The requested documentation resource is unavailable.']);
    }

    /** @param array<string, mixed> $context */
    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        if ($argument !== 'filename') {
            return CompletionResponse::empty();
        }

        $uris = array_column($this->library->forAudience($this->audience()), 'resource_uri');

        return CompletionResponse::match(array_map(basename(...), $uris));
    }
}
