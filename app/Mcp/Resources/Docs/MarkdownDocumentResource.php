<?php

declare(strict_types=1);

namespace App\Mcp\Resources\Docs;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

abstract class MarkdownDocumentResource extends Resource
{
    protected string $mimeType = 'text/markdown';

    final public function handle(Request $request): Response
    {
        $contents = file_get_contents($this->documentAbsolutePath());

        if (! is_string($contents)) {
            return Response::error('Unable to read the markdown documentation resource.');
        }

        return Response::text($contents);
    }

    final public function shouldRegister(): bool
    {
        return is_file($this->documentAbsolutePath());
    }

    abstract protected function documentRelativePath(): string;

    final protected function documentAbsolutePath(): string
    {
        return base_path($this->documentRelativePath());
    }
}
