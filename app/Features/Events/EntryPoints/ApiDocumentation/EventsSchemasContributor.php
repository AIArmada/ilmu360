<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\ApiDocumentation;

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
 * Documents event summaries, participations, and the event submission forms.
 */
final readonly class EventsSchemasContributor implements DocumentSchemaContributor
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
            'EventSummary' => $this->eventSummarySchema(),
            'EventListItem' => $this->eventSummarySchema(sparse: true),
            'EventParticipation' => $this->eventParticipationSchema($components),
            'EventIndexResponse' => $this->eventIndexResponseSchema($components),
            'SubmitEventFormResponse' => $this->submitEventFormResponseSchema($components),
            'AdvancedEventFormResponse' => $this->advancedEventFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceOperationResponseSchema($document, 'events', 'get', $components->getSchemaReference('EventIndexResponse'));
        $this->operations->replaceJsonResponseSchema($document, 'forms/submit-event', 'get', 200, $components, 'SubmitEventFormResponse', 'Submit-event field contract response.');
        $this->operations->replaceJsonResponseSchema($document, 'forms/advanced-events', 'get', 200, $components, 'AdvancedEventFormResponse', 'Advanced-event field contract response.');
    }

    private function submitEventFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components, [
                    'captcha_required_when_turnstile_enabled' => new BooleanType,
                    'captcha_action' => new StringType,
                ]))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function advancedEventFormResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', $this->schema->standardFormContractType($components, [
                    'options' => $this->schema->mixedObjectType(),
                ]))
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function eventSummarySchema(bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('starts_at', (new StringType)->nullable(true))
            ->addProperty('starts_at_local', (new StringType)->nullable(true))
            ->addProperty('starts_on_local_date', (new StringType)->nullable(true))
            ->addProperty('ends_at', (new StringType)->nullable(true))
            ->addProperty('ends_at_local', (new StringType)->nullable(true))
            ->addProperty('timing_display', (new StringType)->nullable(true))
            ->addProperty('prayer_display_text', (new StringType)->nullable(true))
            ->addProperty('end_time_display', (new StringType)->nullable(true))
            ->addProperty('visibility', new StringType)
            ->addProperty('status', new StringType)
            ->addProperty('status_label', new StringType)
            ->addProperty('event_categories', (new ArrayType)->setItems(new ObjectType))
            ->addProperty('event_format', new StringType)
            ->addProperty('event_format_label', (new StringType)->nullable(true))
            ->addProperty('reference_study_subtitle', (new StringType)->nullable(true))
            ->addProperty('location', (new StringType)->nullable(true))
            ->addProperty('is_remote', new BooleanType)
            ->addProperty('is_pending', new BooleanType)
            ->addProperty('is_cancelled', new BooleanType)
            ->addProperty('has_poster', new BooleanType)
            ->addProperty('poster_url', (new StringType)->nullable(true))
            ->addProperty('card_image_url', (new StringType)->nullable(true))
            ->addProperty('institution', $this->eventInstitutionType())
            ->addProperty('venue', $this->eventVenueType())
            ->addProperty('persons', (new ArrayType)->setItems($this->eventPersonType()));

        if (! $sparse) {
            $type->setRequired([
                'id',
                'slug',
                'title',
                'starts_at',
                'starts_at_local',
                'starts_on_local_date',
                'ends_at',
                'ends_at_local',
                'timing_display',
                'prayer_display_text',
                'end_time_display',
                'visibility',
                'status',
                'status_label',
                'event_categories',
                'event_format',
                'event_format_label',
                'reference_study_subtitle',
                'location',
                'is_remote',
                'is_pending',
                'is_cancelled',
                'has_poster',
                'poster_url',
                'card_image_url',
                'institution',
                'venue',
                'persons',
            ]);
        }

        return Schema::fromType($type);
    }

    private function eventParticipationSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('role', new StringType)
                ->addProperty('role_label', (new StringType)->nullable(true))
                ->addProperty('display_name', (new StringType)->nullable(true))
                ->addProperty('event', $this->operations->nullableReference($components, 'EventSummary'))
                ->setRequired(['id', 'role', 'role_label', 'display_name', 'event']),
        );
    }

    private function eventIndexResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference('EventListItem')))
                ->addProperty(
                    'meta',
                    (new ObjectType)
                        ->addProperty(
                            'pagination',
                            (new ObjectType)
                                ->addProperty('page', new IntegerType)
                                ->addProperty('per_page', new IntegerType)
                                ->addProperty('total', new IntegerType)
                                ->addProperty('has_more', new BooleanType)
                                ->addProperty('next_page', (new IntegerType)->nullable(true))
                                ->setRequired(['page', 'per_page', 'total', 'has_more', 'next_page']),
                        )
                        ->setRequired(['pagination']),
                )
                ->setRequired(['data', 'meta']),
        );
    }

    private function eventInstitutionType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('type', (new StringType)->nullable(true))
            ->addProperty('display_name', (new StringType)->nullable(true))
            ->addProperty('public_image_url', (new StringType)->nullable(true))
            ->addProperty('logo_url', (new StringType)->nullable(true))
            ->setRequired(['id', 'name', 'slug', 'type', 'display_name', 'public_image_url', 'logo_url'])
            ->nullable(true);
    }

    private function eventVenueType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('slug', new StringType)
            ->setRequired(['id', 'name', 'slug'])
            ->nullable(true);
    }

    private function eventPersonType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('middle_name', (new StringType)->nullable(true))
            ->addProperty('family_name', (new StringType)->nullable(true))
            ->addProperty('gender', (new StringType)->nullable(true))
            ->addProperty('formatted_name', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('avatar_url', (new StringType)->nullable(true))
            ->setRequired(['id', 'name', 'gender', 'formatted_name', 'slug', 'avatar_url']);
    }
}
