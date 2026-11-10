<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Features\Directory\Api\DirectoryDetail;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Persons\PersonResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Spaces\SpaceResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Directory\DirectoryOption;
use App\Support\Api\Frontend\FrontendCatalogService;
use App\Support\Models\SlugOrUuidResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Interim Directory reader over the current Eloquent models.
 *
 * Directory owns these reads once its models move; this adapter keeps current
 * title, slug, URL and media behavior while exposing immutable values only.
 */
final readonly class EloquentDirectoryReader implements DirectoryReader
{
    public function __construct(
        private SlugOrUuidResolver $slugOrUuidResolver,
        private FrontendCatalogService $catalogs,
    ) {}

    public function exists(DirectoryRecordType $type, string $identifier): bool
    {
        return $this->find($type, $identifier) instanceof DirectoryDetail;
    }

    public function find(DirectoryRecordType $type, string $identifier): ?DirectoryDetail
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $model = $this->query($type, $identifier);

        return $model instanceof Model ? $this->detail($type, $model) : null;
    }

    public function options(
        DirectoryRecordType $type,
        ?string $search = null,
        int $limit = 50,
        ?string $accountId = null,
        ?string $institutionId = null,
    ): array {
        $limit = max(1, $limit);
        $options = match ($type) {
            DirectoryRecordType::Institution => $this->catalogs->submitInstitutions($this->account($accountId), $search, $limit),
            DirectoryRecordType::Person => $this->catalogs->submitPersons($this->account($accountId), $search, $limit),
            DirectoryRecordType::Reference => $this->catalogs->references($search, $limit),
            DirectoryRecordType::Venue => $this->catalogs->venues($search, $limit),
            DirectoryRecordType::Space => array_slice($this->catalogs->spaces($institutionId), 0, $limit),
            DirectoryRecordType::DonationChannel => $this->donationChannelOptions($search, $limit),
        };

        return array_map(
            static fn (array $option): DirectoryOption => new DirectoryOption(
                id: (string) $option['id'],
                label: (string) $option['label'],
            ),
            $options,
        );
    }

    private function query(DirectoryRecordType $type, string $identifier): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = MorphModelResolver::resolve($type->morphAlias());

        return match ($type) {
            DirectoryRecordType::Institution => $this->slugOrUuidResolver->first($modelClass::query(), 'institutions.slug', $identifier),
            DirectoryRecordType::Person => $this->slugOrUuidResolver->first($modelClass::query(), 'persons.slug', $identifier),
            DirectoryRecordType::Reference => $this->slugOrUuidResolver->first($modelClass::query(), 'references.slug', $identifier),
            DirectoryRecordType::Venue => $this->slugOrUuidResolver->first($modelClass::query(), 'venues.slug', $identifier),
            DirectoryRecordType::Space => $this->slugOrUuidResolver->first($modelClass::query(), 'venue_spaces.slug', $identifier),
            DirectoryRecordType::DonationChannel => $modelClass::query()->find($identifier),
        };
    }

    private function detail(DirectoryRecordType $type, Model $model): DirectoryDetail
    {
        return new DirectoryDetail(
            type: $type,
            id: (string) $model->getKey(),
            title: $this->title($type, $model),
            slug: $this->slug($type, $model),
            status: $this->status($model),
            publicUrl: $this->publicUrl($type, $model),
            adminUrl: $this->adminUrl($type, $model),
            imageUrl: $this->imageUrl($type, $model),
        );
    }

    private function title(DirectoryRecordType $type, Model $model): string
    {
        $fallback = (string) $model->getKey();

        return match ($type) {
            DirectoryRecordType::Institution => $model instanceof Institution && filled($model->display_name)
                ? (string) $model->display_name
                : $fallback,
            DirectoryRecordType::Person => $model instanceof Person && filled($model->formatted_name)
                ? (string) $model->formatted_name
                : $fallback,
            DirectoryRecordType::Reference => $model instanceof Reference
                ? $model->displayTitle()
                : $fallback,
            DirectoryRecordType::Venue => $model instanceof Venue && filled($model->name) ? (string) $model->name : $fallback,
            DirectoryRecordType::Space => $model instanceof Space && filled($model->name) ? (string) $model->name : $fallback,
            DirectoryRecordType::DonationChannel => $model instanceof DonationChannel
                ? $this->donationChannelTitle($model, $fallback)
                : $fallback,
        };
    }

    private function donationChannelTitle(DonationChannel $channel, string $fallback): string
    {
        foreach ([$channel->label, $channel->account_name, $channel->recipient] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return $fallback;
    }

    private function slug(DirectoryRecordType $type, Model $model): ?string
    {
        if ($type === DirectoryRecordType::DonationChannel) {
            return null;
        }

        $slug = $model->getAttribute('slug');

        return filled($slug) ? (string) $slug : null;
    }

    private function status(Model $model): ?string
    {
        $status = $model->getAttribute('status');

        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return filled($status) ? (string) $status : null;
    }

    private function publicUrl(DirectoryRecordType $type, Model $model): ?string
    {
        return match ($type) {
            DirectoryRecordType::Institution => $model instanceof Institution ? route('institutions.show', $model) : null,
            DirectoryRecordType::Person => $model instanceof Person ? route('persons.show', $model) : null,
            DirectoryRecordType::Reference => $model instanceof Reference ? route('references.show', $model) : null,
            DirectoryRecordType::Venue => $model instanceof Venue ? route('venues.show', $model) : null,
            DirectoryRecordType::Space, DirectoryRecordType::DonationChannel => null,
        };
    }

    private function adminUrl(DirectoryRecordType $type, Model $model): ?string
    {
        return match ($type) {
            DirectoryRecordType::Institution => $model instanceof Institution
                ? InstitutionResource::getUrl('edit', ['record' => $model], panel: 'admin')
                : null,
            DirectoryRecordType::Person => $model instanceof Person
                ? PersonResource::getUrl('edit', ['record' => $model], panel: 'admin')
                : null,
            DirectoryRecordType::Reference => $model instanceof Reference
                ? ReferenceResource::getUrl('edit', ['record' => $model], panel: 'admin')
                : null,
            DirectoryRecordType::Venue => $model instanceof Venue
                ? VenueResource::getUrl('view', ['record' => $model], panel: 'admin')
                : null,
            DirectoryRecordType::Space => $model instanceof Space
                ? SpaceResource::getUrl('view', ['record' => $model], panel: 'admin')
                : null,
            DirectoryRecordType::DonationChannel => $model instanceof DonationChannel
                ? DonationChannelResource::getUrl('edit', ['record' => $model], panel: 'admin')
                : null,
        };
    }

    private function imageUrl(DirectoryRecordType $type, Model $model): ?string
    {
        $url = match ($type) {
            DirectoryRecordType::Institution => $model instanceof Institution && $model->hasMedia('logo')
                ? $model->getFirstMediaUrl('logo', 'thumb')
                : null,
            DirectoryRecordType::Person => $model instanceof Person ? $model->public_main_url : null,
            DirectoryRecordType::Reference => $model instanceof Reference && $model->hasMedia('front_cover')
                ? $model->getFirstMediaUrl('front_cover', 'thumb')
                : null,
            DirectoryRecordType::Venue => $model instanceof Venue && $model->hasMedia('main')
                ? $model->getFirstMediaUrl('main', 'thumb')
                : null,
            DirectoryRecordType::Space => null,
            DirectoryRecordType::DonationChannel => $model instanceof DonationChannel && $model->hasMedia('qr')
                ? $model->getFirstMediaUrl('qr', 'thumb')
                : null,
        };

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function account(?string $accountId): ?User
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        $account = User::query()->find($accountId);

        return $account instanceof User ? $account : null;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function donationChannelOptions(?string $search, int $limit): array
    {
        $query = DonationChannel::query()->orderBy('label');
        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->where(function ($channelQuery) use ($normalizedSearch): void {
                $channelQuery
                    ->whereLike('label', '%'.$normalizedSearch.'%')
                    ->orWhereLike('recipient', '%'.$normalizedSearch.'%');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'label', 'account_name'])
            ->map(static fn (DonationChannel $channel): array => [
                'id' => (string) $channel->id,
                'label' => (string) ($channel->label ?: $channel->account_name ?: $channel->getKey()),
            ])
            ->all();
    }
}
