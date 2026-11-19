<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;

final readonly class DocumentSchemaTransformer implements DocumentTransformer
{
    public function __construct(
        private DocumentSchemaContributorRegistry $contributors,
    ) {}

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $components = $document->components;

        foreach ($this->contributors->all() as $contributor) {
            if (! $contributor->supports($document, $context)) {
                continue;
            }

            foreach ($contributor->schemas($components) as $name => $schema) {
                $this->putSchema($components, $name, $schema);
            }

            $contributor->patch($document, $components);
        }
    }

    private function putSchema(Components $components, string $name, Schema $schema): void
    {
        if ($components->hasSchema($name)) {
            $components->removeSchema($name);
        }

        $components->addSchema($name, $schema);
    }
}
