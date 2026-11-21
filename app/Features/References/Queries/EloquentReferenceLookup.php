<?php

declare(strict_types=1);

namespace App\Features\References\Queries;

use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceSummary;
use App\Features\References\Models\Reference;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectType;
use App\Platform\Admin\AdminResourceUrl;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Directory\FollowableSubject;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentReferenceLookup implements ReferenceLookup
{
    public function __construct(private SlugOrUuidResolver $resolver) {}

    public function shareIdentityByIdentifier(string $identifier): ?array
    {
        $reference = $this->resolver->first(
            Reference::query()->withoutOwnerScope()->active(),
            'references.slug',
            $identifier,
        );

        if (! $reference instanceof Reference) {
            return null;
        }

        return [
            'id' => (string) $reference->getKey(),
            'slug' => (string) $reference->slug,
            'title' => $reference->displayTitle(),
        ];
    }

    public function resolveIdentifier(string $identifier): ?string
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Reference ? (string) $entity->getKey() : null;
    }

    public function findFollowableSubject(string $identifier): ?FollowableSubject
    {
        $reference = $this->entity($identifier);

        if (! $reference instanceof Reference) {
            return null;
        }

        return $this->followable($reference);
    }

    public function findDirectoryFollowableSubject(string $identifier): ?FollowableSubject
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $reference = $this->resolver->first(Reference::query()->active(), 'references.slug', $identifier);

        return $reference instanceof Reference ? $this->followable($reference) : null;
    }

    public function referenceMorphType(): string
    {
        return (new Reference)->getMorphClass();
    }

    public function exists(string $referenceId): bool
    {
        return Reference::query()->whereKey($referenceId)->exists();
    }

    private function followable(Reference $reference): FollowableSubject
    {
        return new FollowableSubject(
            id: (string) $reference->getKey(),
            slug: (string) $reference->slug,
            type: 'reference',
            morphType: $reference->getMorphClass(),
            isPubliclyVisible: $reference->isPubliclyVisible(),
        );
    }

    public function find(string $identifier): ?ReferenceSummary
    {
        $entity = $this->entity($identifier);
        if (! $entity instanceof Reference) {
            return null;
        }

        return $this->summary($entity);
    }

    private function summary(Reference $entity): ReferenceSummary
    {
        $fallback = (string) $entity->getKey();
        $status = $entity->getAttribute('status');
        $status = $status instanceof BackedEnum ? (string) $status->value : (filled($status) ? (string) $status : null);
        $entity->getAttribute('type');
        $slug = $entity->getAttribute('slug');
        $image = $entity->hasMedia('front_cover') ? $entity->getFirstMediaUrl('front_cover', 'thumb') : null;

        return new ReferenceSummary(
            id: $fallback,
            title: $entity->displayTitle(),
            slug: filled($slug) ? (string) $slug : null,
            status: $status,
            publicUrl: route('references.show', $entity),
            adminUrl: AdminResourceUrl::edit('references', (string) $entity->getRouteKey()),
            imageUrl: is_string($image) && $image !== '' ? $image : null,
        );
    }

    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $entity = Reference::query()->find($id);

        return $entity instanceof Reference ? $this->reportSubject($entity) : null;
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        $entity = $this->entity($identifier);

        return $entity instanceof Reference ? $this->reportSubject($entity) : null;
    }

    private function entity(string $identifier): ?Reference
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->resolver->first(Reference::query(), 'references.slug', $identifier);
    }

    /**
     * @return array<string, string>
     */
    public function activeTitles(): array
    {
        return Reference::query()
            ->active()
            ->orderBy('title')
            ->pluck('title', 'id')
            ->mapWithKeys(static fn (mixed $title, mixed $id): array => [(string) $id => (string) $title])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function searchOptions(string $search, int $limit = 50): array
    {
        $query = Reference::query()->active();
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->where(function (Builder $referenceQuery) use ($normalizedSearch): void {
                $referenceQuery
                    ->whereLike('title', "%{$normalizedSearch}%")
                    ->orWherePartTextLike("%{$normalizedSearch}%");
            });
        }

        return $query
            ->orderBy('title')
            ->limit(max(1, $limit))
            ->get(['id', 'title', 'parent_id', 'metadata'])
            ->mapWithKeys(static fn (Reference $reference): array => [
                (string) $reference->id => $reference->displayTitle(),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $referenceIds
     * @return array<string, string>
     */
    public function optionLabels(array $referenceIds): array
    {
        if ($referenceIds === []) {
            return [];
        }

        return Reference::query()
            ->active()
            ->whereIn('id', $referenceIds)
            ->orderBy('title')
            ->limit(count($referenceIds))
            ->get(['id', 'title', 'parent_id', 'metadata'])
            ->mapWithKeys(static fn (Reference $reference): array => [
                (string) $reference->id => $reference->displayTitle(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function adminOptions(string $search = '', int $limit = 50): array
    {
        $query = Reference::query()->with('parentReference.parentReference');
        $normalizedSearch = trim($search);

        if ($normalizedSearch !== '') {
            $query->where('title', 'like', '%'.$normalizedSearch.'%');
        }

        return $query
            ->orderBy('title')
            ->limit(max(1, $limit))
            ->get()
            ->mapWithKeys(static fn (Reference $reference): array => [
                (string) $reference->getKey() => $reference->displayTitle(),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $referenceIds
     * @return array<string, string>
     */
    public function adminLabels(array $referenceIds): array
    {
        if ($referenceIds === []) {
            return [];
        }

        return Reference::query()
            ->with('parentReference.parentReference')
            ->whereKey($referenceIds)
            ->get()
            ->mapWithKeys(static fn (Reference $reference): array => [
                (string) $reference->getKey() => $reference->displayTitle(),
            ])
            ->all();
    }

    public function followedReferences(array $referenceIds): array
    {
        return Reference::query()
            ->whereKey($referenceIds)
            ->with(['media'])
            ->orderBy('title')
            ->get()
            ->map(fn (Reference $reference): ReferenceSummary => $this->summary($reference))
            ->all();
    }

    public function referencesByIds(array $referenceIds): array
    {
        return Reference::query()
            ->whereKey($referenceIds)
            ->orderBy('title')
            ->get()
            ->map(fn (Reference $reference): ReferenceSummary => $this->summary($reference))
            ->all();
    }

    public function title(string $referenceId): ?string
    {
        $title = Reference::query()->whereKey($referenceId)->value('title');

        return is_string($title) ? $title : null;
    }

    private function reportSubject(Reference $entity): ReportSubject
    {
        $id = (string) $entity->getKey();

        return new ReportSubject(
            type: ReportSubjectType::Reference,
            id: $id,
            label: __('Reference'),
            title: filled($entity->getAttribute('title')) ? (string) $entity->getAttribute('title') : $id,
            slug: (string) $entity->getAttribute('slug'),
            publicUrl: route('references.show', $entity),
            adminUrl: AdminResourceUrl::edit('references', (string) $entity->getRouteKey()),
            imageUrl: null,
        );
    }
}
