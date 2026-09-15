# Media Management Guidelines (Spatie Medialibrary v11 + Filament v5)

Media architecture already implemented app-wide. Follow exactly when adding/modifying media features.

## Core Stack
- `spatie/laravel-medialibrary` v11 + `filament/spatie-laravel-media-library-plugin` v5
- Config: `config/media-library.php`; upload policy: `app/Providers/AppServiceProvider.php` (static boot guards — keep for Octane)
- Naming: `app/Support/Media/MediaFileNamer.php`; paths: `app/Support/Media/MediaPathGenerator.php`

## Global Upload Policy (Do Not Bypass)
All `SpatieMediaLibraryFileUpload` fields inherit: size from `media-library.max_file_size` (10MB), `maxParallelUploads(2)`, `appendFiles()`, immutable cache header (`public, max-age=31536000, immutable`), filename `<slug-or-model-base>-<8-char-ulid>.<ext>`, human `name` from model + collection label, `custom_properties` = `collection` + `original_file_name`.

## Naming & Paths
- Storage base priority: `slug` → `name` → `title` → `label` → morph alias/class basename. Display name: `<Collection Label> - <Subject Label>` (fallback: original filename). Labels: poster→Event Poster, cover→Cover Image, logo→Logo, avatar→Avatar, main→Main Image, gallery→Gallery Image, qr→QR Code, evidence→Evidence File.
- Directory: `{model_type_plural}/{uuid_shard}/{model_uuid}/{collection}/` (e.g. `events/019c/019c4228-…/poster/`); sharding avoids hot directories and groups by owner+collection.

## Media Library Config (`config/media-library.php`)
- `version_urls`, lazy loading (`default_loading_attribute_value`, `force_lazy_loading`), queued conversions (+ after commit), `FileBaseFileRemover`, image optimizers (JPEG/PNG/SVG/GIF/WebP/AVIF), generators (image/webp/avif/pdf/svg/video).
- Custom `file_namer` (`MediaFileNamer`) and `path_generator` (`MediaPathGenerator`).

## Model Collection Matrix (Canonical)
- **Event**: `cover` (16:9, required) + `poster` (3:4 portrait, required) + `gallery` — jpeg/png/webp, responsive, cover/poster single-file w/ placeholder. Conversions: `thumb` 1920×1080 crop webp+sharpen10 (cover,gallery); `card`/`preview` max-1920 webp (cover,poster).
- **Institution**: `logo` (jpeg/png/webp/svg, single, placeholder) + `cover` (responsive, single, placeholder) + `gallery` (responsive, multi). Conversions: `thumb` 1080² webp+sharpen10 (logo); `banner` 1920×1080 crop webp (cover); `gallery_thumb` 1920×1080 crop webp+sharpen10.
- **Speaker**: `avatar` (single, placeholder) + `main`/`cover` (responsive, single, placeholder) + `gallery` (multi) — jpeg/png/webp. Conversions: `thumb`/`profile` 1080² webp (+sharpen10 on thumb), `card` 1080×1440 (avatar); `main_thumb` 1080²+sharpen10, `display` 1080×1440 crop (main); `banner` 1920×1080 (cover); `gallery_thumb` 1920×1080+sharpen10.
- **Venue**: `main`/`cover` (responsive, single, placeholder) + `gallery` (multi). Conversions: `thumb` 1920×1080 crop+sharpen10 (all); `banner` 1920×1080 (main,cover).
- **Series**: `cover` (responsive, single) + `gallery` (multi). Conversion: `thumb` 1920×1080 crop+sharpen10 (both).
- **Reference**: `front_cover`/`back_cover` (responsive, single) + `gallery` (multi). Conversions: `thumb` 1080×1440 crop+sharpen10 (covers); `gallery_thumb` 1920×1080+sharpen10.
- **DonationChannel**: `qr` (single) → `thumb` 1080² webp. **Report**: `evidence` (jpeg/png/webp/pdf, multi, max 8) → `thumb` 1080² webp.

## Event Aspect Ratio Contract
- `cover` = primary website/app visual, always 16:9 (submit/contribution/admin forms, APIs, MCP images). `poster` = shareable flyer, always 3:4 portrait (same surfaces). MCP: separate cover/poster tools, fixed ratios — no generic ratio selector.

## Card Image Fallback (`Event::getCardImageUrlAttribute`)
Event cover (`card`/`preview`/`thumb`) → poster (same) → institution logo `thumb` → global placeholder. Use for cards, previews, and social images.

## Filament Integration
- Forms (Events, Institutions, Speakers, Venues, Series, References, DonationChannels, Reports): `SpatieMediaLibraryFileUpload` + `->collection()`, `->image()`/`->imageEditor()`, `->responsiveImages()`, `->conversion(thumb|banner|gallery_thumb|preview)`; galleries/evidence `->multiple()->reorderable()`. Public submit (`components/pages/submit-event/create.blade.php`): cover 16:9 + poster 3:4 + gallery. Quick-create schemas (`Institution`/`Speaker`/`VenueFormSchema`) then `$schema?->model($model)->saveRelationships()`.
- Tables/infolists: conversion-specific `SpatieMediaLibraryImageColumn`/`SpatieMediaLibraryImageEntry` (never full originals in grids).

## Frontend Consumption
- Event detail (`Livewire/Pages/Events/Show.php` + blade): gallery from `poster` + `gallery` via `getAvailableUrl(['preview','thumb'])` w/ original fallback; slider + thumbnails; related-events + share modal use `card_image_url`.
- Elsewhere: conversion URLs (`profile`, `banner`, `gallery_thumb`, …); eager-load `media` on all list/detail queries (`->with('media')`).

## Maintenance & Optimization
- Scheduled: daily `media-library:clean --delete-orphaned --force`; weekly `media-library:regenerate --only-missing --with-responsive-images --force`. Structure migration `app:media:migrate-structure` (`app/Console/Commands/MigrateMediaToNewStructure.php`; `--dry-run`, `--force`) restructures legacy paths/names.
- Rules: conversion URLs for UI (grids/cards/galleries/previews); responsive images on major collections; strict per-collection MIME; singular assets as `singleFile()`. Indexes: `media.order_column`, `media_model_collection_order_index`, `media_collection_created_at_index`.
- Tests: `MediaConversionsTest` + `SubmitEventMediaTest` cover MIME acceptance, conversion registration, fallbacks, custom config, submit uploads.

## AI Checklist for New Media Features
1. Collection + conversions in model. 2. `SpatieMediaLibraryFileUpload` w/ explicit collection/conversion. 3. Conversion-specific columns/entries. 4. Conversion URLs on frontend + eager-load `media`. 5. Tests for MIME/conversions/fallbacks. 6. Never ad-hoc filenames, never originals in lists, never drop MIME rules or Octane boot guards.
