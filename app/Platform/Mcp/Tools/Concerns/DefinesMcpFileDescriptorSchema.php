<?php

declare(strict_types=1);

namespace App\Platform\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait DefinesMcpFileDescriptorSchema
{
    protected function fileDescriptorSchema(JsonSchema $schema, bool $supportsFileId = false): Type
    {
        /** @var array<string, string> $contentSources */
        $contentSources = [
            'content_base64' => 'Raw base64 file bytes or a data URL.',
            'content_url' => 'Absolute HTTP(S) URL to fetch the file bytes.',
            'download_url' => 'ChatGPT connector file download URL.',
        ];
        /** @var list<Type> $variants */
        $variants = [];

        foreach ($contentSources as $source => $description) {
            /** @var array<string, Type> $properties */
            $properties = [
                'filename' => $schema->string()->required()->min(1),
                $source => $schema->string()->required()->min(1)->description($description),
                'mime_type' => $schema->string()->nullable()->min(1),
            ];

            if ($supportsFileId) {
                $properties['file_id'] = $schema->string()->min(1)
                    ->description('ChatGPT file identifier stored as upload metadata.');
            }

            $variants[] = $schema->object($properties)->withoutAdditionalProperties();
        }

        return $schema->anyOf($variants);
    }
}
