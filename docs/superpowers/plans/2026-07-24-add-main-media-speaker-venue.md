# Add `main` Media Collection to Speaker & Venue

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `main` media collection to Speaker (primary portrait/photo) and Venue (primary venue photo), distinct from `cover` (decorative banner/atmosphere) and `avatar` (identity icon).

**Architecture:** `main` = the primary representative photo ("this is what it looks like"). `cover` = decorative atmospheric backdrop. `avatar` = identity icon. Both models already have `cover` for the decorative role; `main` is additive, not a rename. Venue's `main` will immediately activate dead code in `events/show.blade.php` that already references `getFirstMedia('main')` in fallback chains.

**Tech Stack:** Spatie MediaLibrary v11, Filament v5, Pest v4.

## Global Constraints

- All image collections: `jpeg,png,webp` only, `config('media-library.disk_name')` disk.
- Single file, responsive images, no fixed aspect ratio enforced at model level.
- Speaker fallback: `images/placeholders/speaker.png`.
- Venue fallback: `images/placeholders/venue.png`.
- Follow existing code conventions: UUID PKs, no FK constraints, no SoftDeletes.
- Run `vendor/bin/pint --dirty --format agent` after all changes.
- Run `vendor/bin/pest --parallel --filter={testName}` to verify each task.

---

### Task 1: Speaker Model — `main` collection + conversions

**Files:**
- Modify: `app/Models/Speaker.php`

**Interfaces:**
- Consumes: (none — first task)
- Produces: `main` collection with `thumb` (100×100 webp sharpen(10)) and `display` (width 600 webp) conversions, `public_main_url` accessor, `default_main_url` accessor

- [ ] **Step 1: Add `main` to `registerMediaCollections()`** — after `cover` block, before `gallery`:

```php
$this->addMediaCollection('main')
    ->useDisk(config('media-library.disk_name'))
    ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->useFallbackUrl(asset('images/placeholders/speaker.png'))
    ->withResponsiveImages()
    ->singleFile();
```

- [ ] **Step 2: Add `main` conversions to `registerMediaConversions()`** — after `profile` conversion block, before `banner`:

```php
$this->addMediaConversion('thumb')
    ->performOnCollections('avatar', 'main')
    ->width(100)
    ->height(100)
    ->sharpen(10)
    ->format('webp');

$this->addMediaConversion('display')
    ->performOnCollections('main')
    ->width(600)
    ->format('webp');
```

Note: This changes `avatar`'s existing `thumb` from 80×80 to run on both `avatar` and `main` via `performOnCollections('avatar', 'main')`. However, `avatar` already has its own separate `thumb` conversion earlier in the method (lines 694-698). To avoid duplicate conversion names, rename the new one to `main_thumb` or scope it only to `main`:

**Corrected approach** — add a new conversion block, don't modify existing `avatar` thumb:

```php
$this->addMediaConversion('main_thumb')
    ->performOnCollections('main')
    ->width(100)
    ->height(100)
    ->sharpen(10)
    ->format('webp');

$this->addMediaConversion('display')
    ->performOnCollections('main')
    ->width(600)
    ->format('webp');
```

- [ ] **Step 3: Add `public_main_url` accessor to Speaker model** (after `public_avatar_url` around line 256):

```php
public function getPublicMainUrlAttribute(): string
{
    if ($this->hasMedia('main')) {
        $mainMedia = $this->getFirstMedia('main');

        if ($mainMedia instanceof Media) {
            return $mainMedia->getAvailableUrl(['display', 'main_thumb']) ?: $mainMedia->getUrl();
        }
    }

    return $this->public_avatar_url;
}
```

Note: Falls back to `public_avatar_url` (which itself has profile → thumb → gender placeholder chain).

- [ ] **Step 5: Add `use Spatie\MediaLibrary\MediaCollections\Models\Media;` import if not already present.**

- [ ] **Step 4: Run test to verify collection + conversions:**

```bash
vendor/bin/pest --parallel --filter="registers media conversions for Speaker"
```

- [ ] **Step 5: Commit:**

```bash
git add app/Models/Speaker.php
git commit -m "feat: add main media collection to Speaker model"
```

---

### Task 2: Speaker — Admin Filament Form

**Files:**
- Modify: `app/Filament/Resources/Speakers/Schemas/SpeakerForm.php`

**Interfaces:**
- Consumes: Speaker model with `main` collection, `main_thumb` + `display` conversions
- Produces: `main` file upload field in admin Speaker form

- [ ] **Step 1: Add `main` field to the Media section** — after `avatar` field, before `cover`:

```php
SpatieMediaLibraryFileUpload::make('main')
    ->label(__('Main Photo'))
    ->collection('main')
    ->image()
    ->imageEditor()
    ->responsiveImages()
    ->conversion('display')
    ->helperText(__('Primary speaker portrait or photo — shown prominently on the speaker profile page.')),
```

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="SpeakerForm|CreateSpeaker|EditSpeaker"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Filament/Resources/Speakers/Schemas/SpeakerForm.php
git commit -m "feat: add main photo upload to Speaker admin form"
```

---

### Task 3: Speaker — Contribution Form Schema

**Files:**
- Modify: `app/Forms/SpeakerContributionFormSchema.php`

**Interfaces:**
- Consumes: Speaker model with `main` collection
- Produces: `main` file upload field in contribution/quick-create forms

- [ ] **Step 1: Add `main` field in the Profile Photo & Media section** — after `avatar`, before `cover`:

```php
SpatieMediaLibraryFileUpload::make('main')
    ->label(__('Main Photo'))
    ->collection('main')
    ->image()
    ->imageEditor()
    ->responsiveImages()
    ->conversion('display')
    ->helperText(__('Primary speaker portrait or photo.')),
```

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="SpeakerCreateOptionSchema|SpeakerFormSchema|SpeakerContribution"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Forms/SpeakerContributionFormSchema.php
git commit -m "feat: add main photo upload to Speaker contribution form"
```

---

### Task 4: Speaker — SaveSpeakerAction

**Files:**
- Modify: `app/Actions/Speakers/SaveSpeakerAction.php`

**Interfaces:**
- Consumes: `main` file in `$data` array, `clear_main` boolean flag
- Produces: media synced via `ModelMediaSyncService`

- [ ] **Step 1: Add clear + sync for `main` in `syncMedia()`** — after `cover` clear block:

```php
if (($data['clear_main'] ?? false) === true) {
    $this->mediaSyncService->clearCollection($speaker, 'main');
}
```

- [ ] **Step 2: Add sync call** — after `$cover` variable assignment:

```php
$main = $data['main'] ?? null;

$this->mediaSyncService->syncSingle(
    $speaker,
    $main instanceof UploadedFile ? $main : null,
    'main',
);
```

- [ ] **Step 3: Run test:**

```bash
vendor/bin/pest --parallel --filter="SaveSpeakerAction|saveSpeaker"
```

- [ ] **Step 4: Commit:**

```bash
git add app/Actions/Speakers/SaveSpeakerAction.php
git commit -m "feat: handle main media in SaveSpeakerAction"
```

---

### Task 5: Speaker — Admin API Mutation Service

**Files:**
- Modify: `app/Support/Api/Admin/AdminResourceMutationService.php`

**Interfaces:**
- Consumes: Speaker model
- Produces: `main` field, `clear_main` flag, validation rules in admin API

- [ ] **Step 1: Add `main` to `mediaState()` call** — line ~261:

```php
'current_media' => $record instanceof Speaker ? $this->mediaState($record, ['avatar', 'main', 'cover', 'gallery']) : null,
```

- [ ] **Step 2: Add `clear_main` to create defaults** — lines ~554-561:

```php
'clear_main' => false,
```

- [ ] **Step 3: Add `clear_main` to update defaults** — lines ~676-690:

```php
$defaults['clear_main'] = false;
```

- [ ] **Step 4: Add `main` and `clear_main` field definitions** — in `speakerFields()` near other media fields:

```php
$this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
$this->field('clear_main', 'boolean', required: false, default: false),
```

- [ ] **Step 5: Add validation rules** — in `speakerRules()`:

```php
'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
'clear_main' => ['sometimes', 'boolean'],
```

- [ ] **Step 6: Run test:**

```bash
vendor/bin/pest --parallel --filter="AdminApi.*speaker|speaker.*AdminApi"
```

- [ ] **Step 7: Commit:**

```bash
git add app/Support/Api/Admin/AdminResourceMutationService.php
git commit -m "feat: add main media field to Speaker admin API"
```

---

### Task 6: Speaker — API DTO + Search Controller

**Files:**
- Modify: `app/Data/Api/Frontend/Search/SpeakerDetailMediaData.php`
- Modify: `app/Http/Controllers/Api/Frontend/SearchController.php`

**Interfaces:**
- Consumes: Speaker `main` collection
- Produces: `main_url` in API responses

- [ ] **Step 1: Add `main_url` to `SpeakerDetailMediaData`:**

```php
public function __construct(
    public string $avatar_url,
    public string $main_url,
    public string $cover_url,
    public string $share_image_url,
) {}

public static function fromModel(Speaker $speaker, string $coverUrl): self
{
    return new self(
        avatar_url: (string) $speaker->public_avatar_url,
        main_url: (string) $speaker->public_main_url,
        cover_url: $coverUrl,
        share_image_url: $speaker->hasMedia('avatar')
            ? (string) $speaker->public_avatar_url
            : ($coverUrl !== '' ? $coverUrl : (string) $speaker->default_avatar_url),
    );
}
```

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="SpeakerDetailMediaData|FrontendApi.*speaker"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Data/Api/Frontend/Search/SpeakerDetailMediaData.php app/Http/Controllers/Api/Frontend/SearchController.php
git commit -m "feat: expose main_url in Speaker API responses"
```

---

### Task 7: Speaker — Frontend Show Page

**Files:**
- Modify: `resources/views/livewire/pages/speakers/show.blade.php`

**Interfaces:**
- Consumes: `$speaker->public_main_url`
- Produces: `main` displayed as the hero profile image on `speakers.show`

- [ ] **Step 1: Update hero image to prefer `main` over `avatar`** — line 111-116:

```php
src="{{ $speaker->public_main_url }}"
```

(Replace `$speaker->public_avatar_url` with `$speaker->public_main_url` — the accessor already falls back to `public_avatar_url` when no `main` exists, so this is backward-compatible.)

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="SpeakerShow"
```

- [ ] **Step 3: Commit:**

```bash
git add resources/views/livewire/pages/speakers/show.blade.php
git commit -m "feat: display main photo on Speaker show page"
```

---

### Task 8: Venue Model — `main` collection + conversions

**Files:**
- Modify: `app/Models/Venue.php`

**Interfaces:**
- Consumes: (none)
- Produces: `main` collection with `thumb` (100×100 webp sharpen(10)) and `banner` (fit Crop 1200×675 webp) conversions, `public_main_url` accessor

- [ ] **Step 1: Add `main` to `registerMediaCollections()`** — before `cover` block:

```php
$this->addMediaCollection('main')
    ->useDisk(config('media-library.disk_name'))
    ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->useFallbackUrl(asset('images/placeholders/venue.png'))
    ->withResponsiveImages()
    ->singleFile();
```

- [ ] **Step 2: Add `main` conversions to `registerMediaConversions()`**:

The existing `thumb` conversion on `cover` needs updating to also include `main`, OR add a separate conversion. Since Venue already has `thumb` on `cover,gallery`, add `main` to the existing `thumb`:

```php
$this->addMediaConversion('thumb')
    ->performOnCollections('main', 'cover', 'gallery')
    ->width(368)
    ->height(232)
    ->sharpen(10)
    ->format('webp');
```

And add `main` to the existing `banner`:

```php
$this->addMediaConversion('banner')
    ->performOnCollections('main', 'cover')
    ->fit(Fit::Crop, 1200, 675)
    ->format('webp');
```

This means simply adding `'main'` as the first element in the existing `performOnCollections` arrays.

- [ ] **Step 3: Add `public_main_url` accessor to Venue model** (after `registerMediaConversions`):

```php
public function getPublicMainUrlAttribute(): string
{
    if ($this->hasMedia('main')) {
        $mainMedia = $this->getFirstMedia('main');

        if ($mainMedia instanceof Media) {
            return $mainMedia->getAvailableUrl(['banner', 'thumb']) ?: $mainMedia->getUrl();
        }
    }

    return $this->getFirstMediaUrl('cover', 'banner') ?: asset('images/placeholders/venue.png');
}
```

- [ ] **Step 4: Add `use Spatie\MediaLibrary\MediaCollections\Models\Media;` import if not already present.**

- [ ] **Step 5: Run test:**

```bash
vendor/bin/pest --parallel --filter="Venue.*media|Venue.*conversion"
```

- [ ] **Step 6: Commit:**

```bash
git add app/Models/Venue.php
git commit -m "feat: add main media collection to Venue model"
```

---

### Task 9: Venue — Quick-Create Form Schema

**Files:**
- Modify: `app/Forms/VenueFormSchema.php`

**Interfaces:**
- Consumes: Venue model with `main` collection
- Produces: `main` file upload field in Venue quick-create form

- [ ] **Step 1: Add `main` field** — before `cover` field:

```php
SpatieMediaLibraryFileUpload::make('main')
    ->label(__('Main Photo'))
    ->collection('main')
    ->image()
    ->imageEditor()
    ->responsiveImages()
    ->conversion('banner')
    ->helperText(__('Primary venue photo — shown prominently on the venue profile page.')),
```

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="VenueFormSchema|VenueCreate"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Forms/VenueFormSchema.php
git commit -m "feat: add main photo upload to Venue quick-create form"
```

---

### Task 10: Venue — SaveVenueAction

**Files:**
- Modify: `app/Actions/Venues/SaveVenueAction.php`

**Interfaces:**
- Consumes: `main` file in `$data` array, `clear_main` boolean flag
- Produces: media synced via `ModelMediaSyncService`

- [ ] **Step 1: Add clear + sync for `main` in `syncMedia()`** — after `clear_cover` block:

```php
if (($data['clear_main'] ?? false) === true) {
    $this->mediaSyncService->clearCollection($venue, 'main');
}
```

- [ ] **Step 2: Add sync call** — before `$cover`:

```php
$main = $data['main'] ?? null;

$this->mediaSyncService->syncSingle(
    $venue,
    $main instanceof UploadedFile ? $main : null,
    'main',
);
```

- [ ] **Step 3: Run test:**

```bash
vendor/bin/pest --parallel --filter="SaveVenueAction|saveVenue"
```

- [ ] **Step 4: Commit:**

```bash
git add app/Actions/Venues/SaveVenueAction.php
git commit -m "feat: handle main media in SaveVenueAction"
```

---

### Task 11: Venue — Admin API Mutation Service

**Files:**
- Modify: `app/Support/Api/Admin/AdminResourceMutationService.php`

**Interfaces:**
- Consumes: Venue model
- Produces: `main` field, `clear_main` flag, validation rules in admin API

- [ ] **Step 1: Add `main` to `mediaState()` call** — line ~298:

```php
'current_media' => $record instanceof Venue ? $this->mediaState($record, ['main', 'cover', 'gallery']) : null,
```

- [ ] **Step 2: Add `clear_main` to create defaults** — lines ~562-568:

```php
'clear_main' => false,
```

- [ ] **Step 3: Add `clear_main` to update defaults** — lines ~717-722:

```php
$defaults['clear_main'] = false;
```

- [ ] **Step 4: Add `main` and `clear_main` field definitions** — in `venueFields()`:

```php
$this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
$this->field('clear_main', 'boolean', required: false, default: false),
```

- [ ] **Step 5: Add validation rules** — in `venueRules()`:

```php
'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
'clear_main' => ['sometimes', 'boolean'],
```

- [ ] **Step 6: Run test:**

```bash
vendor/bin/pest --parallel --filter="AdminApi.*venue|venue.*AdminApi"
```

- [ ] **Step 7: Commit:**

```bash
git add app/Support/Api/Admin/AdminResourceMutationService.php
git commit -m "feat: add main media field to Venue admin API"
```

---

### Task 12: Venue — API DTO

**Files:**
- Modify: `app/Data/Api/Frontend/Search/VenueDetailMediaData.php`

**Interfaces:**
- Consumes: Venue `main` collection
- Produces: `main_url` in API responses

- [ ] **Step 1: Add `main_url` to DTO and resolve it:**

```php
class VenueDetailMediaData extends Data
{
    public function __construct(
        public string $main_url,
        public string $cover_url,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            main_url: $venue->getFirstMediaUrl('main', 'banner') ?: $venue->getFirstMediaUrl('main'),
            cover_url: $venue->getFirstMediaUrl('cover', 'banner') ?: $venue->getFirstMediaUrl('cover'),
        );
    }
}
```

- [ ] **Step 2: Run test:**

```bash
vendor/bin/pest --parallel --filter="VenueDetailMediaData|FrontendApi.*venue"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Data/Api/Frontend/Search/VenueDetailMediaData.php
git commit -m "feat: expose main_url in Venue API responses"
```

---

### Task 13: Venue — Frontend Show Page

**Files:**
- Modify: `resources/views/components/pages/venues/⚡show.blade.php`

**Interfaces:**
- Consumes: `$venue->public_main_url`
- Produces: `main` preferred over `cover` for hero + OG image

- [ ] **Step 1: Update OG image** — line 111:

```php
@section('og_image', $this->venue->public_main_url)
```

- [ ] **Step 2: Update hero image URL resolution** — lines 120-121, replace `$coverUrl` derivation with:

```php
$mainUrl = $venue->public_main_url;
```

And use `$mainUrl` in the hero `<img src>` instead of `$coverUrl` at line 188.

But keep `$coverUrl` and `$thumbUrl` for any other uses (event card fallback at lines 208, 239).

- [ ] **Step 3: Run test:**

```bash
vendor/bin/pest --parallel --filter="VenueShow|venue.*show|VenuePage"
```

- [ ] **Step 4: Commit:**

```bash
git add resources/views/components/pages/venues/⚡show.blade.php
git commit -m "feat: display main photo on Venue show page"
```

---

### Task 14: MCP Tools — `main` field payload normalization

**Files:**
- Modify: `app/Support/Mcp/McpFilePayloadNormalizer.php`

**Interfaces:**
- Consumes: `main` field name
- Produces: `main` recognized as file field in MCP write tools

- [ ] **Step 1: Add `'main'` to the file fields list** — check if already listed. Based on prior investigation, `clear_main` is already in the field guards but `main` may need to be added to file fields:

Check the file for existing entries like `'cover'`, `'avatar'` and add `'main'` alongside them if missing.

- [ ] **Step 2: Run MCP tests:**

```bash
vendor/bin/pest --parallel --filter="Mcp.*speaker|Mcp.*venue"
```

- [ ] **Step 3: Commit:**

```bash
git add app/Support/Mcp/McpFilePayloadNormalizer.php
git commit -m "feat: add main to MCP file payload normalizer"
```

---

### Task 15: MediaFileNamer — `main` label

**Files:**
- Modify: `app/Support/Media/MediaFileNamer.php`

**Interfaces:**
- Consumes: `main` collection name
- Produces: human-readable "Main Image" label

- [ ] **Step 1: Add `'main'` to the collection label match** — after `'avatar'`:

```php
'main' => 'Main Image',
```

(Currently falls through to default `Str::headline()` which produces "Main" — this makes it explicit.)

- [ ] **Step 2: Commit:**

```bash
git add app/Support/Media/MediaFileNamer.php
git commit -m "feat: add Main Image label for main collection in MediaFileNamer"
```

---

### Task 16: Tests — MediaConversionsTest

**Files:**
- Modify: `tests/Feature/MediaConversionsTest.php`

**Interfaces:**
- Consumes: Speaker and Venue models with `main` collection
- Produces: test coverage for `main` collection + conversions

- [ ] **Step 1: Add Speaker `main` test** — after existing Speaker cover test (line 306):

```php
it('registers main media collection for Speaker model', function () {
    $speaker = Speaker::factory()->create();

    $speaker->addMedia(fakeGeneratedImageUpload('main.png', 600, 800))
        ->toMediaCollection('main');

    $media = $speaker->getFirstMedia('main');

    expect($media)->not->toBeNull();
    expect($speaker->hasMedia('main'))->toBeTrue();
    expect($media->getMediaConversionNames())->toContain('main_thumb');
    expect($media->getMediaConversionNames())->toContain('display');
});

it('returns public_main_url fallback when Speaker has no main photo', function () {
    $speaker = Speaker::factory()->create();

    $mainUrl = $speaker->public_main_url;

    // Falls back to public_avatar_url
    expect($mainUrl)->not->toBeEmpty();
});
```

- [ ] **Step 2: Add Venue `main` test** — after existing Venue tests (line 364):

```php
it('registers main media collection for Venue model', function () {
    $venue = Venue::factory()->create();

    $venue->addMedia(fakeGeneratedImageUpload('main.png', 1200, 800))
        ->toMediaCollection('main');

    $media = $venue->getFirstMedia('main');

    expect($media)->not->toBeNull();
    expect($venue->hasMedia('main'))->toBeTrue();
    expect($media->getMediaConversionNames())->toContain('thumb');
    expect($media->getMediaConversionNames())->toContain('banner');
});

it('returns public_main_url preferring main over cover for Venue', function () {
    $venue = Venue::factory()->create();

    $venue->addMedia(fakeGeneratedImageUpload('main.png', 1200, 800))
        ->toMediaCollection('main');

    $mainUrl = $venue->public_main_url;

    expect($mainUrl)->toContain('main');
    expect($mainUrl)->toContain('conversions');
});
```

- [ ] **Step 3: Run the new tests:**

```bash
vendor/bin/pest --parallel --filter="main media collection|public_main_url"
```

- [ ] **Step 4: Commit:**

```bash
git add tests/Feature/MediaConversionsTest.php
git commit -m "test: add main media collection tests for Speaker and Venue"
```

---

### Task 17: Update AGENTS.md Media Guidelines

**Files:**
- Modify: `AGENTS.md`

**Interfaces:**
- Consumes: completed `main` collections on Speaker and Venue
- Produces: accurate Model Collection Matrix

- [ ] **Step 1: Update the Speaker section** — change `main` line (was already there), add conversions:

```markdown
### Speaker (`app/Models/Speaker.php`)

- `avatar`: jpeg,png,webp, single file, fallback placeholder
- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `cover`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 80x80 webp sharpen(10) on `avatar`
  - `profile`: 400x400 webp on `avatar`
  - `main_thumb`: 100x100 webp sharpen(10) on `main`
  - `display`: width 600 webp on `main`
  - `banner`: 1200x675 crop webp on `cover`
  - `gallery_thumb`: 368x232 webp sharpen(10) on `gallery`
```

- [ ] **Step 2: Update the Venue section** — add `main` as primary:

```markdown
### Venue (`app/Models/Venue.php`)

- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `cover`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `main`,`cover`,`gallery`
  - `banner`: 1200x675 crop webp on `main`,`cover`
```

- [ ] **Step 3: Commit:**

```bash
git add AGENTS.md
git commit -m "docs: update media collection matrix for Speaker and Venue main"
```

---

### Task 18: Final Verification

- [ ] **Step 1: Run all media-related tests:**

```bash
vendor/bin/pest --parallel tests/Feature/MediaConversionsTest.php
```

- [ ] **Step 2: Run Speaker tests:**

```bash
vendor/bin/pest --parallel --filter="Speaker"
```

- [ ] **Step 3: Run Venue tests:**

```bash
vendor/bin/pest --parallel --filter="Venue"
```

- [ ] **Step 4: Run PHPStan:**

```bash
vendor/bin/phpstan analyse --ansi
```

- [ ] **Step 5: Run Pint:**

```bash
vendor/bin/pint --dirty --format agent
```

