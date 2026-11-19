<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\ApiDocumentation;

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
 * Documents institution and person contribution forms plus the editable
 * contribution suggest-context payload.
 */
final readonly class ContributionsSchemasContributor implements DocumentSchemaContributor
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
            'InstitutionContributionFormResponse' => $this->institutionContributionFormResponseSchema($components),
            'PersonContributionFormResponse' => $this->personContributionFormResponseSchema($components),
            'ContributionSuggestContextResponse' => $this->contributionSuggestContextResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceJsonResponseSchema($document, 'forms/contributions/institutions', 'get', 200, $components, 'InstitutionContributionFormResponse', 'Institution contribution field contract response.');
        $this->operations->replaceJsonResponseSchema($document, 'forms/contributions/persons', 'get', 200, $components, 'PersonContributionFormResponse', 'Person contribution field contract response.');
        $this->operations->replaceJsonResponseSchema($document, 'forms/contributions/{subjectType}/{subject}/suggest', 'get', 200, $components, 'ContributionSuggestContextResponse', 'Editable contribution context response.');
    }

    private function institutionContributionFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function personContributionFormResponseSchema(Components $components): Schema
    {
        return $this->institutionContributionFormResponseSchema($components);
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
                        ->addProperty('initial_state', $this->schema->mixedObjectType())
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
                        ->addProperty('latest_pending_request', $this->schema->mixedObjectType()->nullable(true))
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
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }
}
