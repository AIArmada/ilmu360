# Media Management Guidelines (Spatie Medialibrary v11 + Filament v5)

This document defines the media architecture that is already implemented across this application.  
When adding or modifying media features, follow these rules exactly.

## Core Stack
- Package: `spatie/laravel-medialibrary` v11
- Filament integration: `filament/spatie-laravel-media-library-plugin` v5
- Main config: `config/media-library.php`
- Global upload policy: `app/Providers/AppServiceProvider.php`
- Naming strategy: `app/Support/Media/MediaFileNamer.php`
- Storage path strategy: `app/Support/Media/MediaPathGenerator.php`

## Global Upload Policy (Do Not Bypass)
All `SpatieMediaLibraryFileUpload` fields are globally configured in `AppServiceProvider`.

### Implemented defaults
- Max upload size is derived from `config('media-library.max_file_size')` (10MB default).
- `maxParallelUploads(2)` to balance UX and server load.
- `appendFiles()` so additional uploads do not replace unintentionally.
- Immutable cache header for uploaded files:
  - `CacheControl: public, max-age=31536000, immutable`
- Storage filename pattern:
  - `<slug-or-model-base>-<8-char-ulid>.<ext>`
- Human-readable media `name` is generated from model + collection label.
- `custom_properties` always store:
  - `collection`
  - `original_file_name`

### Octane safety
- Boot-time configuration is protected by static guards (for example `$mediaUploadConfigured`) in `AppServiceProvider`.
- Keep these guards to avoid duplicate macro/config registration in long-lived workers.

## Naming Rules (Natural, Consistent, Searchable)
Implemented in `MediaFileNamer`.

### Storage base name priority
1. `slug`
2. `name`
3. `title`
4. `label`
5. Morph alias / class basename fallback

### Human display name labels
- `poster` => `Event Poster`
- `cover` => `Cover Image`
- `logo` => `Logo`
- `avatar` => `Avatar`
- `main` => `Main Image`
- `gallery` => `Gallery Image`
- `qr` => `QR Code`
- `evidence` => `Evidence File`

The final media name format is:
- `<Collection Label> - <Subject Label>`
- Fallback to original filename label if subject is not available.

## Directory Strategy (Long-Term Scalability)
Implemented in `MediaPathGenerator`.

Directory format:
- `{model_type_plural}/{uuid_shard}/{model_uuid}/{collection}/`

Example:
- `events/019c/019c4228-.../poster/`
- `institutions/01b2/01b2c1d4-.../gallery/`

Why:
- Avoids giant hot directories.
- Keeps files grouped by owner and collection.
- Makes bulk cleanup and debugging easier.

## Media Library Config Decisions
Configured in `config/media-library.php`.

### Performance-centric defaults
- `version_urls => true` (cache busting without stale assets)
- `default_loading_attribute_value => 'lazy'`
- `force_lazy_loading => true`
- `queue_conversions_by_default => true`
- `queue_conversions_after_database_commit => true`
- `file_remover_class => FileBaseFileRemover` (safe for shared directory structures)
- Image optimizers enabled (JPEG, PNG, SVG, GIF, WebP, AVIF)
- Generators enabled for image, webp, avif, pdf, svg, video

### Custom classes
- `file_namer => App\Support\Media\MediaFileNamer::class`
- `path_generator => App\Support\Media\MediaPathGenerator::class`

## Model Collection Matrix (Canonical)

### Event (`app/Models/Event.php`)
- `cover`: image/jpeg,image/png,image/webp, responsive, single file, fallback placeholder, required 16:9 website/mobile-app cover
- `poster`: image/jpeg,image/png,image/webp, responsive, single file, fallback placeholder, required 4:5 portrait external-distribution poster
- `gallery`: image/jpeg,image/png,image/webp, responsive, multi file
- Conversions:
  - `thumb`: 600x400 cropped webp sharpen(10) on `cover`,`poster`,`gallery`
  - `card`: max 960x1200 webp on `cover`,`poster`
  - `preview`: max 1400x1800 webp on `cover`,`poster`

### Institution (`app/Models/Institution.php`)
- `logo`: jpeg,png,webp,svg, single file, fallback placeholder
- `cover`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 100x100 webp sharpen(10) on `logo`
  - `banner`: width 1200 webp on `cover`
  - `gallery_thumb`: 368x232 webp sharpen(10) on `gallery`

### Speaker (`app/Models/Speaker.php`)
- `avatar`: jpeg,png,webp, single file, fallback placeholder
- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 80x80 webp sharpen(10) on `avatar`
  - `profile`: 400x400 webp on `avatar`
  - `banner`: width 1200 webp on `main`
  - `gallery_thumb`: 368x232 webp sharpen(10) on `gallery`

### Venue (`app/Models/Venue.php`)
- `main`: jpeg,png,webp, responsive, single file, fallback placeholder
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `main`,`gallery`
  - `banner`: width 1200 webp on `main`

### Series (`app/Models/Series.php`)
- `cover`: jpeg,png,webp, responsive, single file
- `gallery`: jpeg,png,webp, responsive, multi file
- Conversions:
  - `thumb`: 368x232 webp sharpen(10) on `cover`,`gallery`

### Reference (`app/Models/Reference.php`)
- `cover`: jpeg,png,webp, responsive, single file
- Conversion:
  - `thumb`: 200x280 webp sharpen(10) on `cover`

### DonationChannel (`app/Models/DonationChannel.php`)
- `qr`: jpeg,png,webp, single file
- Conversion:
  - `thumb`: 200x200 webp on `qr`

### Report (`app/Models/Report.php`)
- `evidence`: jpeg,png,webp,pdf, multi file
- Conversion:
  - `thumb`: 200x200 webp on `evidence`

## Filament Form Integration Pattern

### Admin resources
All major resources already use `SpatieMediaLibraryFileUpload`:
- `Events`, `Institutions`, `Speakers`, `Venues`, `Series`, `References`, `DonationChannels`, `Reports`

Common implemented options:
- `->collection('...')`
- `->image()` and `->imageEditor()` for image collections
- `->responsiveImages()` where needed
- `->conversion('thumb'|'banner'|'gallery_thumb'|'preview')`
- `->multiple()->reorderable()` for gallery/evidence collections
- `->maxFiles(8)` and PDF support for report evidence

### Public submission
`resources/views/components/pages/submit-event/create.blade.php` includes:
- `cover` upload for website/mobile app display, fixed to 16:9
- `poster` upload for external/social distribution, fixed to 4:5 portrait
- `gallery` upload with reorder support
- image editor + responsive images + conversion wiring

### Quick-create forms
`InstitutionFormSchema`, `SpeakerFormSchema`, and `VenueFormSchema` also support media uploads during relation quick-create flows, then call:
- `$schema?->model($model)->saveRelationships();`

## Filament Table/Infolist Rendering Pattern
Use conversion-specific media columns/entries for lightweight lists:
- `SpatieMediaLibraryImageColumn` in table resources
- `SpatieMediaLibraryImageEntry` in infolists
- Always point to the correct collection + conversion (`thumb`, `banner`, `gallery_thumb`, `preview`)

This avoids serving full originals in admin grids.

## Frontend Consumption Pattern

### Event detail page
Implemented in:
- `app/Livewire/Pages/Events/Show.php`
- `resources/views/livewire/pages/events/show.blade.php`

Features:
- Gallery payload built from `poster` + `gallery`.
- Uses `getAvailableUrl(['preview','thumb'])` with safe fallback to original URL.
- Gallery slider with thumbnail strip.
- Related events section uses `card_image_url`.
- Share preview modal uses `card_image_url` + social share links + native share/copy flow.

### Other pages
- Speaker and institution public pages render conversion URLs (`profile`, `banner`, `gallery_thumb`, etc.)
- Listing pages eager load `media` to avoid N+1.

## Card Image Fallback Chain
`Event::getCardImageUrlAttribute()`:
1. Event cover `card`/`preview`/`thumb`
2. Event poster `card`/`preview`/`thumb`
3. Institution logo `thumb`
4. Global placeholder image

## Event Aspect Ratio Contract

- Event `cover` is the primary website/mobile-app visual and must be 16:9 on public submit forms, contribution update forms, admin forms, frontend/admin APIs, and MCP-generated images.
- Event `poster` is the shareable external-distribution flyer and must be 4:5 portrait on public submit forms, contribution update forms, admin forms, frontend/admin APIs, and MCP-generated images.
- MCP exposes separate event image tools: cover tools write the `cover` collection at 16:9; poster tools write the `poster` collection at 4:5. Do not add a generic ratio selector for event media generation.

Use this accessor for cards, previews, and social image fallback behavior.

## Maintenance and Cleanup

### Scheduled jobs (`routes/console.php`)
- Daily clean:
  - `media-library:clean --delete-orphaned --force`
- Weekly regenerate missing derivatives:
  - `media-library:regenerate --only-missing --with-responsive-images --force`

### Migration helper command
- `app: media:migrate-structure`
- File: `app/Console/Commands/MigrateMediaToNewStructure.php`
- Supports:
  - `--dry-run`
  - `--force`
- Moves legacy media paths into the sharded structure and renames files to the slug-based convention.

## Query and Storage Optimization Rules
- Always eager-load media when rendering list/detail pages:
  - `->with('media')`, `load(['media', ...])`
- Prefer conversion URLs for UI surfaces:
  - admin grids, cards, galleries, previews
- Use responsive images on major visual collections (`poster`, `cover`, `main`, `gallery` where configured).
- Keep strict MIME rules per collection.
- Keep singular assets (`poster`, `avatar`, `logo`, `main`, `cover`, `qr`) as `singleFile()` collections.

## Database-Level Optimizations Implemented
- `media.order_column` indexed
- Extra indexes added on media table:
  - `media_model_collection_order_index` on (`model_type`, `model_id`, `collection_name`, `order_column`)
  - `media_collection_created_at_index` on (`collection_name`, `created_at`)

These improve collection fetch ordering and maintenance/reporting queries.

## Testing Guarantees (Reference)
`tests/Feature/MediaConversionsTest.php` and `tests/Feature/SubmitEventMediaTest.php` verify:
- Collection MIME acceptance/rejection
- Conversions are registered and used
- Fallback URLs exist
- Custom media config is active (`path_generator`, `file_namer`, lazy loading, versioned URLs)
- Submit-event cover/poster/gallery uploads persist correctly

## AI Implementation Checklist (For New Media Features)
1. Add/extend collection + conversions in the model (`registerMediaCollections`, `registerMediaConversions`).
2. Use `SpatieMediaLibraryFileUpload` with explicit `collection()` and conversion mapping.
3. Use conversion-specific image columns/entries in Filament tables/infolists.
4. Render conversion URLs on frontend, not originals.
5. Eager-load `media` in queries to avoid N+1.
6. Add/adjust tests for conversions, MIME constraints, and fallback behavior.
7. Do not bypass global naming/path conventions.

## Do Not Do
- Do not introduce ad-hoc filename generation outside global upload config.
- Do not store large image originals directly in list/card UIs.
- Do not skip collection MIME constraints.
- Do not remove AppServiceProvider static boot guards in Octane environments.
