<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use App\Platform\ApiDocumentation\Support\SharedSchemaFactory;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the membership-application field contract.
 */
final readonly class MembershipSchemasContributor implements DocumentSchemaContributor
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
            'MembershipApplicationFormResponse' => $this->membershipApplicationFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceJsonResponseSchema($document, 'forms/membership-applications/{subjectType}', 'get', 200, $components, 'MembershipApplicationFormResponse', 'Membership-application field contract response.');
    }

    private function membershipApplicationFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('flow', new StringType)
                        ->addProperty('method', new StringType)
                        ->addProperty('endpoint_template', new StringType)
                        ->addProperty('auth_required', new BooleanType)
                        ->addProperty('fields', (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')))
                        ->setRequired(['flow', 'method', 'endpoint_template', 'auth_required', 'fields']),
                )
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }
}
