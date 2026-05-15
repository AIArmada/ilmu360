<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class PublicDirectorySchemasTransformer implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $components = $document->components;

        $this->putSchema($components, 'AddressSelection', $this->addressSelectionSchema());
        $this->putSchema($components, 'Country', $this->countrySchema());
        $this->putSchema($components, 'EventSummary', $this->eventSummarySchema());
        $this->putSchema($components, 'EventListItem', $this->eventSummarySchema(sparse: true));
        $this->putSchema($components, 'EventParticipation', $this->eventParticipationSchema($components));
        $this->putSchema($components, 'InstitutionListItem', $this->institutionListItemSchema($components));
        $this->putSchema($components, 'InstitutionDirectoryItem', $this->institutionListItemSchema($components, sparse: true));
        $this->putSchema($components, 'ReferenceListItem', $this->referenceListItemSchema());
        $this->putSchema($components, 'ReferenceDirectoryItem', $this->referenceListItemSchema(sparse: true));
        $this->putSchema($components, 'SpeakerListItem', $this->speakerListItemSchema($components));
        $this->putSchema($components, 'SpeakerDirectoryItem', $this->speakerListItemSchema($components, sparse: true));
        $this->putSchema($components, 'Institution', $this->institutionSchema($components));
        $this->putSchema($components, 'Speaker', $this->speakerSchema($components));
        $this->putSchema($components, 'InstitutionDirectoryResponse', $this->institutionDirectoryResponseSchema($components));
        $this->putSchema($components, 'InstitutionDirectorySparseResponse', $this->institutionDirectoryResponseSchema($components, 'InstitutionDirectoryItem'));
        $this->putSchema($components, 'ReferenceDirectoryResponse', $this->referenceDirectoryResponseSchema($components));
        $this->putSchema($components, 'ReferenceDirectorySparseResponse', $this->referenceDirectoryResponseSchema($components, 'ReferenceDirectoryItem'));
        $this->putSchema($components, 'SpeakerDirectoryResponse', $this->speakerDirectoryResponseSchema($components));
        $this->putSchema($components, 'SpeakerDirectorySparseResponse', $this->speakerDirectoryResponseSchema($components, 'SpeakerDirectoryItem'));
        $this->putSchema($components, 'EventIndexResponse', $this->eventIndexResponseSchema($components));
        $this->putSchema($components, 'InstitutionDetailResponse', $this->institutionDetailResponseSchema($components));
        $this->putSchema($components, 'SpeakerDetailResponse', $this->speakerDetailResponseSchema($components));

        $this->patchGeneratedOperations($document, $components);
    }

    private function putSchema(Components $components, string $name, Schema $schema): void
    {
        if ($components->hasSchema($name)) {
            $components->removeSchema($name);
        }

        $components->addSchema($name, $schema);
    }

    private function addressSelectionSchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('country_id', (new IntegerType)->nullable(true))
                ->addProperty('state_id', (new IntegerType)->nullable(true))
                ->addProperty('district_id', (new IntegerType)->nullable(true))
                ->addProperty('subdistrict_id', (new IntegerType)->nullable(true))
                ->setRequired(['country_id', 'state_id', 'district_id', 'subdistrict_id']),
        );
    }

    private function countrySchema(): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new IntegerType)
                ->addProperty('name', new StringType)
                ->addProperty('iso2', new StringType)
                ->addProperty('key', (new StringType)->nullable(true))
                ->setRequired(['id', 'name', 'iso2', 'key']),
        );
    }

    private function institutionListItemSchema(Components $components, bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('type', (new StringType)->nullable(true))
            ->addProperty('nickname', (new StringType)->nullable(true))
            ->addProperty('display_name', new StringType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('public_image_url', new StringType)
            ->addProperty('logo_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->addProperty('country', $this->nullableReference($components, 'Country'))
            ->addProperty('location', (new StringType)->nullable(true))
            ->addProperty('distance_km', (new NumberType)->nullable(true))
            ->addProperty('is_following', new BooleanType);

        if (! $sparse) {
            $type->setRequired([
                'id',
                'slug',
                'name',
                'type',
                'nickname',
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

    private function speakerListItemSchema(Components $components, bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('gender', (new StringType)->nullable(true))
            ->addProperty('formatted_name', new StringType)
            ->addProperty('status', new StringType)
            ->addProperty('is_active', new BooleanType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('avatar_url', new StringType)
            ->addProperty('country', $this->nullableReference($components, 'Country'))
            ->addProperty('is_following', new BooleanType);

        if (! $sparse) {
            $type->setRequired(['id', 'slug', 'name', 'gender', 'formatted_name', 'status', 'is_active', 'events_count', 'avatar_url', 'country', 'is_following']);
        }

        return Schema::fromType($type);
    }

    private function referenceListItemSchema(bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('display_title', new StringType)
            ->addProperty('author', (new StringType)->nullable(true))
            ->addProperty('type', (new StringType)->nullable(true))
            ->addProperty('parent_reference_id', (new StringType)->nullable(true))
            ->addProperty('part_type', (new StringType)->nullable(true))
            ->addProperty('part_number', (new StringType)->nullable(true))
            ->addProperty('part_label', (new StringType)->nullable(true))
            ->addProperty('is_part', new BooleanType)
            ->addProperty('publisher', (new StringType)->nullable(true))
            ->addProperty('publication_year', (new StringType)->nullable(true))
            ->addProperty('is_active', new BooleanType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('front_cover_url', (new StringType)->nullable(true))
            ->addProperty('is_following', new BooleanType);

        if (! $sparse) {
            $type->setRequired([
                'id',
                'slug',
                'title',
                'display_title',
                'author',
                'type',
                'parent_reference_id',
                'part_type',
                'part_number',
                'part_label',
                'is_part',
                'publisher',
                'publication_year',
                'is_active',
                'events_count',
                'front_cover_url',
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
                ->addProperty('nickname', (new StringType)->nullable(true))
                ->addProperty('display_name', new StringType)
                ->addProperty('description', (new StringType)->nullable(true))
                ->addProperty('status', new StringType)
                ->addProperty('type', (new StringType)->nullable(true))
                ->addProperty('type_label', (new StringType)->nullable(true))
                ->addProperty('address_line', (new StringType)->nullable(true))
                ->addProperty('address', $this->nullableReference($components, 'AddressSelection'))
                ->addProperty('country', $this->nullableReference($components, 'Country'))
                ->addProperty('map_url', (new StringType)->nullable(true))
                ->addProperty('map_lat', (new NumberType)->nullable(true))
                ->addProperty('map_lng', (new NumberType)->nullable(true))
                ->addProperty('followers_count', new IntegerType)
                ->addProperty('speaker_count', new IntegerType)
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
                    'nickname',
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
                    'speaker_count',
                    'is_following',
                    'media',
                    'contacts',
                    'social_media',
                    'waze_url',
                    'donation_channels',
                ]),
        );
    }

    private function speakerSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('id', new StringType)
                ->addProperty('slug', new StringType)
                ->addProperty('name', new StringType)
                ->addProperty('gender', (new StringType)->nullable(true))
                ->addProperty('formatted_name', new StringType)
                ->addProperty('job_title', (new StringType)->nullable(true))
                ->addProperty('is_freelance', new BooleanType)
                ->addProperty('bio', (new StringType)->nullable(true))
                ->addProperty('qualifications', (new ArrayType)->setItems(new StringType))
                ->addProperty('address', $this->nullableReference($components, 'AddressSelection'))
                ->addProperty('country', $this->nullableReference($components, 'Country'))
                ->addProperty('location', (new StringType)->nullable(true))
                ->addProperty('status', new StringType)
                ->addProperty('is_active', new BooleanType)
                ->addProperty('is_following', new BooleanType)
                ->addProperty('media', $this->speakerMediaType())
                ->addProperty('gallery', $this->speakerGalleryListType())
                ->addProperty('institutions', $this->speakerInstitutionListType())
                ->addProperty('contacts', $this->contactListType())
                ->addProperty('social_media', $this->socialMediaListType())
                ->setRequired([
                    'id',
                    'slug',
                    'name',
                    'gender',
                    'formatted_name',
                    'job_title',
                    'is_freelance',
                    'bio',
                    'qualifications',
                    'address',
                    'country',
                    'location',
                    'status',
                    'is_active',
                    'is_following',
                    'media',
                    'gallery',
                    'institutions',
                    'contacts',
                    'social_media',
                ]),
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
            ->addProperty('event_type', (new ArrayType)->setItems(new StringType))
            ->addProperty('event_type_label', (new StringType)->nullable(true))
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
            ->addProperty('speakers', (new ArrayType)->setItems($this->eventSpeakerType()));

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
                'event_type',
                'event_type_label',
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
                'speakers',
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
                ->addProperty('event', $this->nullableReference($components, 'EventSummary'))
                ->setRequired(['id', 'role', 'role_label', 'display_name', 'event']),
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

    private function speakerDirectoryResponseSchema(Components $components, string $itemSchema = 'SpeakerListItem'): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference($itemSchema)))
                ->addProperty('meta', $this->directoryMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function referenceDirectoryResponseSchema(Components $components, string $itemSchema = 'ReferenceListItem'): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference($itemSchema)))
                ->addProperty('meta', $this->referenceDirectoryMetaType())
                ->setRequired(['data', 'meta']),
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
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function speakerDetailResponseSchema(Components $components): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty(
                    'data',
                    (new ObjectType)
                        ->addProperty('speaker', $components->getSchemaReference('Speaker'))
                        ->addProperty('upcoming_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('upcoming_total', new IntegerType)
                        ->addProperty('past_events', (new ArrayType)->setItems($components->getSchemaReference('EventSummary')))
                        ->addProperty('past_total', new IntegerType)
                        ->addProperty('other_role_upcoming_participations', (new ArrayType)->setItems($components->getSchemaReference('EventParticipation')))
                        ->addProperty('other_role_upcoming_total', new IntegerType)
                        ->addProperty('other_role_past_participations', (new ArrayType)->setItems($components->getSchemaReference('EventParticipation')))
                        ->addProperty('other_role_past_total', new IntegerType)
                        ->setRequired([
                            'speaker',
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
                ->addProperty('meta', $this->requestMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function directoryMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty(
                'pagination',
                (new ObjectType)
                    ->addProperty('page', new IntegerType)
                    ->addProperty('per_page', new IntegerType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['page', 'per_page', 'total']),
            )
            ->addProperty(
                'cache',
                (new ObjectType)
                    ->addProperty('version', new StringType)
                    ->setRequired(['version']),
            )
            ->addProperty(
                'following',
                (new ObjectType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['total']),
            )
            ->addProperty('request_id', new StringType)
            ->setRequired(['pagination', 'cache', 'request_id']);
    }

    private function institutionDirectoryMetaType(): ObjectType
    {
        return $this->directoryMetaType()
            ->addProperty(
                'location',
                (new ObjectType)
                    ->addProperty('active', new BooleanType)
                    ->addProperty('lat', (new NumberType)->nullable(true))
                    ->addProperty('lng', (new NumberType)->nullable(true))
                    ->addProperty('radius_km', (new IntegerType)->nullable(true))
                    ->setRequired(['active', 'lat', 'lng', 'radius_km']),
            )
            ->addProperty('types', (new ArrayType)->setItems($this->directoryFilterOptionType()));
    }

    private function referenceDirectoryMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty(
                'pagination',
                (new ObjectType)
                    ->addProperty('page', new IntegerType)
                    ->addProperty('per_page', new IntegerType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['page', 'per_page', 'total']),
            )
            ->addProperty(
                'following',
                (new ObjectType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['total']),
            )
            ->setRequired(['pagination', 'following']);
    }

    private function directoryFilterOptionType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('value', new StringType)
            ->addProperty('label', new StringType)
            ->setRequired(['value', 'label']);
    }

    private function requestMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('request_id', new StringType)
            ->setRequired(['request_id']);
    }

    private function institutionMediaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('public_image_url', new StringType)
            ->addProperty('logo_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->setRequired(['public_image_url', 'logo_url', 'cover_url']);
    }

    private function speakerMediaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('avatar_url', new StringType)
            ->addProperty('cover_url', (new StringType)->nullable(true))
            ->addProperty('share_image_url', (new StringType)->nullable(true))
            ->setRequired(['avatar_url', 'cover_url', 'share_image_url']);
    }

    private function speakerGalleryListType(): ArrayType
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

    private function speakerInstitutionListType(): ArrayType
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

    private function eventSpeakerType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->addProperty('gender', (new StringType)->nullable(true))
            ->addProperty('formatted_name', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('avatar_url', (new StringType)->nullable(true))
            ->setRequired(['id', 'name', 'gender', 'formatted_name', 'slug', 'avatar_url']);
    }

    private function patchGeneratedOperations(OpenApi $document, Components $components): void
    {
        $this->replaceOperationResponseSchema($document, 'institutions', 'get', $components->getSchemaReference('InstitutionDirectorySparseResponse'));
        $this->replaceOperationResponseSchema($document, 'institutions/near', 'get', $components->getSchemaReference('InstitutionDirectorySparseResponse'));
        $this->replaceOperationResponseSchema($document, 'references', 'get', $components->getSchemaReference('ReferenceDirectorySparseResponse'));
        $this->replaceOperationResponseSchema($document, 'speakers', 'get', $components->getSchemaReference('SpeakerDirectorySparseResponse'));
        $this->replaceOperationResponseSchema($document, 'events', 'get', $components->getSchemaReference('EventIndexResponse'));

        $institutionsNearOperation = $this->findOperation($document, 'institutions/near', 'get');

        if ($institutionsNearOperation instanceof Operation) {
            $this->replaceNumericQueryParameter(
                $institutionsNearOperation,
                'lat',
                'Current device latitude. Provide with `lng` if not using `near`.',
                3.139,
            );
            $this->replaceNumericQueryParameter(
                $institutionsNearOperation,
                'lng',
                'Current device longitude. Provide with `lat` if not using `near`.',
                101.6869,
            );
        }

    }

    private function replaceOperationResponseSchema(OpenApi $document, string $path, string $method, Reference $schema): void
    {
        $operation = $this->findOperation($document, $path, $method);

        if (! $operation instanceof Operation) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || $response->code !== 200) {
                continue;
            }

            $response->setContent('application/json', $schema);
        }
    }

    private function replaceNumericQueryParameter(Operation $operation, string $name, string $description, float $example): void
    {
        foreach ($operation->parameters as $parameter) {
            if (! $parameter instanceof Parameter || $parameter->name !== $name) {
                continue;
            }

            $parameter
                ->setSchema(Schema::fromType(new NumberType))
                ->description($description)
                ->example($example);

            return;
        }

        $operation->addParameters([
            Parameter::make($name, 'query')
                ->required(false)
                ->setSchema(Schema::fromType(new NumberType))
                ->description($description)
                ->example($example),
        ]);
    }

    private function findOperation(OpenApi $document, string $path, string $method): ?Operation
    {
        $normalizedPath = trim($path, '/');
        $normalizedMethod = strtolower($method);

        foreach ($document->paths as $documentPath) {
            if (trim($documentPath->path, '/') !== $normalizedPath) {
                continue;
            }

            return $documentPath->operations[$normalizedMethod] ?? null;
        }

        return null;
    }

    private function nullableReference(Components $components, string $name): Reference
    {
        return (clone $components->getSchemaReference($name))->nullable(true);
    }
}
