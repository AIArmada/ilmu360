<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Features\Ingestion\Models\TrackedProperty;
use App\Models\User;
use App\Platform\Api\Admin\AdminResourceHookRegistry;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic record serialization shared by every admin resource definition.
 */
final readonly class EloquentResourceSerializer
{
    public function __construct(
        private ResourceIntrospection $introspection,
        private AdminResourceHookRegistry $hooks,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array{
     *   id: string,
     *   route_key: string,
     *   title: string|null,
     *   attributes: array<string, mixed>,
     *   abilities: array<string, bool>,
     *   panel_routes: array<string, string|null>
     * }
     */
    public function serializeRecord(string $resourceClass, Model $record, ?User $user): array
    {
        $pages = $resourceClass::getPages();

        $this->loadMissingApiRelations($record);

        return [
            'id' => (string) $record->getKey(),
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->introspection->communicationsRecordTitle($record)
                ?? $this->hooks->for($resourceClass)?->recordTitle($record)
                ?? $this->introspection->htmlableToString($resourceClass::getRecordTitle($record)),
            'attributes' => $this->hooks->for($resourceClass)?->recordAttributes($record)
                ?? $this->serializeAttributes($record),
            'abilities' => $this->recordAbilities($resourceClass, $record, $user),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $this->introspection->resourcePanelUrl($resourceClass, 'view', $record) : null,
                'edit' => array_key_exists('edit', $pages) ? $this->introspection->resourcePanelUrl($resourceClass, 'edit', $record) : null,
            ],
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array{
     *   route_key: string,
     *   title: string|null,
     *   attributes: array<string, mixed>,
     *   abilities: array<string, bool>,
     *   panel_routes: array<string, string|null>
     * }
     */
    public function serializeRecordDetail(string $resourceClass, Model $record, ?User $user): array
    {
        $pages = $resourceClass::getPages();

        $this->loadMissingApiRelations($record);

        $attributes = $this->hooks->for($resourceClass)?->recordAttributes($record)
            ?? $this->serializeAttributes($record);

        return [
            'route_key' => (string) $record->getRouteKey(),
            'title' => $this->introspection->communicationsRecordTitle($record)
                ?? $this->hooks->for($resourceClass)?->recordTitle($record)
                ?? $this->introspection->htmlableToString($resourceClass::getRecordTitle($record)),
            'attributes' => $this->introspection->stripResponsiveImages($attributes),
            'abilities' => $this->recordAbilities($resourceClass, $record, $user),
            'panel_routes' => [
                'view' => array_key_exists('view', $pages) ? $this->introspection->resourcePanelUrl($resourceClass, 'view', $record) : null,
                'edit' => array_key_exists('edit', $pages) ? $this->introspection->resourcePanelUrl($resourceClass, 'edit', $record) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function serializeGenericAttributes(Model $record): array
    {
        return $this->introspection->sanitizeCommunicationsAttributes($record, $this->baseAttributes($record));
    }

    public function communicationsRecordTitle(Model $record): ?string
    {
        return $this->introspection->communicationsRecordTitle($record);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, bool>
     */
    public function recordAbilities(string $resourceClass, Model $record, ?User $user): array
    {
        return [
            'view' => $this->introspection->canActOnRecord('view', $resourceClass, $record, $user),
            'edit' => $this->introspection->canActOnRecord('update', $resourceClass, $record, $user),
            'delete' => $this->introspection->canActOnRecord('delete', $resourceClass, $record, $user),
            'force_delete' => $resourceClass::canForceDelete($record),
            'restore' => $resourceClass::canRestore($record),
            'replicate' => $resourceClass::canReplicate($record),
        ];
    }

    /**
     * @param  class-string<resource>|null  $resourceClass
     * @return array<string, mixed>
     */
    public function serializeRelatedRecord(?string $resourceClass, Model $record, ?User $user): array
    {
        if ($resourceClass !== null) {
            return $this->serializeRecord($resourceClass, $record, $user);
        }

        return ResourceReadSupport::serializeRelatedRecord(
            $record,
            $this->serializeGenericAttributes($record),
            $this->introspection->communicationsRecordTitle($record),
        );
    }

    /** @return array<string, mixed> */
    public function serializeAttributes(Model $record): array
    {
        $attributes = $this->baseAttributes($record);

        if ($record instanceof TrackedProperty) {
            $attributes['write_key'] = filled($record->write_key) ? 'present' : null;
        }

        if (method_exists($record, 'primaryAddress')) {
            $address = $record->primaryAddress();

            $attributes['address'] = $address instanceof Address
                ? $address->toArray()
                : [];

        }

        // Product API keys (not package relation names).
        if (method_exists($record, 'contactMethods')) {
            $contacts = $record->relationLoaded('contactMethods')
                ? $record->getRelation('contactMethods')
                : $record->contactMethods()->get();
            $attributes['contacts'] = $contacts->map(static fn ($contact): array => $contact->toArray())->values()->all();
            unset($attributes['contactMethods']);
        }

        if (method_exists($record, 'socialProfiles')) {
            $profiles = $record->relationLoaded('socialProfiles')
                ? $record->getRelation('socialProfiles')
                : $record->socialProfiles()->get();
            $attributes['social_media'] = $profiles->map(static fn ($profile): array => $profile->toArray())->values()->all();
            unset($attributes['socialProfiles']);
        }

        unset($attributes['addresses']);

        foreach ($this->hooks->all() as $hooks) {
            $attributes = $hooks->augmentAttributes($record, $attributes);
        }

        return $this->introspection->sanitizeCommunicationsAttributes($record, $attributes);
    }

    /** @return array<string, mixed> */
    private function baseAttributes(Model $record): array
    {
        return OwnerContext::withOwner(null, fn (): array => $record->toArray());
    }

    private function loadMissingApiRelations(Model $record): void
    {
        $relations = $this->introspection->apiRelations($record);

        if ($relations !== []) {
            $record->loadMissing($relations);
        }
    }
}
