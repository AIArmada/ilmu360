<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use App\Platform\ApiDocumentation\Support\SharedSchemaFactory;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/**
 * Documents the report submission field contract.
 */
final readonly class ReportsSchemasContributor implements DocumentSchemaContributor
{
    public function __construct(
        private SharedSchemaFactory $schema,
        private OperationSchemaPatcher $operations,
    ) {}

    public function supports(OpenApi $document, OpenApiContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, Schema>
     */
    public function schemas(Components $components): array
    {
        return [
            'ReportFormResponse' => $this->reportFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceJsonResponseSchema($document, 'forms/report', 'get', 200, $components, 'ReportFormResponse', 'Report field contract response.');
    }

    private function reportFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components, [
                    'defaults' => null,
                ], includeDefaults: false))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }
}
