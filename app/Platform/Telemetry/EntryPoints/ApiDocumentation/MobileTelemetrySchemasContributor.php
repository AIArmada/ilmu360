<?php

declare(strict_types=1);

namespace App\Platform\Telemetry\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use App\Platform\ApiDocumentation\Support\SharedSchemaFactory;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the native mobile telemetry accepted response and field contract.
 */
final readonly class MobileTelemetrySchemasContributor implements DocumentSchemaContributor
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
            'MobileTelemetryAcceptedResponse' => $this->mobileTelemetryAcceptedResponseSchema(),
            'MobileTelemetryFormResponse' => $this->mobileTelemetryFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceJsonResponseSchema($document, 'mobile/telemetry/events', 'post', 202, $components, 'MobileTelemetryAcceptedResponse', 'Native mobile telemetry accepted response.');
        $this->operations->replaceJsonResponseSchema($document, 'forms/mobile-telemetry', 'get', 200, $components, 'MobileTelemetryFormResponse', 'Mobile telemetry field contract response.');
    }

    private function mobileTelemetryAcceptedResponseSchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('message', new StringType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('received_events', new IntegerType)
                        ->addProperty('recorded_events', new IntegerType)
                        ->addProperty('dropped_events', new IntegerType)
                        ->addProperty('authenticated', new BooleanType)
                        ->addProperty('client', $this->schema->mixedObjectType())
                        ->setRequired(['received_events', 'recorded_events', 'dropped_events', 'authenticated', 'client']),
                )
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['message', 'data', 'meta']),
        );
    }

    private function mobileTelemetryFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components, [
                    'bearer_auth_optional' => new BooleanType,
                    'native_only' => new BooleanType,
                    'intended_clients' => (new ArrayType)->setItems(new StringType),
                    'required_headers' => (new ArrayType)->setItems($this->schema->mixedObjectType()),
                    'event_fields' => (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')),
                    'notes' => (new ArrayType)->setItems(new StringType),
                ]))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }
}
