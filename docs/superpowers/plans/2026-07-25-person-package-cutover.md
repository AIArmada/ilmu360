# Person Package Cutover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace custom Person implementation with `aiarmada/persons` + `aiarmada/filament-persons` packages. No backward compatibility, no legacy data.

**Architecture:** `app/Models/Person` extends `AIArmada\Persons\Models\Person`, layering 11 app-specific traits. Package owns identity core (names, titles, credentials, affiliations). App owns everything else (media, members, follows, events, contacts, addresses, search, moderation).

**Tech Stack:** Laravel 13, Filament v5, PHP 8.5, spatie/laravel-medialibrary, laravel/scout, owen-it/auditing, spatie/deleted-models

## Global Constraints

- `aiarmada/persons` at `dev-main` from local path `~/herd/commerce/packages/persons`
- `aiarmada/filament-persons` at `dev-main` from local path `~/herd/commerce/packages/filament-persons`
- PHP ^8.4
- Zero FK constraints, zero soft deletes, zero cascades
- `$table->uuid('id')->primary()` for all tables
- `foreignUuid()` for all FK columns
- No backward compatibility — remove old code, don't shim
- No data backfill — clean database start

---

### Task 1: Add Gender enum to aiarmada/persons package

**Files:**
- Create: `~/herd/commerce/packages/persons/src/Enums/Gender.php`
- Modify: `~/herd/commerce/packages/persons/src/Models/Person.php`

**Interfaces:**
- Produces: `AIArmada\Persons\Enums\Gender` — string-backed enum with `Male = 'male'`, `Female = 'female'`

- [ ] **Step 1: Create the Gender enum file**

```php
<?php

declare(strict_types=1);

namespace AIArmada\Persons\Enums;

use AIArmada\Commerce\Support\Enums\HasLabelOptions;

enum Gender: string
{
    use HasLabelOptions;

    case Male = 'male';
    case Female = 'female';
}
```

- [ ] **Step 2: Add Gender cast to package Person model**

In `~/herd/commerce/packages/persons/src/Models/Person.php`, add the import:

```php
use AIArmada\Persons\Enums\Gender;
```

Update `casts()`:

```php
protected function casts(): array
{
    return [
        'date_of_birth' => 'immutable_date',
        'bio' => 'array',
        'gender' => Gender::class,
    ];
}
```

- [ ] **Step 3: Commit to persons package**

```bash
cd ~/herd/commerce
git add packages/persons/src/Enums/Gender.php packages/persons/src/Models/Person.php
git commit -m "feat: add Gender enum"
```

---

### Task 2: Update filament-persons PersonForm to use Gender enum

**Files:**
- Modify: `~/herd/commerce/packages/filament-persons/src/Resources/PersonResource/Schemas/PersonForm.php`

- [ ] **Step 1: Replace gender Select options**

Read the file, find the gender `Select::make('gender')` field. Replace the hardcoded options array `['male' => 'Male', 'female' => 'Female']` with `->options(Gender::class)`.

Add import:
```php
use AIArmada\Persons\Enums\Gender;
```

If there's a `->default()` or `->required()`, leave those.

- [ ] **Step 2: Commit**

```bash
cd ~/herd/commerce
git add packages/filament-persons/src/Resources/PersonResource/Schemas/PersonForm.php
git commit -m "feat: use Gender enum in PersonForm"
```

---

### Task 3: Add packages to ilmu360 composer.json

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add path repositories and package requirements**

Add to `composer.json` under `require` (alphabetically near other `aiarmada/*` packages):
```json
"aiarmada/filament-persons": "dev-main",
"aiarmada/persons": "dev-main",
```

Add to `repositories`:
```json
{
    "type": "path",
    "url": "~/herd/commerce/packages/persons"
},
{
    "type": "path",
    "url": "~/herd/commerce/packages/filament-persons"
}
```

- [ ] **Step 2: Install**

```bash
composer update aiarmada/persons aiarmada/filament-persons --no-interaction
```

- [ ] **Step 3: Verify**

```bash
composer show aiarmada/persons aiarmada/filament-persons
```

Expected: Both show `dev-main`.

---

### Task 4: Publish configs and configure model swap

**Files:**
- Publish: `config/persons.php`, `config/filament-persons.php`

- [ ] **Step 1: Publish**

```bash
php artisan vendor:publish --tag=persons-config --no-interaction
php artisan vendor:publish --tag=filament-persons-config --no-interaction
```

- [ ] **Step 2: Configure model swap in config/persons.php**

```php
'models' => [
    'person' => \App\Models\Person::class,
    'country' => \AIArmada\Addressing\Models\AddressCountry::class,
    'institution' => null,
],
```

- [ ] **Step 3: Disable package PersonResource in config/filament-persons.php**

We extend the package resource ourselves:
```php
'resources' => [
    'enabled' => [
        'person' => false,
        'title' => true,
        'title_issuer' => true,
        'credential_definition' => true,
    ],
],
```

Also set navigation group to match existing convention:
```php
'navigation' => [
    'group' => 'Directory',
],
```

- [ ] **Step 4: Verify**

```bash
php artisan tinker --execute 'echo config("persons.models.person");'
```

Expected: `App\Models\Person`

---

### Task 5: Run package migrations + create app migration

**Files:**
- Create: `database/migrations/<timestamp>_add_app_columns_to_persons_table.php`
- Create: `database/migrations/<timestamp>_create_person_search_terms_table.php`

- [ ] **Step 1: Run package migrations**

```bash
php artisan migrate --no-interaction
```

The package creates 11 tables: `persons`, `person_names`, `title_categories`, `titles`, `title_issuers`, `title_assignments`, `credential_definitions`, `credential_assignments`, `affiliations`, `affiliation_roles`, `languages`.

- [ ] **Step 2: Verify tables exist**

```bash
php artisan tinker --execute 'echo collect(Schema::getTables())->pluck("name")->implode(", ")'
```

Expected: sees `persons`, `person_names`, `title_categories`, etc.

- [ ] **Step 3: Create app columns migration**

```bash
php artisan make:migration add_app_columns_to_persons_table --no-interaction
```

Write the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('searchable_name', 512)->nullable()->index();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampTz('last_state_change_at')->nullable();
            $table->boolean('allow_public_event_submission')->default(true);
            $table->timestampTz('public_submission_locked_at')->nullable();
            $table->foreignUuid('public_submission_locked_by')->nullable();

            $table->index(['status', 'name'], 'persons_status_name');
            $table->index(['gender', 'status', 'name'], 'persons_gender_status_name');
            $table->index('updated_at', 'persons_sitemap');
        });
    }
};
```

Note: The package migration already creates the `persons` table with: `id`, `name`, `family_name`, `middle_name`, `gender`, `date_of_birth`, `nationality_country_id`, `slug`, `searchable_name`, `bio`, `status`, `timestampsTz()`. Our migration just adds the app-specific columns on top.

- [ ] **Step 4: Create person_search_terms migration**

```bash
php artisan make:migration create_person_search_terms_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_search_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->index();
            $table->string('term', 120)->index();
            $table->index(['person_id', 'term']);
        });
    }
};
```

- [ ] **Step 5: Run app migrations**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 6: Verify columns**

```bash
php artisan tinker --execute 'print_r(Schema::getColumnListing("persons"))'
```

Expected: includes all app columns (`verified_at`, `rejected_at`, `inactive_at`, `last_state_change_at`, `allow_public_event_submission`, etc.)

---

### Task 6: Rewrite app/Models/Person.php

**Files:**
- Rewrite: `app/Models/Person.php`

**Approach:** Extend `AIArmada\Persons\Models\Person`. Add all 11 app traits. The package already provides `HasTitles`, `HasCredentials`, `HasAffiliations`, `HasUuids`, `HasFactory`, `names()`, `formatted_name` accessor. The app builds on top with media, members, follows, events, contacts, addresses, search, moderation.

- [ ] **Step 1: Remove old Person model, create new one**

```bash
rm app/Models/Person.php
```

- [ ] **Step 2: Write new app/Models/Person.php**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Contacting\Concerns\HasSocialProfiles;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Membership\Models\MembershipInvitation;
use AIArmada\Membership\Traits\HasMembers;
use AIArmada\Persons\Enums\Gender;
use AIArmada\Persons\Models\Person as BasePerson;
use App\Enums\EventKeyPersonRole;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasDonationChannels;
use App\Models\Concerns\HasLanguages;
use Carbon\CarbonInterface;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @use HasFactory<PersonFactory>
 * @use HasMembers<User>
 */
class Person extends BasePerson implements AuditableContract, HasMedia
{
    public const string PUBLIC_DIRECTORY_SESSION_KEY = 'public_persons_directory_seed';

    use AuditsModelChanges;
    use HasAddresses;
    use HasContactMethods;
    use HasDonationChannels;
    use HasLanguages;
    use HasMembers;
    use HasSocialProfiles;
    use InteractsWithMedia;
    use KeepsDeletedModels;
    use Searchable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'family_name',
        'middle_name',
        'gender',
        'date_of_birth',
        'nationality_country_id',
        'slug',
        'searchable_name',
        'bio',
        'status',
        'verified_at',
        'verified_by',
        'rejected_at',
        'inactive_at',
        'last_state_change_at',
        'allow_public_event_submission',
        'public_submission_locked_at',
        'public_submission_locked_by',
    ];

    public static function getResourceKey(): string
    {
        return 'person';
    }

    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'inactive_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'allow_public_event_submission' => 'boolean',
            'public_submission_locked_at' => 'datetime',
        ]);
    }

    public static function formatDisplayedName(string $name): string
    {
        return trim($name);
    }

    // ===== Scout Search =====

    public function shouldBeSearchable(): bool
    {
        return in_array((string) $this->status, ['verified', 'pending'], true);
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'name', 'slug', 'status', 'gender',
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->whereIn('persons.status', ['verified', 'pending']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        if ($this->usesScoutDatabaseDriver()) {
            return $this->toScoutDatabaseSearchableArray();
        }

        $address = $this->primaryAddress();
        $updatedAt = $this->updated_at ?? now();

        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'formatted_name' => $this->formatted_name,
            'slug' => (string) $this->slug,
            'status' => (string) $this->status,
            'gender' => $this->gender instanceof Gender ? $this->gender->value : null,
            'country_code' => $address?->country_code,
            'city' => $address?->city,
            'state' => $address?->state,
            'postcode' => $address?->postcode,
            'updated_at' => $updatedAt->timestamp,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function toScoutDatabaseSearchableArray(): array
    {
        return array_filter([
            'name' => (string) $this->name,
            'searchable_name' => filled($this->searchable_name) ? (string) $this->searchable_name : null,
            'slug' => (string) $this->slug,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function usesScoutDatabaseDriver(): bool
    {
        return (string) config('scout.driver') === 'database';
    }

    // ===== Lifecycle =====

    #[\Override]
    protected static function booted(): void
    {
        static::saving(function (Person $person) {
            if ($person->isDirty('status')) {
                $now = now();
                $person->last_state_change_at = $now;

                match ((string) $person->status) {
                    'verified' => $person->verified_at ??= $now,
                    'rejected' => $person->rejected_at ??= $now,
                    'inactive' => $person->inactive_at ??= $now,
                    default => null,
                };

                if ((string) $person->status === 'verified') {
                    $person->verified_by ??= auth()->id();
                }
            }
        });
    }

    // ===== Media Accessors =====

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->hasMedia('avatar')) {
            return $this->getFirstMediaUrl('avatar', 'thumb');
        }

        return null;
    }

    public function getPublicAvatarUrlAttribute(): string
    {
        if ($this->hasMedia('avatar')) {
            $avatarMedia = $this->getFirstMedia('avatar');

            if ($avatarMedia instanceof Media) {
                return $avatarMedia->getAvailableUrl(['profile', 'thumb']) ?: $avatarMedia->getUrl();
            }
        }

        return $this->default_avatar_url;
    }

    public function getPublicMainUrlAttribute(): string
    {
        if ($this->hasMedia('main')) {
            $mainMedia = $this->getFirstMedia('main');

            if ($mainMedia instanceof Media) {
                return $mainMedia->getAvailableUrl(['card', 'main_thumb']) ?: $mainMedia->getUrl();
            }
        }

        return asset('images/placeholders/person.png');
    }

    public function getDefaultAvatarUrlAttribute(): string
    {
        if ($this->avatar_url) {
            return $this->avatar_url;
        }

        if ($this->gender instanceof Gender && $this->gender === Gender::Female) {
            return asset('images/placeholders/person-female.png');
        }

        return asset('images/placeholders/person-male.png');
    }

    // ===== Relationships =====

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<AddressCountry, $this>
     */
    public function nationality(): BelongsTo
    {
        return $this->belongsTo(AddressCountry::class, 'nationality_country_id');
    }

    /**
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_involvements', 'involveable_id', 'event_id')
            ->using(EventKeyPersonPivot::class)
            ->wherePivot('involveable_type', 'person')
            ->withPivot(['id', 'involveable_type', 'role_code', 'sort_order', 'notes'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * @return BelongsToMany<Event, $this, EventKeyPersonPivot, 'pivot'>
     */
    public function personEvents(): BelongsToMany
    {
        return $this->events()
            ->wherePivot('role_code', EventKeyPersonRole::Speaker->value)
            ->withPivotValue('role_code', EventKeyPersonRole::Speaker->value);
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function eventKeyPeople(): HasMany
    {
        return $this->hasMany(EventKeyPerson::class, 'involveable_id')
            ->where('involveable_type', 'person');
    }

    /**
     * @return HasMany<EventKeyPerson, $this>
     */
    public function nonSpeakerEventKeyPeople(): HasMany
    {
        return $this->eventKeyPeople()
            ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
            ->where('visibility', 'public')
            ->orderBy('sort_order');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'entity');
    }

    /**
     * @return MorphMany<MembershipInvitation, $this>
     */
    public function memberInvitations(): MorphMany
    {
        return $this->invitations();
    }

    /**
     * @return MorphToMany<Institution, $this>
     */
    public function institutions(): MorphToMany
    {
        return $this->morphToMany(Institution::class, 'affiliatable', 'affiliations')
            ->withPivot(['position', 'is_primary'])
            ->withTimestamps();
    }

    // ===== Relationships from package (using package models) =====

    /**
     * @return MorphMany<\AIArmada\Persons\Models\TitleAssignment, $this>
     */
    public function titleAssignments(): MorphMany
    {
        return $this->morphMany(\AIArmada\Persons\Models\TitleAssignment::class, 'titleable');
    }

    /**
     * @return MorphMany<\AIArmada\Persons\Models\CredentialAssignment, $this>
     */
    public function credentialAssignments(): MorphMany
    {
        return $this->morphMany(\AIArmada\Persons\Models\CredentialAssignment::class, 'credentialable');
    }

    /**
     * @return MorphMany<\AIArmada\Persons\Models\Affiliation, $this>
     */
    public function affiliations(): MorphMany
    {
        return $this->morphMany(\AIArmada\Persons\Models\Affiliation::class, 'affiliatable');
    }

    /**
     * @return HasMany<\AIArmada\Persons\Models\PersonName, $this>
     */
    public function names(): HasMany
    {
        return $this->hasMany(\AIArmada\Persons\Models\PersonName::class, 'person_id');
    }

    // ===== Follows =====

    /**
     * @return MorphMany<Follow, $this>
     */
    public function follows(): MorphMany
    {
        return $this->morphMany(Follow::class, 'followable');
    }

    /**
     * @return MorphToMany<User, $this>
     */
    public function followers(): MorphToMany
    {
        $table = (new Follow)->getTable();

        return $this->morphToMany(User::class, 'followable', $table, 'followable_id', 'follower_id')
            ->where("{$table}.status", 'active');
    }

    public function followersCount(): int
    {
        return $this->follows()->active()->count();
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $this->follows()->active()->where('follower_id', $user->getKey())->exists();
    }

    // ===== Scopes =====

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('persons.status', ['verified', 'pending']);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function publicDirectoryOrder(Builder $query, ?string $sessionSeed = null): void
    {
        $offset = self::publicDirectoryOrderOffset($sessionSeed ?? self::publicDirectorySessionSeed());
        $idExpression = self::publicDirectoryOrderIdExpression($query);

        $query->orderByRaw("substr({$idExpression}, {$offset}, 32)")
            ->orderByRaw($idExpression.' asc');
    }

    /**
     * @param  Builder<self>  $query
     */
    private function publicDirectoryOrderIdExpression(Builder $query): string
    {
        $driver = DB::connection($query->getModel()->getConnectionName())->getDriverName();

        return $driver === 'pgsql'
            ? "replace(cast(persons.id as text), '-', '')"
            : "replace(persons.id, '-', '')";
    }

    public static function publicDirectoryOrderOffset(?string $sessionSeed = null, ?CarbonInterface $at = null): int
    {
        if (is_string($sessionSeed) && $sessionSeed !== '') {
            return (abs(crc32($sessionSeed)) % 24) + 1;
        }

        return (((int) ($at ?? now())->format('z')) % 24) + 1;
    }

    /**
     * @return array{primary: string, secondary: string}
     */
    public static function publicDirectorySortParts(string $personId, ?string $sessionSeed = null, ?CarbonInterface $at = null): array
    {
        $normalizedId = str_replace('-', '', $personId);
        $offset = self::publicDirectoryOrderOffset($sessionSeed, $at);

        return [
            'primary' => substr($normalizedId, $offset - 1),
            'secondary' => $normalizedId,
        ];
    }

    public static function publicDirectorySessionSeed(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $seed = $session->get(self::PUBLIC_DIRECTORY_SESSION_KEY);

        if (is_string($seed) && $seed !== '') {
            return $seed;
        }

        $seed = (string) Str::uuid();
        $session->put(self::PUBLIC_DIRECTORY_SESSION_KEY, $seed);

        return $seed;
    }

    // ===== Media Collections =====

    #[\Override]
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->singleFile();

        $this->addMediaCollection('main')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('cover')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useFallbackUrl(asset('images/placeholders/person.png'))
            ->withResponsiveImages()
            ->singleFile();

        $this->addMediaCollection('gallery')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->withResponsiveImages();

        $this->addMediaCollection('documents')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('certificates')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
    }

    #[\Override]
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('avatar')
            ->width(1080)
            ->height(1080)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('profile')
            ->performOnCollections('avatar')
            ->width(1080)
            ->height(1080)
            ->format('webp');

        $this->addMediaConversion('card')
            ->performOnCollections('main')
            ->width(640)
            ->height(853)
            ->format('webp');

        $this->addMediaConversion('main_thumb')
            ->performOnCollections('main')
            ->width(1080)
            ->height(1080)
            ->sharpen(10)
            ->format('webp');

        $this->addMediaConversion('banner')
            ->performOnCollections('cover')
            ->fit(Fit::Crop, 1920, 1080)
            ->format('webp');

        $this->addMediaConversion('gallery_thumb')
            ->performOnCollections('gallery')
            ->fit(Fit::Crop, 1920, 1080)
            ->sharpen(10)
            ->format('webp');
    }
}
```

Key point: The package Person already provides `formatted_name` accessor and `HasTitles`/`HasCredentials`/`HasAffiliations` traits. We override `titleAssignments()`, `credentialAssignments()`, `affiliations()`, and `names()` to point to the package model classes (FQN references). We keep `institutions()`, `nationality()`, and all event/follow/member/report relationships as app-specific.

- [ ] **Step 3: Verify model resolves correctly**

```bash
php artisan tinker --execute 'echo get_class(resolve(\AIArmada\Persons\Support\ModelResolver::class)->personClass());'
```

Expected: `App\Models\Person`

```bash
php artisan tinker --execute 'echo get_parent_class(\App\Models\Person::class);'
```

Expected: `AIArmada\Persons\Models\Person`

---

### Task 7: Register FilamentPersonsPlugin in panel providers

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `app/Providers/Filament/AhliPanelProvider.php`

- [ ] **Step 1: AdminPanelProvider — add plugin and import**

Add import:
```php
use AIArmada\FilamentPersons\FilamentPersonsPlugin;
```

Add to the `->plugins([])` array:
```php
FilamentPersonsPlugin::make(),
```

- [ ] **Step 2: AhliPanelProvider — add plugin and import**

Same import and plugin addition.

- [ ] **Step 3: Verify plugin registers**

```bash
php artisan tinker --execute 'echo get_class(app(\AIArmada\FilamentPersons\FilamentPersonsPlugin::class));'
```

---

### Task 8: Rewrite app Filament PersonResource to extend package

**Files:**
- Rewrite: `app/Filament/Resources/Persons/PersonResource.php`

- [ ] **Step 1: Rewrite PersonResource**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons;

use AIArmada\FilamentPersons\Resources\PersonResource as PackagePersonResource;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Persons\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Persons\Schemas\PersonForm;
use App\Filament\Resources\Persons\Tables\PersonsTable;
use App\Models\Person;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PersonResource extends PackagePersonResource
{
    protected static ?string $model = Person::class;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return PersonForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PersonsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ...parent::getRelations(),
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            FollowersRelationManager::class,
            RelationManagers\InstitutionsRelationManager::class,
            EventsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }
}
```

`parent::getRelations()` returns 4 package relation managers: `NamesRelationManager`, `TitleAssignmentsRelationManager`, `CredentialAssignmentsRelationManager`, `AffiliationsRelationManager`. We add our 6 app-specific ones.

---

### Task 9: Update Ahli PersonResource

**Files:**
- Rewrite: `app/Filament/Ahli/Resources/Persons/PersonResource.php`

- [ ] **Step 1: Rewrite Ahli PersonResource**

```php
<?php

namespace App\Filament\Ahli\Resources\Persons;

use App\Filament\Ahli\Resources\Persons\Pages\EditPerson;
use App\Filament\Ahli\Resources\Persons\Pages\ListPersons;
use App\Filament\Ahli\Resources\Persons\Pages\ViewPerson;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Persons\PersonResource as AdminPersonResource;
use App\Filament\Resources\Persons\RelationManagers\MemberInvitationsRelationManager;
use App\Models\Person;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PersonResource extends AdminPersonResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'persons';

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->toolbarActions([]);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'persons.id',
            $user->persons()->select('persons.id')
        );
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPersons::route('/'),
            'view' => ViewPerson::route('/{record}'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }
}
```

---

### Task 10: Update PersonForm — use Gender enum from package

**Files:**
- Modify: `app/Filament/Resources/Persons/Schemas/PersonForm.php`

- [ ] **Step 1: Change Gender import**

Replace:
```php
use App\Enums\Gender;
```
With:
```php
use AIArmada\Persons\Enums\Gender;
```

- [ ] **Step 2: Remove `->default(Gender::Male->value)`**

The field already has `->options(Gender::class)` and `->required()`. The default of `Male` is opinionated. Keep or drop based on existing behavior.

The current code has:
```php
Select::make('gender')
    ->label(__('Gender'))
    ->options(Gender::class)
    ->default(Gender::Male->value)
    ->required(),
```

Keep everything the same, just the import changes. This is the only change.

- [ ] **Step 3: Verify no syntax errors**

```bash
php -l app/Filament/Resources/Persons/Schemas/PersonForm.php
```

---

### Task 11: Update PersonsTable — adapt to package model

**Files:**
- Modify: `app/Filament/Resources/Persons/Tables/PersonsTable.php`

The current table is already self-contained (no dependency on the old Person model's custom columns beyond what we still have). The `avatar`, `name`, `followers_count`, `status`, `email`, `phone`, `created_at` columns all still work with the new model.

No changes needed to PersonsTable.

---

### Task 12: Remove old files

- [ ] **Step 1: Delete app enums replaced by package**

```bash
rm app/Enums/PersonNameType.php
rm app/Enums/Gender.php
```

The package provides `AIArmada\Persons\Enums\PersonNameType` and `AIArmada\Persons\Enums\Gender`.

- [ ] **Step 2: Delete app models replaced by package**

```bash
rm app/Models/PersonName.php
```

Note: Check if `app/Models/TitleAssignment.php`, `app/Models/CredentialAssignment.php`, `app/Models/Affiliation.php`, `app/Models/AffiliationRole.php` exist. If they do, remove them too. The package provides these.

- [ ] **Step 3: Delete app relation managers replaced by package**

```bash
rm app/Filament/Resources/Persons/RelationManagers/NamesRelationManager.php
rm app/Filament/Resources/Persons/RelationManagers/TitlesRelationManager.php
rm app/Filament/Resources/Persons/RelationManagers/CredentialAssignmentsRelationManager.php
rm app/Filament/Resources/Persons/RelationManagers/AffiliationsRelationManager.php
```

- [ ] **Step 4: Delete old migrations**

```bash
rm database/migrations/2026_01_10_000010_create_persons_table.php
rm database/migrations/2026_07_23_212624_create_person_names_table.php
```

- [ ] **Step 5: Update all remaining imports across the codebase**

Run a sweep to find remaining references to removed classes:

```bash
rg -l "App\\\\Enums\\\\PersonNameType" app/ tests/ database/ resources/
rg -l "App\\\\Enums\\\\Gender" app/ tests/ database/ resources/
rg -l "App\\\\Models\\\\PersonName" app/ tests/ database/ resources/
```

For each file found, replace:
- `App\Enums\PersonNameType` → `AIArmada\Persons\Enums\PersonNameType`
- `App\Enums\Gender` → `AIArmada\Persons\Enums\Gender`
- `App\Models\PersonName` → `AIArmada\Persons\Models\PersonName`

---

### Task 13: Adapt app actions and services

**Files to check and adapt:**
- `app/Actions/Persons/SavePersonAction.php`
- `app/Actions/Persons/GeneratePersonSlugAction.php`
- `app/Services/ContributionEntityMutationService.php`
- `app/Support/Search/PersonSearchService.php`
- `app/Observers/PersonObserver.php`

- [ ] **Step 1: Read each file and adapt field references**

Key changes needed:
1. Any reference to `App\Models\Person` should still work (it's the same class name, just extends differently now)
2. Any reference to `App\Models\PersonName` should change to `AIArmada\Persons\Models\PersonName`
3. Any reference to `App\Enums\PersonNameType` should change to `AIArmada\Persons\Enums\PersonNameType`
4. Any reference to `App\Enums\Gender` should change to `AIArmada\Persons\Enums\Gender`
5. The Person model now has `middle_name` field — add it to fillable/field mapping
6. The `gender` column is now cast to an enum — string comparisons like `$person->gender === 'female'` should become `$person->gender === Gender::Female`

- [ ] **Step 2: Run syntax check on all modified files**

```bash
php -l app/Actions/Persons/SavePersonAction.php
php -l app/Actions/Persons/GeneratePersonSlugAction.php
php -l app/Services/ContributionEntityMutationService.php
php -l app/Support/Search/PersonSearchService.php
php -l app/Observers/PersonObserver.php
```

---

### Task 14: Adapt API DTOs and form schemas

**Files:**
- `app/Data/Api/Frontend/Search/PersonDetailData.php`
- `app/Data/Api/Frontend/Search/PersonListData.php`
- `app/Data/Api/Frontend/Search/PersonDetailMediaData.php`
- `app/Data/Api/Frontend/Search/PersonInstitutionData.php`
- `app/Data/Api/Frontend/Search/PersonGalleryItemData.php`
- `app/Data/Api/Frontend/Search/EventListPersonData.php`
- `app/Data/Api/Event/EventPersonData.php`
- `app/Forms/PersonFormSchema.php`
- `app/Forms/PersonContributionFormSchema.php`

- [ ] **Step 1: Read each DTO and update field references**

The Person model field names haven't changed (`name`, `family_name`, `gender`, etc.). New field `middle_name` is available. All existing DTO fields should still resolve.

For gender: since it's now cast to `Gender` enum, DTOs that read `$person->gender` may now get an enum instance instead of a string. Ensure consistent output.

- [ ] **Step 2: Update form schemas**

In `PersonFormSchema.php` and `PersonContributionFormSchema.php`, update if they reference removed classes like `App\Enums\Gender` or `App\Models\PersonName`.

---

### Task 15: Adapt Livewire components, controllers, factories, seeders

**Files:**
- `app/Livewire/Pages/Persons/Show.php`
- `app/Livewire/Pages/Contributions/SubmitPerson.php`
- `app/Http/Controllers/Api/Frontend/SearchController.php`
- `app/Http/Controllers/Api/Frontend/ContributionController.php`
- `app/Http/Controllers/Api/Frontend/FollowController.php`
- `app/Http/Controllers/SitemapController.php`
- `database/factories/PersonFactory.php`
- `database/seeders/PersonSeeder.php`

- [ ] **Step 1: Update factory to extend package factory**

Read `database/factories/PersonFactory.php`. Change it to extend the package factory and add app-specific columns:

```php
<?php

namespace Database\Factories;

use AIArmada\Persons\Enums\Gender;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \AIArmada\Persons\Database\Factories\PersonFactory<Person>
 */
class PersonFactory extends \AIArmada\Persons\Database\Factories\PersonFactory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return array_merge(parent::definition(), [
            'allow_public_event_submission' => true,
        ]);
    }
}
```

- [ ] **Step 2: Update Livewire components**

Read each file and adapt:
- Person model field access (gender is now enum — use `->value` or enum comparison)
- Import changes for removed classes

- [ ] **Step 3: Update controllers**

Similar import and field access adaptations.

---

### Task 16: Run PHPStan and fix errors

- [ ] **Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse --ansi
```

- [ ] **Step 2: Fix all Person-related errors**

Common issues:
- Wrong enum class referenced
- Wrong model class referenced for relationships
- Gender enum comparison patterns

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --format agent
```

---

### Task 17: Delete old Person resource pages (if needed)

The package PersonResource comes with its own ListPersons, CreatePerson, EditPerson, ViewPerson pages. Our app had its own:

- `app/Filament/Resources/Persons/Pages/ListPersons.php`
- `app/Filament/Resources/Persons/Pages/CreatePerson.php`
- `app/Filament/Resources/Persons/Pages/EditPerson.php`
- `app/Filament/Resources/Persons/Pages/ViewPerson.php`

Our new PersonResource extends the package's, which means the package pages are used by default (the `getPages()` method is inherited). Our old pages can be removed.

- [ ] **Step 1: Remove old Person resource pages**

```bash
rm app/Filament/Resources/Persons/Pages/ListPersons.php
rm app/Filament/Resources/Persons/Pages/CreatePerson.php
rm app/Filament/Resources/Persons/Pages/EditPerson.php
rm app/Filament/Resources/Persons/Pages/ViewPerson.php
```

---

### Task 18: Run tests

- [ ] **Step 1: Clear caches**

```bash
php artisan config:clear
php artisan cache:clear
```

- [ ] **Step 2: Run all tests**

```bash
vendor/bin/pest --parallel --compact
```

- [ ] **Step 3: Fix failing tests**

Common test failures and fixes:
1. Tests using `PersonFactory` — our factory now extends the package factory. Verify definitions align.
2. Tests asserting gender as string — may need to compare with `Gender::Male->value` or `Gender::Male`
3. Tests referencing removed classes or enums — update imports
4. Tests checking database columns — package migration columns differ (e.g., `slug` is nullable, `timestampsTz` vs `timestamps`)

---

### Task 19: Cleanup

- [ ] **Step 1: Verify no deleted class references remain**

```bash
rg -n "App\\\\Enums\\\\PersonNameType" app/ tests/ database/ resources/
rg -n "App\\\\Models\\\\PersonName" app/ tests/ database/ resources/
```

Expected: No results.

- [ ] **Step 2: Verify package Gender enum is used exclusively**

```bash
rg -n "use App\\\\Enums\\\\Gender" app/ tests/
```

Expected: No results.

- [ ] **Step 3: Final test run**

```bash
vendor/bin/pest --parallel --compact
```

Expected: All green.
