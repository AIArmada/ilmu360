<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

final class ApiWorkflowSchemasTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $components = $document->components;

        $this->registerSecurityScheme($components);
        $this->registerSchemas($components);
        $this->patchOperations($document, $components);
        $this->patchTagDescriptions($document);
    }

    private function registerSecurityScheme(Components $components): void
    {
        $components->addSecurityScheme(
            'sanctumBearer',
            SecurityScheme::http('bearer')
                ->as('sanctumBearer')
                ->setDescription('Sanctum bearer token returned by `POST /auth/login` or `POST /auth/register`. Send it as `Authorization: Bearer {token}`.'),
        );
    }

    private function registerSchemas(Components $components): void
    {
        $this->putSchema($components, 'SearchIndexResponse', $this->searchIndexResponseSchema($components));
        $this->putSchema($components, 'PublicManifestResponse', $this->publicManifestResponseSchema());
        $this->putSchema($components, 'PublicFormFieldContract', $this->publicFormFieldContractSchema());
        $this->putSchema($components, 'PublicConditionalRule', $this->publicConditionalRuleSchema());
        $this->putSchema($components, 'MobileTelemetryAcceptedResponse', $this->mobileTelemetryAcceptedResponseSchema());
        $this->putSchema($components, 'MobileTelemetryFormResponse', $this->mobileTelemetryFormResponseSchema($components));
        $this->putSchema($components, 'SubmitEventFormResponse', $this->submitEventFormResponseSchema($components));
        $this->putSchema($components, 'InstitutionContributionFormResponse', $this->institutionContributionFormResponseSchema($components));
        $this->putSchema($components, 'PersonContributionFormResponse', $this->personContributionFormResponseSchema($components));
        $this->putSchema($components, 'ReportFormResponse', $this->reportFormResponseSchema($components));
        $this->putSchema($components, 'GitHubIssueReportFormResponse', $this->gitHubIssueReportFormResponseSchema($components));
        $this->putSchema($components, 'AccountSettingsFormResponse', $this->accountSettingsFormResponseSchema($components));
        $this->putSchema($components, 'AdvancedEventFormResponse', $this->advancedEventFormResponseSchema($components));
        $this->putSchema($components, 'InstitutionWorkspaceFormResponse', $this->institutionWorkspaceFormResponseSchema($components));
        $this->putSchema($components, 'MembershipApplicationFormResponse', $this->membershipClaimFormResponseSchema($components));
        $this->putSchema($components, 'ContributionSuggestContextResponse', $this->contributionSuggestContextResponseSchema($components));
    }

    private function patchOperations(OpenApi $document, Components $components): void
    {
        $this->replaceJsonResponseSchema($document, 'search', 'get', 200, $components, 'SearchIndexResponse', 'Unified search response.');
        $this->replaceJsonResponseSchema($document, 'manifest', 'get', 200, $components, 'PublicManifestResponse', 'Public capability manifest response.');
        $this->replaceJsonResponseSchema($document, 'mobile/telemetry/events', 'post', 202, $components, 'MobileTelemetryAcceptedResponse', 'Native mobile telemetry accepted response.');
        $this->replaceJsonResponseSchema($document, 'forms/mobile-telemetry', 'get', 200, $components, 'MobileTelemetryFormResponse', 'Mobile telemetry field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/submit-event', 'get', 200, $components, 'SubmitEventFormResponse', 'Submit-event field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/contributions/institutions', 'get', 200, $components, 'InstitutionContributionFormResponse', 'Institution contribution field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/contributions/persons', 'get', 200, $components, 'PersonContributionFormResponse', 'Person contribution field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/report', 'get', 200, $components, 'ReportFormResponse', 'Report field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/github-issue-report', 'get', 200, $components, 'GitHubIssueReportFormResponse', 'GitHub issue-report field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/account-settings', 'get', 200, $components, 'AccountSettingsFormResponse', 'Account-settings field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/advanced-events', 'get', 200, $components, 'AdvancedEventFormResponse', 'Advanced-event field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/institution-workspace', 'get', 200, $components, 'InstitutionWorkspaceFormResponse', 'Institution-workspace field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/membership-applications/{subjectType}', 'get', 200, $components, 'MembershipApplicationFormResponse', 'Membership-application field contract response.');
        $this->replaceJsonResponseSchema($document, 'forms/contributions/{subjectType}/{subject}/suggest', 'get', 200, $components, 'ContributionSuggestContextResponse', 'Editable contribution context response.');
        $this->patchUserOperation($document);
    }

    private function patchUserOperation(OpenApi $document): void
    {
        $operation = $this->findOperation($document, 'user', 'get');

        if (! $operation instanceof Operation) {
            return;
        }

        $operation
            ->summary('Get the current authenticated user')
            ->description('Returns the current authenticated user profile resolved from the Sanctum bearer token.')
            ->setTags(['Authentication']);
    }

    private function patchTagDescriptions(OpenApi $document): void
    {
        $tagDescriptions = [
            'Catalog' => 'Public lookup catalogs for geography, tags, languages, references, venues, and write-flow selectors.',
            'Search' => 'Public aggregate search endpoints across events, persons, and institutions.',
            'Telemetry' => 'Native mobile telemetry endpoints for batching UI interaction events from real iOS, iPadOS, and Android app sessions. This surface is not for mobile web browsing.',
            'AccountSettings' => 'Authenticated account-settings read and update endpoints for client applications.',
            'GitHub Issue Reporting' => 'Authenticated feedback endpoints that create GitHub issues in the ilmu360 repository for maintainers to triage.',
            'InstitutionWorkspace' => 'Authenticated institution workspace endpoints for member management and institution-scoped event listings.',
            'MembershipApplication' => 'Authenticated membership-application endpoints for listing, creating, and cancelling subject membership claims.',
            'EventGoing' => 'Authenticated event-going endpoints for listing, reading, creating, and deleting the current user\'s going state.',
            'EventCheckIn' => 'Authenticated event self-check-in endpoints for state discovery and check-in recording.',
            'RegistrationExport' => 'Authenticated CSV export endpoints for institution event registrations.',
            'Report' => 'Authenticated reporting endpoints for user-submitted data and content issue reports.',
        ];

        $tagsByName = [];

        foreach ($document->tags as $tag) {
            $tagsByName[$tag->name] = $tag;
        }

        foreach ($tagDescriptions as $name => $description) {
            if (array_key_exists($name, $tagsByName)) {
                $tagsByName[$name]->description = $description;

                continue;
            }

            $tagsByName[$name] = new Tag($name, $description);
        }

        $document->tags = array_values($tagsByName);
    }

    private function replaceJsonResponseSchema(
        OpenApi $document,
        string $path,
        string $method,
        int $status,
        Components $components,
        string $schemaName,
        string $description,
    ): void {
        $operation = $this->findOperation($document, $path, $method);

        if (! $operation instanceof Operation) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof OpenApiResponse || (string) $response->code !== (string) $status) {
                continue;
            }

            $response
                ->setDescription($description)
                ->setContent('application/json', $components->getSchemaReference($schemaName));

            return;
        }
    }

    private function findOperation(OpenApi $document, string $path, string $method): ?Operation
    {
        $normalizedPath = trim($path, '/');
        $normalizedMethod = strtolower($method);

        foreach ($document->paths as $documentPath) {
            if ($documentPath->path !== $normalizedPath) {
                continue;
            }

            return $documentPath->operations[$normalizedMethod] ?? null;
        }

        return null;
    }

    private function putSchema(Components $components, string $name, Schema $schema): void
    {
        if ($components->hasSchema($name)) {
            $components->removeSchema($name);
        }

        $components->addSchema($name, $schema);
    }

    private function searchIndexResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('events', $this->searchBucketType($components->getSchemaReference('EventSummary')))
                        ->addProperty('persons', $this->searchBucketType($components->getSchemaReference('PersonListItem')))
                        ->addProperty('institutions', $this->searchBucketType($components->getSchemaReference('InstitutionListItem')))
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
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function publicFormFieldContractSchema(): Schema
    {
        return Schema::fromType($this->formFieldType());
    }

    private function publicConditionalRuleSchema(): Schema
    {
        return Schema::fromType($this->conditionalRuleType());
    }

    private function submitEventFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components, [
                    'captcha_required_when_turnstile_enabled' => new BooleanType,
                ]))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function institutionContributionFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function personContributionFormResponseSchema(Components $components): Schema
    {
        return $this->institutionContributionFormResponseSchema($components);
    }

    private function reportFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components, [
                    'defaults' => null,
                ], includeDefaults: false))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
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
                        ->addProperty('client', $this->mixedObjectType())
                        ->setRequired(['received_events', 'recorded_events', 'dropped_events', 'authenticated', 'client']),
                )
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['message', 'data', 'meta']),
        );
    }

    private function mobileTelemetryFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components, [
                    'bearer_auth_optional' => new BooleanType,
                    'native_only' => new BooleanType,
                    'intended_clients' => (new ArrayType)->setItems(new StringType),
                    'required_headers' => (new ArrayType)->setItems($this->mixedObjectType()),
                    'event_fields' => (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')),
                    'notes' => (new ArrayType)->setItems(new StringType),
                ]))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function gitHubIssueReportFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function accountSettingsFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components, [
                    'show_endpoint' => new StringType,
                    'notification_settings_endpoint' => new StringType,
                    'notification_update_endpoint' => new StringType,
                    'mcp_tokens_endpoint' => new StringType,
                    'mcp_token_store_endpoint' => new StringType,
                    'mcp_token_revoke_endpoint_template' => new StringType,
                    'mcp_token_fields' => (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')),
                    'mcp_servers' => $this->mixedObjectType(),
                ]))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function advancedEventFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->standardFormContractType($components, [
                    'options' => $this->mixedObjectType(),
                ]))
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function institutionWorkspaceFormResponseSchema(Components $components): Schema
    {
        $fieldReference = $components->getSchemaReference('PublicFormFieldContract');

        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('flow', new StringType)
                        ->addProperty('workspace_endpoint', new StringType)
                        ->addProperty('member_add_endpoint_template', new StringType)
                        ->addProperty('member_update_endpoint_template', new StringType)
                        ->addProperty('member_remove_endpoint_template', new StringType)
                        ->addProperty('member_add_fields', (new ArrayType)->setItems($fieldReference))
                        ->addProperty('member_edit_fields', (new ArrayType)->setItems($fieldReference))
                        ->setRequired([
                            'flow',
                            'workspace_endpoint',
                            'member_add_endpoint_template',
                            'member_update_endpoint_template',
                            'member_remove_endpoint_template',
                            'member_add_fields',
                            'member_edit_fields',
                        ]),
                )
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function membershipClaimFormResponseSchema(Components $components): Schema
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
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function contributionSuggestContextResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty(
                            'entity',
                            (new ObjectType)
                                ->addProperty('id', new StringType)
                                ->addProperty('type', new StringType)
                                ->addProperty('slug', new StringType)
                                ->addProperty('title', new StringType)
                                ->addProperty('status', new StringType)
                                ->setRequired(['id', 'type', 'slug', 'title', 'status']),
                        )
                        ->addProperty('initial_state', $this->mixedObjectType())
                        ->addProperty('accepts_partial_updates', new BooleanType)
                        ->addProperty('fields', (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')))
                        ->addProperty('conditional_rules', (new ArrayType)->setItems($components->getSchemaReference('PublicConditionalRule')))
                        ->addProperty('direct_edit_media_fields', (new ArrayType)->setItems(new StringType))
                        ->addProperty(
                            'current_media',
                            (new ObjectType)
                                ->additionalProperties(
                                    (new ArrayType)->setItems(
                                        (new ObjectType)
                                            ->addProperty('id', new StringType)
                                            ->addProperty('name', new StringType)
                                            ->addProperty('url', new StringType)
                                            ->addProperty('thumb_url', (new StringType)->nullable(true))
                                            ->setRequired(['id', 'name', 'url', 'thumb_url']),
                                    ),
                                ),
                        )
                        ->addProperty(
                            'subject_presentation',
                            (new ObjectType)
                                ->addProperty('subject_label', (new StringType)->nullable(true))
                                ->addProperty('subject_title', new StringType)
                                ->addProperty('redirect_url', new StringType)
                                ->setRequired(['subject_label', 'subject_title', 'redirect_url']),
                        )
                        ->addProperty('can_direct_edit', new BooleanType)
                        ->addProperty('latest_pending_request', $this->mixedObjectType()->nullable(true))
                        ->setRequired([
                            'entity',
                            'initial_state',
                            'accepts_partial_updates',
                            'fields',
                            'conditional_rules',
                            'direct_edit_media_fields',
                            'current_media',
                            'subject_presentation',
                            'can_direct_edit',
                            'latest_pending_request',
                        ]),
                )
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    /**
     * @param  array<string, Type|null>  $extraProperties
     */
    private function standardFormContractType(Components $components, array $extraProperties = [], bool $includeDefaults = true): ObjectType
    {
        $object = (new ObjectType)
            ->addProperty('flow', new StringType)
            ->addProperty('method', new StringType)
            ->addProperty('endpoint', new StringType)
            ->addProperty('auth_required', new BooleanType)
            ->addProperty('fields', (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')))
            ->addProperty('conditional_rules', (new ArrayType)->setItems($components->getSchemaReference('PublicConditionalRule')));

        if ($includeDefaults) {
            $object->addProperty('defaults', $this->mixedObjectType());
        }

        foreach ($extraProperties as $name => $type) {
            if ($type !== null) {
                $object->addProperty($name, $type);
            }
        }

        $required = ['flow', 'method', 'endpoint', 'auth_required', 'fields', 'conditional_rules'];

        if ($includeDefaults) {
            $required[] = 'defaults';
        }

        return $object->setRequired(array_merge($required, array_keys(array_filter($extraProperties, static fn ($type) => $type !== null))));
    }

    private function searchBucketType(Reference $itemReference): ObjectType
    {
        return (new ObjectType)
            ->addProperty('items', (new ArrayType)->setItems($itemReference))
            ->addProperty('total', new IntegerType)
            ->setRequired(['items', 'total']);
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

    private function formFieldType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('name', new StringType)
            ->addProperty('type', new StringType)
            ->addProperty('required', new BooleanType)
            ->addProperty('label', (new StringType)->nullable(true))
            ->addProperty('description', (new StringType)->nullable(true))
            ->addProperty('default', new MixedType)
            ->addProperty('allowed_values', (new ArrayType)->setItems(new MixedType)->nullable(true))
            ->addProperty('max_length', (new IntegerType)->nullable(true))
            ->addProperty('min_length', (new IntegerType)->nullable(true))
            ->addProperty('multiple', (new BooleanType)->nullable(true))
            ->addProperty('catalog', (new StringType)->nullable(true))
            ->addProperty('accept', (new ArrayType)->setItems(new StringType)->nullable(true))
            ->additionalProperties(new MixedType)
            ->setRequired(['name', 'type', 'required']);
    }

    private function conditionalRuleType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('field', new StringType)
            ->additionalProperties(new MixedType)
            ->setRequired(['field']);
    }

    private function mixedObjectType(): ObjectType
    {
        return (new ObjectType)->additionalProperties(new MixedType);
    }

    private function requestMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('request_id', new StringType)
            ->setRequired(['request_id']);
    }
}
