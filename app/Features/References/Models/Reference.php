<?php

namespace App\Features\References\Models;

use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Engagement\Contracts\Followable;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Membership\Traits\HasMembers;
use AIArmada\References\Enums\ReferenceContributorRole;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Models\Reference as PackageReference;
use AIArmada\References\Models\ReferenceContributor;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventReferencePivot;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Models\MemberInvitation;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Models\Person;
use App\Features\References\Api\Enums\ReferencePartType;
use App\Features\References\Api\Enums\ReferenceType;
use App\Features\References\Actions\PrepareReferencePersistenceAction;
use App\Features\References\Support\ReferenceInputValues;
use App\Models\User;
use App\Platform\Auditing\AuditsModelChanges;
use App\Platform\Models\MorphModelResolver;
use Carbon\CarbonImmutable;
use Database\Factories\ReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string $title
 * @property string $slug
 * @property ReferenceType|string|null $type
 * @property string|null $parent_id
 * @property string $record_kind
 * @property int|null $edition_number
 * @property string|null $edition_label
 * @property string|null $isbn
 * @property int|null $year
 * @property string|null $publisher
 * @property string|null $description
 * @property string|null $status
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $last_state_change_at
 * @property string|null $url
 * @property string|null $language
 * @property array<int, array{type: string, value: string|null, label: string|null}>|null $reference_parts
 * @property array<string, mixed>|null $metadata
 */
#[RouteKey('slug')]
class Reference extends PackageReference implements AuditableContract, Followable
{
    use AuditsModelChanges, HasSocialProfiles, KeepsDeletedModels, Searchable;

    /** @var list<string> */
    public const array PUBLIC_STATUSES = ['verified', 'pending'];

    #[\Override]
    protected $table = 'references';

    /** @use HasFactory<ReferenceFactory> */
    use HasFactory;

    /** @use HasMembers<User> */
    use HasMembers;

    #[\Override]
    protected static function newFactory(): ReferenceFactory
    {
        return ReferenceFactory::new();
    }

    #[\Override]
    public function getTable(): string
    {
        return $this->table;
    }

    #[\Override]
    protected static function bootHasSlug(): void {}

    #[\Override]
    protected static function booted(): void
    {
        static::saving(static function (self $reference): void {
            app(PrepareReferencePersistenceAction::class)->handle($reference);
        });

        parent::booted();
    }

    #[\Override]
    protected $fillable = [
        'title',
        'slug',
        'parent_id',
        'type',
        // Virtual part inputs consumed by PrepareReferencePersistenceAction into reference_parts.
        'part_type',
        'part_number',
        'part_label',
        'year',
        'publisher',
        'description',
        'record_kind',
        'edition_number',
        'edition_label',
        'isbn',
        'status',
        'published_at',
        'verified_at',
        'verified_by',
        'rejected_at',
        'last_state_change_at',
        'url',
        'language',
        'reference_parts',
        'metadata',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'edition_number' => 'integer',
            'published_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'reference_parts' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public static function expandReferenceIdsForFiltering(array $referenceIds): array
    {
        $selected = self::query()->active()->whereKey($referenceIds)->get(['id', 'record_kind']);
        $ids = $selected->modelKeys();
        $parents = $selected->reject(fn (self $reference): bool => $reference->isPart())->modelKeys();

        for ($depth = 0; $depth < 2 && $parents !== []; $depth++) {
            $children = self::query()->active()->whereIn('parent_id', $parents)->get(['id', 'record_kind']);
            $ids = [...$ids, ...$children->modelKeys()];
            $parents = $children->reject(fn (self $reference): bool => $reference->isPart())->modelKeys();
        }

        return array_values(array_unique(array_map(strval(...), $ids)));
    }

    /**
     * Scope a query to only include active references.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        self::applyPublicVisibility($query);
    }

    /**
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function applyPublicVisibility(Builder|QueryBuilder $query, ?string $table = null): Builder|QueryBuilder
    {
        $table ??= $query instanceof Builder ? $query->getModel()->getTable() : 'references';

        return $query
            ->whereNotNull($table.'.published_at')
            ->whereIn($table.'.status', self::PUBLIC_STATUSES);
    }

    public function isPubliclyVisible(): bool
    {
        return $this->published_at !== null
            && in_array((string) $this->status, self::PUBLIC_STATUSES, true);
    }

    /**
     * Scope a query to root references and standalone references.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function root(Builder $query): void
    {
        $query->where($query->qualifyColumn('record_kind'), ReferenceRecordKind::Work->value);
    }

    /**
     * Scope a query to child part references.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function part(Builder $query): void
    {
        $query->where($query->qualifyColumn('record_kind'), ReferenceRecordKind::Part->value);
    }

    /**
     * Match part designations stored in the reference_parts JSON payload.
     *
     * @param  Builder<self>  $query
     */
    /**
     * Match author person names, including authors inherited from the root work.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orWhereAuthorNameLike(Builder $query, string $pattern): void
    {
        $contributorsTable = (string) config(
            'references.database.tables.reference_contributors',
            'reference_contributors',
        );
        $table = $query->getModel()->getTable();

        $query->orWhereExists(function (QueryBuilder $exists) use ($pattern, $contributorsTable, $table): void {
            $exists->selectRaw('1')
                ->from($contributorsTable.' as reference_author_link')
                ->join('persons as reference_author_person', 'reference_author_person.id', '=', 'reference_author_link.contributor_id')
                ->leftJoin(
                    $table.' as reference_author_parent',
                    'reference_author_parent.id',
                    '=',
                    $table.'.parent_id',
                )
                ->where('reference_author_link.role', ReferenceContributorRole::Author->value)
                ->where('reference_author_link.contributor_type', app(PersonLookup::class)->personMorphType())
                ->where(function (QueryBuilder $owner) use ($table): void {
                    $owner->whereColumn('reference_author_link.reference_id', $table.'.id')
                        ->orWhereColumn('reference_author_link.reference_id', $table.'.parent_id')
                        ->orWhereColumn('reference_author_link.reference_id', 'reference_author_parent.parent_id');
                })
                ->where(function (QueryBuilder $name) use ($pattern): void {
                    $name->whereLike('reference_author_person.name', $pattern)
                        ->orWhereLike('reference_author_person.middle_name', $pattern)
                        ->orWhereLike('reference_author_person.family_name', $pattern);
                });
        });
    }

    /**
     * Match part text stored on the record.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function orWherePartTextLike(Builder $query, string $pattern): void
    {
        $connection = $query->getModel()->getConnection();
        $column = $connection->getQueryGrammar()->wrap($query->getModel()->qualifyColumn('reference_parts'));
        $driver = $connection->getDriverName();
        $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $expression = match ($driver) {
            'pgsql' => "COALESCE({$column}::text, '')",
            default => "COALESCE(CAST({$column} AS TEXT), '')",
        };

        $query->orWhereRaw("{$expression} {$operator} ?", [$pattern]);
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPubliclyVisible();
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'title',
            'type',
            'reference_parts',
            'parent_id',
            'record_kind',
            'edition_number',
            'edition_label',
            'isbn',
            'language',
            'year',
            'url',
            'publisher',
            'description',
            'slug',
            'status',
            'published_at',
        ]);
    }

    /**
     * @param  Builder<Reference>  $query
     * @return Builder<Reference>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->active()->withEffectiveAuthors();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $updatedAt = $this->updated_at ?? now();
        $publicationYear = $this->year;
        $normalizedPublicationYear = is_numeric($publicationYear) ? (int) $publicationYear : null;
        $description = trim(strip_tags((string) $this->description));

        return [
            'id' => (string) $this->getKey(),
            'title' => (string) $this->titleValue(),
            'authors' => $this->effectiveAuthorNamesList(),
            'type' => $this->typeValue(),
            'parent_id' => $this->parentIdValue(),
            'record_kind' => $this->recordKindValue(),
            'edition_number' => $this->edition_number,
            'edition_label' => $this->edition_label,
            'isbn' => $this->isbn,
            'language' => $this->language,
            'url' => $this->url,
            'part_type' => $this->partTypeValue(),
            'part_number' => $this->partNumberValue(),
            'part_label' => $this->partLabelValue(),
            'display_title' => $this->displayTitle(),
            'publication_year' => $normalizedPublicationYear,
            'publisher' => $this->publisherValue(),
            'description' => $description !== '' ? $description : null,
            'search_text' => $this->searchableText(),
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'published_at' => $this->published_at?->timestamp,
            'updated_at' => $updatedAt->timestamp,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function toScoutDatabaseSearchableArray(): array
    {
        $description = trim(strip_tags((string) $this->description));
        $publicationYear = is_numeric($this->year) ? (int) $this->year : null;

        return array_filter([
            'title' => (string) $this->titleValue(),
            'type' => $this->typeValue(),
            'edition_label' => $this->edition_label,
            'isbn' => $this->isbn,
            'language' => $this->language,
            'year' => $publicationYear,
            'publisher' => $this->publisherValue(),
            'description' => $description !== '' ? $description : null,
            'slug' => (string) $this->slug,
        ], static fn (mixed $value): bool => (is_string($value) && $value !== '') || is_int($value));
    }

    private function usesScoutDatabaseDriver(): bool
    {
        return (string) config('scout.driver') === 'database';
    }

    private function searchableText(): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $this->titleValue()),
            trim($this->displayTitle()),
            trim((string) $this->partLabelValue()),
            trim((string) $this->partNumberValue()),
            $this->effectiveAuthorNames(),
            trim((string) $this->publisherValue()),
            trim((string) $this->edition_label),
            trim((string) $this->edition_number),
            trim((string) $this->isbn),
            trim((string) $this->language),
            trim((string) $this->year),
            trim(strip_tags((string) $this->descriptionValue())),
        ])));
    }

    /**
     * @return BelongsTo<Reference, $this>
     */
    public function parentReference(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Reference, $this>
     */
    public function childReferences(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('title');
    }

    /**
     * Author links stored directly on this record (works only, by convention).
     *
     * @return HasMany<ReferenceContributor, $this>
     */
    public function authorLinks(): HasMany
    {
        return $this->contributorsForRole(ReferenceContributorRole::Author);
    }

    /**
     * Author persons linked directly to this record, stable by contributor ID.
     *
     * The pivot morph columns point at the related contributor (not at this
     * reference), so this is a morphed-by-many from the parent side.
     *
     * @return MorphToMany<Person, $this>
     */
    public function authors(): MorphToMany
    {
        return $this->morphedByMany(
            Person::class,
            'contributor',
            config('references.database.tables.reference_contributors', 'reference_contributors'),
            'reference_id',
            'contributor_id',
        )
            ->wherePivot('role', ReferenceContributorRole::Author->value)
            ->orderByPivot('contributor_id');
    }

    /**
     * Eager-load everything effective-author resolution needs (own links plus
     * the two-level parent chain), so lists never query per row.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withEffectiveAuthors(Builder $query): void
    {
        $query->with([
            'authors.titleAssignments.title.category',
            'parentReference.authors.titleAssignments.title.category',
            'parentReference.parentReference.authors.titleAssignments.title.category',
        ]);
    }

    /**
     * The work that owns this record's authorship (itself for works).
     */
    public function contributorOwner(): self
    {
        if ($this->isRootReference()) {
            return $this;
        }

        $parent = $this->relationLoaded('parentReference')
            ? $this->getRelation('parentReference')
            : $this->parentReference()->first();

        if ($parent instanceof self && $parent->isRootReference()) {
            return $parent;
        }

        $grandparent = $parent instanceof self
            ? ($parent->relationLoaded('parentReference')
                ? $parent->getRelation('parentReference')
                : $parent->parentReference()->first())
            : null;

        if ($grandparent instanceof self && $grandparent->isRootReference()) {
            return $grandparent;
        }

        return $this;
    }

    /**
     * Effective author persons: own links for works, inherited from the root
     * work for editions and parts.
     *
     * @return EloquentCollection<int, Person>
     */
    public function effectiveAuthorPersons(): EloquentCollection
    {
        $owner = $this->contributorOwner();

        if ($owner->relationLoaded('authors')) {
            $authors = $owner->getRelation('authors');

            return $authors instanceof EloquentCollection ? $authors : new EloquentCollection;
        }

        // An unsaved reference cannot have persisted contributor links.
        // Do not query the pivot with a provisional (possibly non-UUID) ID.
        if (! $owner->exists) {
            return new EloquentCollection;
        }

        return $owner->authors()->with('titleAssignments.title.category')->get();
    }

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    public function effectiveAuthorsStructured(): array
    {
        return $this->effectiveAuthorPersons()
            ->map(static function (Person $person): array {
                $formatted = trim((string) $person->formatted_name);

                return [
                    'id' => (string) $person->getKey(),
                    'name' => $formatted !== '' ? $formatted : trim((string) $person->name),
                    'slug' => (string) $person->slug,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function effectiveAuthorNamesList(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $author): string => trim((string) $author['name']), $this->effectiveAuthorsStructured()),
            static fn (string $name): bool => $name !== '',
        ));
    }

    public function effectiveAuthorNames(): string
    {
        return implode(', ', $this->effectiveAuthorNamesList());
    }

    /**
     * Author person IDs stored directly on this record, stable by contributor ID.
     *
     * @return list<string>
     */
    public function authorIdsValue(): array
    {
        if ($this->relationLoaded('authorLinks')) {
            $links = $this->getRelation('authorLinks');

            return $links instanceof EloquentCollection
                ? $links->map(static fn (Model $link): string => (string) $link->getAttribute('contributor_id'))->values()->all()
                : [];
        }

        return $this->authorLinks()
            ->pluck('contributor_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    public function recordKindValue(): string
    {
        return (string) ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'record_kind');
    }

    public function isPart(): bool
    {
        return $this->recordKindValue() === ReferenceRecordKind::Part->value;
    }

    public function isEdition(): bool
    {
        return $this->recordKindValue() === ReferenceRecordKind::Edition->value;
    }

    public function isRootReference(): bool
    {
        return $this->recordKindValue() === ReferenceRecordKind::Work->value;
    }

    public function familyRootId(): ?string
    {
        if ($this->isRootReference()) {
            return $this->exists ? (string) $this->getKey() : null;
        }

        $parent = $this->parentReference;

        return $parent?->isRootReference() ? (string) $parent->getKey() : $parent?->parentIdValue();
    }

    /** @return list<string> */
    public function familyReferenceIds(): array
    {
        $rootId = $this->familyRootId();

        return $rootId === null ? [] : self::expandReferenceIdsForFiltering([$rootId]);
    }

    /** @return list<string> */
    public function defaultEventReferenceIds(): array
    {
        return $this->exists ? self::expandReferenceIdsForFiltering([(string) $this->getKey()]) : [];
    }

    public function editionDisplayLabel(): string
    {
        $label = trim((string) $this->edition_label);
        if ($label === '') {
            $label = $this->edition_number !== null ? __('Cetakan :number', ['number' => $this->edition_number]) : __('Edisi');
        }
        $details = array_filter([$this->publisherValue(), $this->year]);

        return $details === [] ? $label : $label.' ('.implode(', ', $details).')';
    }

    public function displayTitle(): string
    {
        $title = trim((string) $this->titleValue());
        if ($this->isRootReference()) {
            return $title;
        }

        $labels = [];
        if ($this->isPart() && $this->parentReference?->isEdition()) {
            $labels[] = $this->parentReference->editionDisplayLabel();
        }
        $labels[] = $this->isEdition() ? $this->editionDisplayLabel() : $this->resolvedPartLabel();
        foreach (array_filter($labels) as $label) {
            if (! str_contains(mb_strtolower($title), mb_strtolower($label))) {
                $title .= ' — '.$label;
            }
        }

        return $title;
    }

    public function getDisplayTitleAttribute(): string
    {
        return $this->displayTitle();
    }

    public function followableName(): string
    {
        return $this->displayTitle();
    }

    public function followableUrl(): ?string
    {
        return route('references.show', ['reference' => $this->slug]);
    }

    public function followableImage(): ?string
    {
        return null;
    }

    public function defaultFollowNotificationLevel(): ?string
    {
        return null;
    }

    public function typeValue(): ?string
    {
        return ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'type');
    }

    public function parentIdValue(): ?string
    {
        return ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'parent_id');
    }

    public function partTypeValue(): ?string
    {
        return ReferenceInputValues::normalizeStringValue($this->primaryPartEntry()['type'] ?? null);
    }

    public function partNumberValue(): ?string
    {
        return ReferenceInputValues::normalizeStringValue($this->primaryPartEntry()['value'] ?? null);
    }

    public function partLabelValue(): ?string
    {
        return ReferenceInputValues::normalizeStringValue($this->primaryPartEntry()['label'] ?? null);
    }

    /**
     * A child reference designates exactly one part of its parent book.
     *
     * @return array{type?: mixed, value?: mixed, label?: mixed}
     */
    private function primaryPartEntry(): array
    {
        $parts = $this->getAttributes()['reference_parts'] ?? null;

        if (is_string($parts)) {
            $decoded = json_decode($parts, true);
            $parts = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($parts)) {
            return [];
        }

        $first = $parts[0] ?? null;

        return is_array($first) ? $first : [];
    }

    public function titleValue(): ?string
    {
        return ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'title');
    }

    public function publisherValue(): ?string
    {
        return ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'publisher');
    }

    public function descriptionValue(): ?string
    {
        return ReferenceInputValues::optionalStringAttribute($this->getAttributes(), 'description');
    }

    private function resolvedPartLabel(): string
    {
        $partLabel = trim((string) $this->partLabelValue());

        if ($partLabel !== '') {
            return $partLabel;
        }

        $partType = ReferencePartType::tryFrom((string) $this->partTypeValue());
        $partNumber = trim((string) $this->partNumberValue());

        if (! $partType instanceof ReferencePartType) {
            return $partNumber;
        }

        $label = $partType->getLabel();

        return $partNumber !== '' ? "{$label} {$partNumber}" : $label;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(MorphModelResolver::resolve('user'), 'verified_by');
    }

    /**
     * @return MorphToMany<Event, $this, EventReferencePivot, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->morphToMany(
            MorphModelResolver::resolve('event'),
            'referenceable',
            config('events.database.tables.event_references', 'event_references'),
            'referenceable_id',
            'event_id',
        )
            ->using(EventReferencePivot::class)
            ->withPivot(['id', 'sort_order', 'visibility', 'reference_type', 'title'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return HasMany<MemberInvitation, $this>
     */
    public function memberInvitations(): HasMany
    {
        return $this->hasMany(MorphModelResolver::resolve('member_invitation'), 'subject_id')
            ->where('subject_type', MemberSubjectType::Reference->value);
    }

    /**
     * @return MorphMany<Model, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(MorphModelResolver::resolve('report'), 'entity');
    }

    /**
     * Register media collections for Spatie Media Library.
     */
    #[\Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('front_cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('back_cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('gallery')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages();
    }

    /**
     * Register media conversions for optimized image delivery.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('front_cover', 'back_cover')
            ->fit(Fit::Crop, 1080, 1440)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('gallery_thumb')
            ->performOnCollections('gallery')
            ->fit(Fit::Max, 1080, 1080)
            ->sharpen(10)
            ->format('webp');
    }

    /**
     * @return MorphMany<Follow, $this>
     */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    public function followersCount(): int
    {
        return $this->follows()->active()->count();
    }
}
