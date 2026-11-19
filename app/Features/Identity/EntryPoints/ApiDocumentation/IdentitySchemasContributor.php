<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use App\Platform\ApiDocumentation\Support\SharedSchemaFactory;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents the authenticated user, account settings, and the public manifest
 * and search contracts that route clients into the rest of the API.
 */
final readonly class IdentitySchemasContributor implements DocumentSchemaContributor
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
            'SearchIndexResponse' => $this->searchIndexResponseSchema($components),
            'PublicManifestResponse' => $this->publicManifestResponseSchema(),
            'AccountSettingsFormResponse' => $this->accountSettingsFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceJsonResponseSchema($document, 'search', 'get', 200, $components, 'SearchIndexResponse', 'Unified search response.');
        $this->operations->replaceJsonResponseSchema($document, 'manifest', 'get', 200, $components, 'PublicManifestResponse', 'Public capability manifest response.');
        $this->operations->replaceJsonResponseSchema($document, 'forms/account-settings', 'get', 200, $components, 'AccountSettingsFormResponse', 'Account-settings field contract response.');

        $this->patchUserOperation($document);
    }

    private function patchUserOperation(OpenApi $document): void
    {
        $operation = $this->operations->findOperation($document, 'user', 'get');

        if (! $operation instanceof Operation) {
            return;
        }

        $operation
            ->summary('Get the current authenticated user')
            ->description('Returns the current authenticated user profile resolved from the Sanctum bearer token.')
            ->setTags(['Authentication']);
    }

    private function searchIndexResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('events', $this->schema->searchBucketType($components->getSchemaReference('EventSummary')))
                        ->addProperty('persons', $this->schema->searchBucketType($components->getSchemaReference('PersonListItem')))
                        ->addProperty('institutions', $this->schema->searchBucketType($components->getSchemaReference('InstitutionListItem')))
                        ->setRequired(['events', 'persons', 'institutions']),
                )
                ->addProperty(
                    'meta',
                    (new ObjectType)
                        ->addProperty('search', (new StringType)->nullable(true))
                        ->addProperty('lat', (new NumberType)->nullable(true))
                        ->addProperty('lng', (new NumberType)->nullable(true))
                        ->addProperty('radius_km', new IntegerType)
                        ->addProperty('authenticated', new BooleanType)
                        ->addProperty('request_id', new StringType)
                        ->setRequired(['search', 'lat', 'lng', 'radius_km', 'authenticated', 'request_id']),
                )
                ->setRequired(['data', 'meta']),
        );
    }

    private function publicManifestResponseSchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('version', new StringType)
                        ->addProperty('docs', $this->docsType())
                        ->addProperty('routing_surfaces', $this->routingSurfacesType())
                        ->addProperty('ai_quickstart', $this->aiQuickstartType())
                        ->addProperty('rules', (new ArrayType)->setItems(new StringType))
                        ->addProperty('catalogs', $this->catalogsType())
                        ->addProperty('flows', $this->flowsType())
                        ->addProperty('auth_context', $this->authContextType())
                        ->setRequired(['version', 'docs', 'routing_surfaces', 'ai_quickstart', 'rules', 'catalogs', 'flows', 'auth_context']),
                )
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function accountSettingsFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components, [
                    'show_endpoint' => new StringType,
                    'notification_settings_endpoint' => new StringType,
                    'notification_update_endpoint' => new StringType,
                    'mcp_tokens_endpoint' => new StringType,
                    'mcp_token_store_endpoint' => new StringType,
                    'mcp_token_revoke_endpoint_template' => new StringType,
                    'mcp_token_fields' => (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')),
                    'mcp_servers' => $this->schema->mixedObjectType(),
                ]))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function docsType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('ui', new StringType)
            ->addProperty('openapi', new StringType)
            ->addProperty('api_base', new StringType)
            ->setRequired(['ui', 'openapi', 'api_base']);
    }

    private function routingSurfacesType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('public', $this->routingSurfaceType())
            ->addProperty('admin', $this->routingSurfaceType())
            ->setRequired(['public', 'admin']);
    }

    private function routingSurfaceType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('base_path', new StringType)
            ->addProperty('record_scope', new StringType)
            ->addProperty('manifest_endpoint', new StringType)
            ->addProperty('write_contract_family', new StringType)
            ->setRequired(['base_path', 'record_scope', 'manifest_endpoint', 'write_contract_family']);
    }

    private function aiQuickstartType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('read_order', (new ArrayType)->setItems($this->quickstartStepType()))
            ->addProperty('decision_points', (new ArrayType)->setItems($this->decisionPointType()))
            ->setRequired(['read_order', 'decision_points']);
    }

    private function quickstartStepType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('step', new IntegerType)
            ->addProperty('action', new StringType)
            ->addProperty('endpoint', (new StringType)->nullable(true))
            ->addProperty('endpoint_family', (new StringType)->nullable(true))
            ->additionalProperties(new MixedType)
            ->setRequired(['step', 'action']);
    }

    private function decisionPointType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('need', new StringType)
            ->addProperty('use_surface', new StringType)
            ->setRequired(['need', 'use_surface']);
    }

    private function catalogsType(): ObjectType
    {
        return (new ObjectType)->additionalProperties((new StringType)->nullable(true));
    }

    private function flowsType(): ObjectType
    {
        return (new ObjectType)->additionalProperties($this->flowDescriptorType());
    }

    private function flowDescriptorType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('method', (new StringType)->nullable(true))
            ->addProperty('endpoint', (new StringType)->nullable(true))
            ->addProperty('endpoint_template', (new StringType)->nullable(true))
            ->addProperty('schema_endpoint', (new StringType)->nullable(true))
            ->addProperty('schema_endpoint_template', (new StringType)->nullable(true))
            ->addProperty('state_method', (new StringType)->nullable(true))
            ->addProperty('state_endpoint_template', (new StringType)->nullable(true))
            ->addProperty('follow_method', (new StringType)->nullable(true))
            ->addProperty('follow_endpoint_template', (new StringType)->nullable(true))
            ->addProperty('unfollow_method', (new StringType)->nullable(true))
            ->addProperty('unfollow_endpoint_template', (new StringType)->nullable(true))
            ->addProperty('auth_required', (new BooleanType)->nullable(true))
            ->additionalProperties(new MixedType);
    }

    private function authContextType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('authenticated', new BooleanType)
            ->addProperty('requires_bearer_token_for_mutations', new BooleanType)
            ->setRequired(['authenticated', 'requires_bearer_token_for_mutations']);
    }
}
