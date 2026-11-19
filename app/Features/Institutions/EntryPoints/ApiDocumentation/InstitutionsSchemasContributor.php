<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\ApiDocumentation;

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
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents institution directory items, details, media, and the institution
 * workspace field contract.
 */
final readonly class InstitutionsSchemasContributor implements DocumentSchemaContributor
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
            'InstitutionListItem' => $this->institutionListItemSchema($components),
            'InstitutionDirectoryItem' => $this->institutionListItemSchema($components, sparse: true),
            'Institution' => $this->institutionSchema($components),
            'InstitutionDirectoryResponse' => $this->institutionDirectoryResponseSchema($components),
            'InstitutionDirectorySparseResponse' => $this->institutionDirectoryResponseSchema($components, 'InstitutionDirectoryItem'),
            'InstitutionDetailResponse' => $this->institutionDetailResponseSchema($components),
            'InstitutionWorkspaceFormResponse' => $this->institutionWorkspaceFormResponseSchema($components),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceOperationResponseSchema($document, 'institutions', 'get', $components->getSchemaReference('InstitutionDirectorySparseResponse'));
        $this->operations->replaceOperationResponseSchema($document, 'institutions/near', 'get', $components->getSchemaReference('InstitutionDirectorySparseResponse'));

        $institutionsNearOperation = $this->operations->findOperation($document, 'institutions/near', 'get');

        if ($institutionsNearOperation instanceof Operation) {
            $this->operations->replaceNumericQueryParameter(
                $institutionsNearOperation,
                'lat',
                'Current device latitude. Provide with `lng` if not using `near`.',
                3.139,
            );
            $this->operations->replaceNumericQueryParameter(
                $institutionsNearOperation,
                'lng',
                'Current device longitude. Provide with `lat` if not using `near`.',
                101.6869,
            );
        }

        $this->operations->replaceJsonResponseSchema($document, 'forms/institution-workspace', 'get', 200, $components, 'InstitutionWorkspaceFormResponse', 'Institution-workspace field contract response.');
    }

    private function institutionListItemSchema(Components $components, bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('type', (new StringType)->nullable(true))
            ->addProperty('names', (new ArrayType)->setItems(
                (new ObjectType)
                    ->addProperty('name_type', new StringType)
                    ->addProperty('full_name', new StringType)
                    ->addProperty('language_code', new StringType)
                    ->addProperty('is_primary', new BooleanType)
                    ->setRequired(['name_type', 'full_name', 'language_code', 'is_primary']),
            ))
            ->addProperty('display_name', new StringType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('public_image_url', new StringType)
            ->addProperty('logo_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->addProperty('country', $this->operations->nullableReference($components, 'Country'))
            ->addProperty('location', (new StringType)->nullable(true))
            ->addProperty('distance_km', (new NumberType)->nullable(true))
            ->addProperty('is_following', new BooleanType)
            ->addProperty('verified_by', (new StringType)->nullable(true));

        if (! $sparse) {
            $type->setRequired([
                'id',
                'slug',
                'name',
                'type',
                'names',
                'display_name',
                'events_count',
                'public_image_url',
                'logo_url',
                'cover_url',
                'country',
                'location',
                'distance_km',
                'is_following',
            ]);
        }

        return Schema::fromType($type);
    }

    private function institutionSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('slug', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('names', (new ArrayType)->setItems(
                    (new ObjectType)
                        ->addProperty('name_type', new StringType)
                        ->addProperty('full_name', new StringType)
                        ->addProperty('language_code', new StringType)
                        ->addProperty('is_primary', new BooleanType)
                        ->setRequired(['name_type', 'full_name', 'language_code', 'is_primary']),
                ))
                ->addProperty('display_name', new StringType)
                ->addProperty('description', (new StringType)->nullable(true))
                ->addProperty('status', new StringType)
                ->addProperty('type', (new StringType)->nullable(true))
                ->addProperty('type_label', (new StringType)->nullable(true))
                ->addProperty('address_line', (new StringType)->nullable(true))
                ->addProperty('address', $this->operations->nullableReference($components, 'AddressSelection'))
                ->addProperty('country', $this->operations->nullableReference($components, 'Country'))
                ->addProperty('map_url', (new StringType)->nullable(true))
                ->addProperty('map_lat', (new NumberType)->nullable(true))
                ->addProperty('map_lng', (new NumberType)->nullable(true))
                ->addProperty('followers_count', new IntegerType)
                ->addProperty('person_count', new IntegerType)
                ->addProperty('is_following', new BooleanType)
                ->addProperty('media', $this->institutionMediaType())
                ->addProperty('contacts', $this->contactListType())
                ->addProperty('social_media', $this->socialMediaListType())
                ->addProperty('waze_url', (new StringType)->nullable(true))
                ->addProperty('donation_channels', $this->donationChannelListType())
                ->setRequired([
                    'id',
                    'slug',
                    'name',
                    'names',
                    'display_name',
                    'description',
                    'status',
                    'type',
                    'type_label',
                    'address_line',
                    'address',
                    'country',
                    'map_url',
                    'map_lat',
                    'map_lng',
                    'followers_count',
                    'person_count',
                    'is_following',
                    'media',
                    'contacts',
                    'social_media',
                    'waze_url',
                    'donation_channels',
                ]),
        );
    }

    private function institutionDirectoryResponseSchema(Components $components, string $itemSchema = 'InstitutionListItem'): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference($itemSchema)))
                ->addProperty('meta', $this->institutionDirectoryMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function institutionDetailResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('institution', $components->getSchemaReference('Institution'))
                        ->addProperty('upcoming_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('upcoming_total', new IntegerType)
                        ->addProperty('past_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('past_total', new IntegerType)
                        ->setRequired(['institution', 'upcoming_events', 'upcoming_total', 'past_events', 'past_total']),
                )
                ->addProperty('meta', $this->schema->requestMetaType())
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
                ->addProperty('meta', $this->schema->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function institutionDirectoryMetaType(): ObjectType
    {
        return $this->schema->directoryMetaType()
            ->addProperty(
                'location',
                (new ObjectType)
                    ->addProperty('active', new BooleanType)
                    ->addProperty('lat', (new NumberType)->nullable(true))
                    ->addProperty('lng', (new NumberType)->nullable(true))
                    ->addProperty('radius_km', (new IntegerType)->nullable(true))
                    ->setRequired(['active', 'lat', 'lng', 'radius_km']),
            )
            ->addProperty('types', (new ArrayType)->setItems($this->schema->directoryFilterOptionType()));
    }

    private function institutionMediaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('public_image_url', new StringType)
            ->addProperty('logo_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->setRequired(['public_image_url', 'logo_url', 'cover_url']);
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

    private function donationChannelListType(): ArrayType
    {
        return (new ArrayType)->setItems(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('label', new StringType)
                ->addProperty('method', new StringType)
                ->addProperty('method_display', new StringType)
                ->addProperty('recipient', (new StringType)->nullable(true))
                ->addProperty('payment_details', (new StringType)->nullable(true))
                ->addProperty('bank_name', (new StringType)->nullable(true))
                ->addProperty('bank_code', (new StringType)->nullable(true))
                ->addProperty('account_number', (new StringType)->nullable(true))
                ->addProperty('duitnow_type', (new StringType)->nullable(true))
                ->addProperty('duitnow_value', (new StringType)->nullable(true))
                ->addProperty('ewallet_provider', (new StringType)->nullable(true))
                ->addProperty('ewallet_handle', (new StringType)->nullable(true))
                ->addProperty('is_default', new BooleanType)
                ->addProperty('qr_url', (new StringType)->nullable(true))
                ->setRequired([
                    'id',
                    'label',
                    'method',
                    'method_display',
                    'recipient',
                    'payment_details',
                    'bank_name',
                    'bank_code',
                    'account_number',
                    'duitnow_type',
                    'duitnow_value',
                    'ewallet_provider',
                    'ewallet_handle',
                    'is_default',
                    'qr_url',
                ]),
        );
    }
}
