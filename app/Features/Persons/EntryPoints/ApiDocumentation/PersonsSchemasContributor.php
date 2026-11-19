<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\ApiDocumentation;

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
 * Documents person directory items, details, media, gallery, and affiliation payloads.
 */
final readonly class PersonsSchemasContributor implements DocumentSchemaContributor
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
            'PersonListItem' => $this->personListItemSchema($components),
            'PersonDirectoryItem' => $this->personListItemSchema($components, sparse: true),
            'Person' => $this->personSchema($components),
            'PersonDirectoryResponse' => $this->personDirectoryResponseSchema($components),
            'PersonDirectorySparseResponse' => $this->personDirectoryResponseSchema($components, 'PersonDirectoryItem'),
            'PersonDetailResponse' => $this->personDetailResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceOperationResponseSchema($document, 'persons', 'get', $components->getSchemaReference('PersonDirectorySparseResponse'));
    }

    private function personListItemSchema(Components $components, bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('middle_name', (new StringType)->nullable(true))
            ->addProperty('family_name', (new StringType)->nullable(true))
            ->addProperty('gender', (new StringType)->nullable(true))
            ->addProperty('formatted_name', new StringType)
            ->addProperty('status', new StringType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('avatar_url', new StringType)
            ->addProperty('country', $this->operations->nullableReference($components, 'Country'))
            ->addProperty('is_following', new BooleanType)
            ->addProperty('verified_by', (new StringType)->nullable(true));

        if (! $sparse) {
            $type->setRequired(['id', 'slug', 'name', 'middle_name', 'family_name', 'gender', 'formatted_name', 'status', 'events_count', 'avatar_url', 'country', 'is_following']);
        }

        return Schema::fromType($type);
    }

    private function personSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('slug', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('middle_name', (new StringType)->nullable(true))
                ->addProperty('family_name', (new StringType)->nullable(true))
                ->addProperty('gender', (new StringType)->nullable(true))
                ->addProperty('formatted_name', new StringType)
                ->addProperty('bio', (new StringType)->nullable(true))
                ->addProperty('address', $this->operations->nullableReference($components, 'AddressSelection'))
                ->addProperty('country', $this->operations->nullableReference($components, 'Country'))
                ->addProperty('location', (new StringType)->nullable(true))
                ->addProperty('status', new StringType)
                ->addProperty('is_following', new BooleanType)
                ->addProperty('media', $this->personMediaType())
                ->addProperty('gallery', $this->personGalleryListType())
                ->addProperty('institutions', $this->personInstitutionListType())
                ->addProperty('contacts', $this->contactListType())
                ->addProperty('social_media', $this->socialMediaListType())
                ->setRequired([
                    'id',
                    'slug',
                    'name',
                    'middle_name',
                    'family_name',
                    'gender',
                    'formatted_name',
                    'bio',
                    'address',
                    'country',
                    'location',
                    'status',
                    'is_following',
                    'media',
                    'gallery',
                    'institutions',
                    'contacts',
                    'social_media',
                ]),
        );
    }

    private function personDirectoryResponseSchema(Components $components, string $itemSchema = 'PersonListItem'): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference($itemSchema)))
                ->addProperty('meta', $this->schema->directoryMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function personDetailResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('person', $components->getSchemaReference('Person'))
                        ->addProperty('upcoming_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('upcoming_total', new IntegerType)
                        ->addProperty('past_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('past_total', new IntegerType)
                        ->addProperty('other_role_upcoming_participations', (new ArrayType)->setItems($components->getSchemaReference('EventParticipation')))
                        ->addProperty('other_role_upcoming_total', new IntegerType)
                        ->addProperty('other_role_past_participations', (new ArrayType)->setItems($components->getSchemaReference('EventParticipation')))
                        ->addProperty('other_role_past_total', new IntegerType)
                        ->setRequired([
                            'person',
                            'upcoming_events',
                            'upcoming_total',
                            'past_events',
                            'past_total',
                            'other_role_upcoming_participations',
                            'other_role_upcoming_total',
                            'other_role_past_participations',
                            'other_role_past_total',
                        ]),
                )
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function personMediaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('avatar_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->addProperty('share_image_url', (new StringType)->nullable(true))
            ->setRequired(['avatar_url', 'cover_url', 'share_image_url']);
    }

    private function personGalleryListType(): ArrayType
    {
        return (new ArrayType)->setItems(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('url', new StringType)
                ->addProperty('thumb_url', new StringType)
                ->setRequired(['id', 'name', 'url', 'thumb_url']),
        );
    }

    private function personInstitutionListType(): ArrayType
    {
        return (new ArrayType)->setItems(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('display_name', new StringType)
                ->addProperty('slug', new StringType)
                ->addProperty('position', (new StringType)->nullable(true))
                ->addProperty('is_primary', new BooleanType)
                ->addProperty('public_image_url', new StringType)
                ->addProperty('logo_url', new StringType)
                ->addProperty('cover_url', (new StringType)->nullable(true))
                ->setRequired(['id', 'name', 'display_name', 'slug', 'position', 'is_primary', 'public_image_url', 'logo_url', 'cover_url']),
        );
    }

    private function contactListType(): ArrayType
    {
        return (new ArrayType)->setItems(
            (new ObjectType)
                ->addProperty('category', new StringType)
                ->addProperty('label', new StringType)
                ->addProperty('value', new StringType)
                ->addProperty('type', new StringType)
                ->addProperty('is_public', new BooleanType)
                ->setRequired(['category', 'label', 'value', 'type', 'is_public']),
        );
    }

    private function socialMediaListType(): ArrayType
    {
        return (new ArrayType)->setItems(
            (new ObjectType)
                ->addProperty('platform', new StringType)
                ->addProperty('platform_label', new StringType)
                ->addProperty('url', new StringType)
                ->addProperty('resolved_url', new StringType)
                ->addProperty('username', new StringType)
                ->addProperty('display_username', new StringType)
                ->addProperty('icon_url', new StringType)
                ->setRequired(['platform', 'platform_label', 'url', 'resolved_url', 'username', 'display_username', 'icon_url']),
        );
    }
}
