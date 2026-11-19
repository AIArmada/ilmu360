<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Contributors;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\SharedSchemaFactory;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Publishes the shared value schemas used across owner payloads: the public
 * form field contract and conditional rule, plus address and country values.
 */
final readonly class SharedSchemasContributor implements DocumentSchemaContributor
{
    public function __construct(
        private SharedSchemaFactory $schema,
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
            'AddressSelection' => $this->addressSelectionSchema(),
            'Country' => $this->countrySchema(),
            'PublicFormFieldContract' => $this->schema->publicFormFieldContractSchema(),
            'PublicConditionalRule' => $this->schema->publicConditionalRuleSchema(),
        ];
    }

    public function patch(OpenApi $document, Components $components): void {}

    private function addressSelectionSchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('country_id', (new StringType)->nullable(true))
                ->addProperty('state_id', (new StringType)->nullable(true))
                ->addProperty('city_id', (new StringType)->nullable(true))
                ->addProperty('area_assignments', (new ObjectType)->additionalProperties((new StringType)->nullable(true)))
                ->setRequired([
                    'country_id',
                    'state_id',
                    'city_id',
                    'area_assignments',
                ]),
        );
    }

    private function countrySchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('iso2', new StringType)
                ->addProperty('key', (new StringType)->nullable(true))
                ->setRequired(['id', 'name', 'iso2', 'key']),
        );
    }
}
