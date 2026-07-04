<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use AIArmada\Addressing\Models\Address;
use AIArmada\Events\Models\EventAccessPolicy;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Enums\TagType;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\MediaLink;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\Location\AddressHierarchyFormatter;
use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Nnjeim\World\Models\Language;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use UnitEnum;

class EventCoverPromptBuilder
{
    /**
     * @var list<string>
     */
    public const array TARGET_COLLECTIONS = ['cover', 'poster'];

    /**
     * @var array<class-string<Model>, array<string, list<string>>>
     */
    private const array MEDIA_COLLECTIONS = [
        Event::class => [
            'cover' => ['preview', 'card', 'thumb'],
            'poster' => ['preview', 'card', 'thumb'],
            'gallery' => ['thumb'],
        ],
        Institution::class => [
            'logo' => ['thumb'],
            'cover' => ['banner'],
            'gallery' => ['gallery_thumb'],
        ],
        Speaker::class => [
            'avatar' => ['profile', 'thumb'],
            'cover' => ['banner'],
            'gallery' => ['gallery_thumb'],
        ],
        Venue::class => [
            'cover' => ['banner', 'thumb'],
            'gallery' => ['thumb'],
        ],
        Series::class => [
            'cover' => ['thumb'],
            'gallery' => ['thumb'],
        ],
        Reference::class => [
            'front_cover' => ['thumb'],
            'back_cover' => ['thumb'],
            'gallery' => ['gallery_thumb'],
        ],
        DonationChannel::class => [
            'qr' => ['thumb'],
        ],
    ];

    /**
     * @param  array{
     *   target_collection?: string|null,
     *   creative_direction?: string|null,
     *   include_existing_media?: bool|null,
     *   max_reference_media?: int|null
     * }  $options
     * @return array{
     *   payload: array<string, mixed>,
     *   content_media: list<array{media: Media, payload: array<string, mixed>}>
     * }
     */
    public function build(Event $event, array $options = []): array
    {
        $this->loadPromptContext($event);

        $target = $this->targetSpec($options['target_collection'] ?? 'cover');
        $creativeDirection = $this->optionalString($options['creative_direction'] ?? null);
        $includeExistingMedia = $options['include_existing_media'] ?? true;
        $includeExistingMedia = is_bool($includeExistingMedia) ? $includeExistingMedia : true;
        $maxReferenceMedia = max(0, min(10, (int) ($options['max_reference_media'] ?? 10)));

        $selectedMedia = array_slice(
            $this->selectedMedia($event, $target['collection'], $includeExistingMedia),
            0,
            $maxReferenceMedia,
        );
        $referenceMedia = array_map(
            fn (array $candidate): array => $candidate['payload'],
            $selectedMedia,
        );

        $sourceData = $this->sourceData($event);
        $prompt = $this->prompt($event, $target, $referenceMedia, $creativeDirection);

        return [
            'payload' => [
                'event' => [
                    'id' => (string) $event->getKey(),
                    'route_key' => (string) $event->getRouteKey(),
                    'slug' => (string) $event->slug,
                    'title' => (string) $event->title,
                    'public_url' => route('events.show', ['event' => $event->slug], false),
                ],
                'target' => $target,
                'prompt' => $prompt,
                'upload_spec' => $this->uploadSpec($target),
                'reference_media' => $referenceMedia,
                'source_data' => $sourceData,
                'usage' => [
                    'intended_next_step' => 'This prompt is intended for the MCP image-generation tool. Attach selected reference_media images when the image model supports image inputs.',
                    'save_target' => [
                        'model' => Event::class,
                        'record_key' => (string) $event->getRouteKey(),
                        'collection' => $target['collection'],
                    ],
                    'safety_notes' => [
                        'Do not invent speaker likenesses when no actual speaker image is provided.',
                        'Do not invent official logos, QR codes, sponsors, phone numbers, or registration claims.',
                        'Keep all visible event facts consistent with source_data.',
                    ],
                ],
            ],
            'content_media' => $selectedMedia,
        ];
    }

    private function loadPromptContext(Event $event): void
    {
        $event->loadMissing([
            'media',
            'addresses.country',
            'institution.media',
            'institution.addresses.country',
            'venue.media',
            'venue.addresses.country',
            'space',
            'primaryOrganizerInvolvement.involveable',
            'speakers.media',
            'keyPeople.speaker.media',
            'tags',
            'references.media',
            'references.parentReference.media',
            'series.media',
            'languages',
            'settings',
            'donationChannel.media',
            'mediaLinks',
            'parentEvent.media',
            'childEvents.media',
            'childEvents.institution.media',
            'childEvents.venue.media',
        ]);

        if ($involveable = $event->primaryOrganizerInvolvement?->involveable) {
            $involveable->loadMissing(['media']);
        }
    }

    /**
     * @return array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }
     */
    private function targetSpec(mixed $requested): array
    {
        $collection = is_string($requested) && in_array($requested, self::TARGET_COLLECTIONS, true)
            ? $requested
            : 'cover';

        if ($collection === 'poster') {
            return [
                'collection' => 'poster',
                'label' => 'Event Poster Image',
                'aspect_ratio' => '4:5',
                'ratio_width' => 4,
                'ratio_height' => 5,
                'ai_size' => '2:3',
                'output_width' => 1600,
                'output_height' => 2000,
            ];
        }

        return [
            'collection' => 'cover',
            'label' => 'Event Cover Image',
            'aspect_ratio' => '16:9',
            'ratio_width' => 16,
            'ratio_height' => 9,
            'ai_size' => '3:2',
            'output_width' => 1600,
            'output_height' => 900,
        ];
    }

    /**
     * @param  array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }  $target
     * @return array<string, mixed>
     */
    private function uploadSpec(array $target): array
    {
        return [
            'target_model' => Event::class,
            'target_collection' => $target['collection'],
            'single_file' => true,
            'responsive_images' => true,
            'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_file_size_kb' => (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024),
            'required_aspect_ratio' => $target['aspect_ratio'],
            'allowed_aspect_ratios' => [$target['aspect_ratio']],
            'output_width' => $target['output_width'],
            'output_height' => $target['output_height'],
            'conversions' => [
                'thumb' => '600x400 cropped webp, sharpened',
                'card' => 'max 960x1200 webp',
                'preview' => 'max 1400x1800 webp',
            ],
            'storage_filename_pattern' => '<event-slug-or-title>-<8-char-ulid>.<ext>',
        ];
    }

    /**
     * @return list<array{media: Media, payload: array<string, mixed>}>
     */
    private function selectedMedia(Event $event, string $targetCollection, bool $includeExistingMedia): array
    {
        $selected = [];
        $seen = [];

        if ($includeExistingMedia) {
            $existingRole = $targetCollection === 'cover' ? 'existing_event_cover' : 'existing_event_poster';
            $existingReason = $targetCollection === 'cover'
                ? 'Use for continuity with the current event cover. Improve composition without copying artifacts.'
                : 'Use for continuity with the current event poster. Improve it; do not copy low-quality text artifacts.';

            $this->pushMediaCandidates($selected, $seen, $event, $targetCollection, ['preview', 'card', 'thumb'], $existingRole, $existingReason, 1);
        }

        if ($targetCollection === 'cover') {
            $this->pushMediaCandidates($selected, $seen, $event, 'poster', ['preview', 'card', 'thumb'], 'event_poster_reference', 'Use only for factual and visual continuity; do not import cluttered flyer layout into the cover.', 1);
        }

        if ($targetCollection === 'poster') {
            $this->pushMediaCandidates($selected, $seen, $event, 'cover', ['preview', 'card', 'thumb'], 'event_cover_reference', 'Use for visual continuity with the website/app cover while making the poster information-rich.', 1);
        }

        $this->pushMediaCandidates($selected, $seen, $event, 'gallery', ['thumb'], 'event_gallery', 'Use as real event/location atmosphere if visually helpful.', 3);

        if ($event->venue instanceof Venue) {
            $this->pushMediaCandidates($selected, $seen, $event->venue, 'cover', ['banner', 'thumb'], 'venue_cover', 'Use as the physical location reference.', 1);
            $this->pushMediaCandidates($selected, $seen, $event->venue, 'gallery', ['thumb'], 'venue_gallery', 'Use for venue atmosphere and interior cues.', 2);
        }

        foreach ($event->keyPeople as $keyPerson) {
            if (! $keyPerson instanceof EventKeyPerson || ! $keyPerson->speaker instanceof Speaker) {
                continue;
            }

            $role = $keyPerson->role instanceof EventKeyPersonRole ? $keyPerson->role->value : (string) $keyPerson->role;
            $this->pushMediaCandidates(
                $selected,
                $seen,
                $keyPerson->speaker,
                'cover',
                ['banner', 'thumb'],
                "speaker_cover:{$role}",
                "Use as the primary likeness/context reference for {$keyPerson->display_name} ({$this->roleLabel($keyPerson->role)}).",
                1,
            );

            $this->pushMediaCandidates(
                $selected,
                $seen,
                $keyPerson->speaker,
                'avatar',
                ['profile', 'thumb'],
                "speaker_avatar:{$role}",
                "Use as fallback likeness reference for {$keyPerson->display_name} ({$this->roleLabel($keyPerson->role)}) when speaker cover is unavailable.",
                1,
            );
        }

        $institutionReference = null;

        if ($event->organizer instanceof Institution) {
            $institutionReference = $event->organizer;
        } elseif ($event->institution instanceof Institution) {
            $institutionReference = $event->institution;
        }

        if ($institutionReference instanceof Institution) {
            $isOrganizerInstitution = $event->organizer instanceof Institution
                && (string) $event->organizer->getKey() === (string) $institutionReference->getKey();

            $coverReason = $isOrganizerInstitution
                ? 'Fallback environment reference from the event organizer institution when speaker media is unavailable.'
                : 'Fallback environment reference from the linked institution when event-level media is unavailable.';
            $logoReason = $isOrganizerInstitution
                ? 'Fallback organizer identity mark from the event organizer institution.'
                : 'Fallback institution identity mark from the linked institution.';
            $galleryReason = $isOrganizerInstitution
                ? 'Fallback atmosphere references from the event organizer institution.'
                : 'Fallback atmosphere references from the linked institution.';

            $this->pushMediaCandidates($selected, $seen, $institutionReference, 'cover', ['banner'], 'institution_cover', $coverReason, 1);
            $this->pushMediaCandidates($selected, $seen, $institutionReference, 'logo', ['thumb'], 'institution_logo', $logoReason, 1);
            $this->pushMediaCandidates($selected, $seen, $institutionReference, 'gallery', ['gallery_thumb'], 'institution_gallery', $galleryReason, 2);
        }

        foreach ($event->references as $reference) {
            if (! $reference instanceof Reference) {
                continue;
            }

            $this->pushMediaCandidates($selected, $seen, $reference, 'front_cover', ['thumb'], 'reference_front_cover', 'Use as a book/reference visual if the cover should include the study text.', 1);
            $this->pushMediaCandidates($selected, $seen, $reference, 'back_cover', ['thumb'], 'reference_back_cover', 'Use only if the front cover is unavailable or the back cover has useful identity cues.', 1);
        }

        foreach ($event->series as $series) {
            if ($series instanceof Series) {
                $this->pushMediaCandidates($selected, $seen, $series, 'cover', ['thumb'], 'series_cover', 'Use for visual continuity with the event series.', 1);
            }
        }

        return array_slice($selected, 0, 10);
    }

    /**
     * @param  list<array{media: Media, payload: array<string, mixed>}>  $selected
     * @param  array<string, true>  $seen
     * @param  list<string>  $preferredConversions
     */
    private function pushMediaCandidates(
        array &$selected,
        array &$seen,
        HasMedia&Model $model,
        string $collection,
        array $preferredConversions,
        string $role,
        string $reason,
        int $limit,
    ): void {
        /** @var Collection<int, Media> $mediaItems */
        $mediaItems = $model->getMedia($collection)->take($limit);

        foreach ($mediaItems as $media) {
            $mediaKey = (string) $media->getKey();

            if (isset($seen[$mediaKey])) {
                continue;
            }

            $seen[$mediaKey] = true;
            $selected[] = [
                'media' => $media,
                'payload' => $this->mediaPayload(
                    media: $media,
                    source: $this->modelSource($model),
                    role: $role,
                    preferredConversions: $preferredConversions,
                    selected: true,
                    selectionReason: $reason,
                ),
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $referenceMedia
     */
    /**
     * @param  array{
     *   collection: 'cover'|'poster',
     *   label: string,
     *   aspect_ratio: string,
     *   ratio_width: int,
     *   ratio_height: int,
     *   ai_size: string,
     *   output_width: int,
     *   output_height: int
     * }  $target
     * @param  list<array<string, mixed>>  $referenceMedia
     */
    private function prompt(Event $event, array $target, array $referenceMedia, ?string $creativeDirection): string
    {
        $isCover = $target['collection'] === 'cover';
        $lines = [
            $isCover
                ? 'Create a stunning, premium ilmu360° event cover image for website and mobile app display.'
                : 'Create a stunning, premium ilmu360° event poster image for WhatsApp, Instagram, Facebook, TikTok, and external distribution.',
            '',
            'Output target:',
            "- Event {$target['collection']} for the `{$target['collection']}` media collection.",
            "- Required aspect ratio: {$target['aspect_ratio']}.",
            "- Final image must be cleanly crop-safe at {$target['output_width']}x{$target['output_height']} pixels.",
            '- Must be suitable for upload as image/jpeg, image/png, or image/webp.',
            $isCover
                ? '- This is not the full marketing flyer; the website and app already display event facts beside the image.'
                : '- This is the shareable marketing poster; include enough event facts for the image to stand alone outside the app.',
            '',
            $isCover ? 'Core event facts for design context:' : 'Required visible event facts:',
            "- Title: {$event->title}",
            "- Use the title as an ambience anchor: derive mood and scholarly atmosphere from \"{$event->title}\" while keeping all facts accurate.",
        ];

        if (! $isCover) {
            $lines[] = "- Date and time: {$this->dateTimeLine($event)}";
        }

        $location = $this->locationLine($event);

        if ($location !== null && ! $isCover) {
            $lines[] = "- Location: {$location}";
        }

        $speakerNames = $this->speakerNames($event);

        if ($speakerNames !== [] && ! $isCover) {
            $lines[] = '- Speaker(s): '.implode(', ', $speakerNames);
        }

        $keyPeople = $this->nonSpeakerKeyPeopleLine($event);

        if ($keyPeople !== null && ! $isCover) {
            $lines[] = "- Other roles: {$keyPeople}";
        }

        $referenceTitles = $this->referenceTitles($event);

        if ($referenceTitles !== []) {
            $lines[] = '- Reference / kitab: '.implode(', ', $referenceTitles);
        }

        $taxonomy = $this->taxonomyLine($event);

        if ($taxonomy !== null) {
            $lines[] = "- Theme taxonomy: {$taxonomy}";
        }

        if ($creativeDirection !== null) {
            $lines[] = "- Additional creative direction: {$creativeDirection}";
        }

        $lines = [
            ...$lines,
            '',
            'Design direction:',
            '- Use refined Islamic editorial design: confident hierarchy, elegant Malay typography, generous negative space, and contemporary masjid/knowledge visual language.',
            '- Treat the event title as the primary narrative cue for ambience: tune lighting, tone, palette, and setting details to match the meaning and spirit of the title.',
            '- Prefer authentic visual cues from the selected reference media over generic stock imagery.',
            '- If speaker reference images are provided, preserve likeness respectfully. If no actual speaker media is provided, use text-only speaker treatment and do not invent faces.',
            '- If book/reference cover images are provided, use them as subtle study-material cues rather than copying the full cover as the poster.',
            '- Avoid clutter, fake logos, fake QR codes, fake sponsors, fake phone numbers, and unprovided claims.',
            $isCover
                ? '- Keep visible text minimal: title plus at most one short supporting cue such as date, institution, speaker, or kitab when it improves the design.'
                : '- Keep the exact event facts above legible; do not add extra event details not present in source_data.',
        ];

        if ($referenceMedia !== []) {
            $lines[] = '';
            $lines[] = 'Selected reference media to attach/use:';

            foreach ($referenceMedia as $index => $media) {
                $position = $index + 1;
                $label = (string) ($media['label'] ?? $media['file_name'] ?? "Reference media {$position}");
                $role = (string) ($media['role'] ?? 'reference');
                $reason = (string) ($media['selection_reason'] ?? 'Use if helpful.');
                $url = (string) ($media['url'] ?? $media['original_url'] ?? '');
                $urlLine = $url !== ''
                    ? "URL: {$url}"
                    : 'URL: no direct URL available; use attached reference media.';
                $lines[] = "{$position}. {$label} ({$role}) - {$reason} - {$urlLine}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceData(Event $event): array
    {
        return [
            'direct_attributes' => $this->normalizeArray($event->getAttributes()),
            'computed' => [
                'description_text' => $event->description_text,
                'timing_display' => $event->timing_display,
                'full_timing_display' => $event->full_timing_display,
                'reference_study_subtitle' => $event->reference_study_subtitle,
                'card_image_url' => $event->card_image_url,
                'poster_display_aspect_ratio' => $event->poster_display_aspect_ratio,
                'poster_orientation' => $event->poster_orientation,
                'event_type_labels' => $this->eventTypeLabels($event->event_type),
                'age_group_labels' => $this->ageGroupLabels($event->age_group),
                'gender_label' => $this->genderLabel($event->gender),
                'event_format_label' => $this->formatLabel($event->event_format),
                'visibility_label' => $this->visibilityLabel($event->visibility),
                'event_structure_label' => $this->structureLabel($event->event_structure),
            ],
            'relations' => [
                'address' => $this->addressPayload($event->addressModel),
                'institution' => $event->institution instanceof Institution ? $this->modelPayload($event->institution) : null,
                'venue' => $event->venue instanceof Venue ? $this->modelPayload($event->venue) : null,
                'space' => $event->space instanceof Space ? $this->modelPayload($event->space) : null,
                'organizer' => $event->organizer instanceof Model ? $this->modelPayload($event->organizer) : null,
                'key_people' => $event->keyPeople->map(fn (EventKeyPerson $keyPerson): array => $this->keyPersonPayload($keyPerson))->values()->all(),
                'speakers' => $event->speakers->map(fn (Speaker $speaker): array => $this->modelPayload($speaker))->values()->all(),
                'references' => $event->references->map(fn (Reference $reference): array => $this->referencePayload($reference))->values()->all(),
                'series' => $event->series->map(fn (Series $series): array => $this->modelPayload($series))->values()->all(),
                'tags' => $this->tagsPayload($event->tags),
                'languages' => $event->languages->map(fn (Language $language): array => $this->languagePayload($language))->values()->all(),
                'access_policy' => $event->accessPolicy instanceof EventAccessPolicy ? $this->modelPayload($event->accessPolicy) : null,
                'donation_channel' => $event->donationChannel instanceof DonationChannel ? $this->modelPayload($event->donationChannel) : null,
                'media_links' => $event->mediaLinks->map(fn (MediaLink $mediaLink): array => $this->modelPayload($mediaLink))->values()->all(),
                'parent_event' => $event->parentEvent instanceof Event ? $this->relatedEventPayload($event->parentEvent) : null,
                'child_events' => $event->childEvents->map(fn (Event $childEvent): array => $this->relatedEventPayload($childEvent))->values()->all(),
            ],
            'available_media' => $this->modelMediaPayloads($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function keyPersonPayload(EventKeyPerson $keyPerson): array
    {
        return [
            ...$this->modelPayload($keyPerson),
            'display_name' => $keyPerson->display_name,
            'role_label' => $this->roleLabel($keyPerson->role),
            'speaker' => $keyPerson->speaker instanceof Speaker ? $this->modelPayload($keyPerson->speaker) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referencePayload(Reference $reference): array
    {
        return [
            ...$this->modelPayload($reference),
            'display_title' => $reference->displayTitle(),
            'type_label' => ReferenceType::tryFrom((string) $reference->typeValue())?->getLabel(),
            'is_part' => $reference->isPart(),
            'parent_reference' => $reference->parentReference instanceof Reference ? $this->modelPayload($reference->parentReference) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relatedEventPayload(Event $event): array
    {
        return [
            'type' => $event->getMorphClass(),
            'class' => $event::class,
            'attributes' => $this->normalizeArray($event->getAttributes()),
            'computed' => [
                'title' => (string) $event->title,
                'route_key' => (string) $event->getRouteKey(),
                'timing_display' => $event->timing_display,
                'card_image_url' => $event->card_image_url,
            ],
            'media' => $this->modelMediaPayloads($event),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelPayload(Model $model): array
    {
        return [
            'type' => $model->getMorphClass(),
            'class' => $model::class,
            'attributes' => $this->normalizeArray($model->getAttributes()),
            'label' => $this->modelLabel($model),
            'address' => $this->modelAddressPayload($model),
            'media' => $model instanceof HasMedia ? $this->modelMediaPayloads($model) : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function modelAddressPayload(Model $model): ?array
    {
        if (! method_exists($model, 'getAddressModelAttribute')) {
            return null;
        }

        $address = $model->getAttribute('addressModel');

        return $address instanceof Address ? $this->addressPayload($address) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function addressPayload(?Address $address): ?array
    {
        if (! $address instanceof Address) {
            return null;
        }

        return [
            'attributes' => $this->normalizeArray($address->getAttributes()),
            'display_line' => AddressHierarchyFormatter::format($address),
            'display_lines' => AddressHierarchyFormatter::displayLines($address),
            'country' => $address->country instanceof Model ? $address->country->name : $address->country,
            'state' => $address->state,
            'district' => $address->city,
            'subdistrict' => $address->neighbourhood,
            'city' => $address->city,
        ];
    }

    /**
     * @param  Collection<int, Model>  $tags
     * @return array<string, list<array<string, mixed>>>
     */
    private function tagsPayload(Collection $tags): array
    {
        $grouped = [];

        foreach (TagType::cases() as $type) {
            $grouped[$type->value] = [];
        }

        foreach ($tags as $tag) {
            if (! $tag instanceof Tag) {
                continue;
            }

            $type = is_string($tag->type) && $tag->type !== '' ? $tag->type : 'unknown';
            $grouped[$type] ??= [];
            $grouped[$type][] = [
                'id' => (string) $tag->getKey(),
                'name' => $this->tagName($tag),
                'type' => $type,
                'status' => (string) $tag->status,
                'attributes' => $this->normalizeArray($tag->getAttributes()),
            ];
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    private function languagePayload(Language $language): array
    {
        return [
            'id' => (string) $language->getKey(),
            'code' => $this->stringAttribute($language, 'code'),
            'name' => $this->stringAttribute($language, 'name'),
            'native' => $this->stringAttribute($language, 'native'),
            'attributes' => $this->normalizeArray($language->getAttributes()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modelMediaPayloads(HasMedia&Model $model): array
    {
        $collections = self::MEDIA_COLLECTIONS[$model::class] ?? [];
        $media = [];

        foreach ($collections as $collection => $preferredConversions) {
            /** @var Collection<int, Media> $items */
            $items = $model->getMedia($collection);

            foreach ($items as $item) {
                $media[] = $this->mediaPayload(
                    media: $item,
                    source: $this->modelSource($model),
                    role: "{$collection}_media",
                    preferredConversions: $preferredConversions,
                    selected: false,
                    selectionReason: null,
                );
            }
        }

        return $media;
    }

    /**
     * @param  array{type: string, class: class-string<Model>, id: string, label: string|null}  $source
     * @param  list<string>  $preferredConversions
     * @return array<string, mixed>
     */
    private function mediaPayload(
        Media $media,
        array $source,
        string $role,
        array $preferredConversions,
        bool $selected,
        ?string $selectionReason,
    ): array {
        $selectedConversion = $this->selectedConversion($media, $preferredConversions);
        $useTemporaryUrl = $this->diskSupportsTemporaryUrls($media->disk);
        $expiry = now()->startOfHour()->addHours(2);

        if ($useTemporaryUrl) {
            $url = $preferredConversions === []
                ? $media->getTemporaryUrl($expiry)
                : $media->getAvailableTemporaryUrl($preferredConversions, $expiry);
            $originalUrl = $media->getTemporaryUrl($expiry);
        } else {
            $url = $preferredConversions === [] ? $media->getUrl() : $media->getAvailableUrl($preferredConversions);
            $originalUrl = $media->getUrl();
        }

        $sourceDimensions = $media->getCustomProperty('source_dimensions', []);

        return [
            'id' => (string) $media->getKey(),
            'role' => $role,
            'source' => $source,
            'collection' => (string) $media->collection_name,
            'conversion' => $selectedConversion,
            'preferred_conversions' => $preferredConversions,
            'label' => $media->name !== '' ? $media->name : $media->file_name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => (int) $media->size,
            'url' => $url !== '' ? $url : null,
            'original_url' => $originalUrl !== '' ? $originalUrl : null,
            'custom_properties' => $this->normalizeArray($media->custom_properties ?? []),
            'source_dimensions' => is_array($sourceDimensions) ? $this->normalizeArray($sourceDimensions) : [],
            'selected_for_prompt' => $selected,
            'selection_reason' => $selectionReason,
        ];
    }

    private function diskSupportsTemporaryUrls(?string $disk): bool
    {
        if (! is_string($disk) || $disk === '') {
            return false;
        }

        try {
            return Storage::disk($disk)->providesTemporaryUrls();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $preferredConversions
     */
    private function selectedConversion(Media $media, array $preferredConversions): ?string
    {
        foreach ($preferredConversions as $conversion) {
            if ($media->hasGeneratedConversion($conversion)) {
                return $conversion;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, class: class-string<Model>, id: string, label: string|null}
     */
    private function modelSource(Model $model): array
    {
        return [
            'type' => $model->getMorphClass(),
            'class' => $model::class,
            'id' => (string) $model->getKey(),
            'label' => $this->modelLabel($model),
        ];
    }

    private function modelLabel(Model $model): ?string
    {
        foreach (['display_name', 'formatted_name', 'display_title', 'title', 'name', 'label'] as $attribute) {
            $value = $this->stringAttribute($model, $attribute);

            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function dateTimeLine(Event $event): string
    {
        $timezone = $this->eventTimezone($event);
        $startsAt = $this->inEventTimezone($event->starts_at, $timezone);
        $endsAt = $this->inEventTimezone($event->ends_at, $timezone);

        $date = $startsAt?->translatedFormat('l, j F Y');
        $start = $startsAt?->format('g:i A');
        $end = $endsAt?->format('g:i A');

        return trim(implode(' ', array_filter([
            $date,
            ($start !== null && $end !== null) ? "{$start} - {$end}" : $start,
            "({$timezone})",
        ])));
    }

    private function eventTimezone(Event $event): string
    {
        $timezone = is_string($event->timezone) ? trim($event->timezone) : '';

        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function inEventTimezone(?DateTimeInterface $dateTime, string $timezone): ?Carbon
    {
        if (! $dateTime instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($dateTime)->setTimezone($timezone);
    }

    private function locationLine(Event $event): ?string
    {
        $parts = [];

        if ($event->institution instanceof Institution) {
            $parts[] = $event->institution->display_name;
        }

        if ($event->venue instanceof Venue) {
            $parts[] = (string) $event->venue->name;
        }

        if ($event->space instanceof Space) {
            $parts[] = (string) $event->space->name;
        }

        $address = null;

        if ($event->venue instanceof Venue) {
            $address = $event->venue->addressModel;
        }

        if (! $address instanceof Address && $event->institution instanceof Institution) {
            $address = $event->institution->addressModel;
        }

        if (! $address instanceof Address) {
            $address = $event->addressModel;
        }

        $addressLine = AddressHierarchyFormatter::format($address);

        if ($addressLine !== '') {
            $parts[] = $addressLine;
        }

        $line = trim(implode(', ', array_filter($parts)));

        return $line !== '' ? $line : null;
    }

    /**
     * @return list<string>
     */
    private function speakerNames(Event $event): array
    {
        return $event->keyPeople
            ->filter(fn (EventKeyPerson $keyPerson): bool => $this->roleValue($keyPerson->role) === EventKeyPersonRole::Speaker->value)
            ->map(fn (EventKeyPerson $keyPerson): string => $keyPerson->display_name)
            ->filter(fn (string $name): bool => trim($name) !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function nonSpeakerKeyPeopleLine(Event $event): ?string
    {
        $people = $event->keyPeople
            ->reject(fn (EventKeyPerson $keyPerson): bool => $this->roleValue($keyPerson->role) === EventKeyPersonRole::Speaker->value)
            ->map(fn (EventKeyPerson $keyPerson): string => trim($this->roleLabel($keyPerson->role).': '.$keyPerson->display_name))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();

        return $people !== [] ? implode('; ', $people) : null;
    }

    /**
     * @return list<string>
     */
    private function referenceTitles(Event $event): array
    {
        return $event->references
            ->map(fn (Reference $reference): string => $reference->displayTitle())
            ->filter(fn (string $title): bool => trim($title) !== '')
            ->values()
            ->all();
    }

    private function taxonomyLine(Event $event): ?string
    {
        $labels = array_merge(
            $this->eventTypeLabels($event->event_type),
            $event->tags
                ->filter(fn (Model $tag): bool => $tag instanceof Tag)
                ->map(fn (Tag $tag): string => $this->tagName($tag))
                ->filter()
                ->values()
                ->all(),
        );

        return $labels !== [] ? implode(', ', array_unique($labels)) : null;
    }

    /**
     * @return list<string>
     */
    private function eventTypeLabels(mixed $values): array
    {
        return $this->enumLabels($values, EventType::class);
    }

    /**
     * @return list<string>
     */
    private function ageGroupLabels(mixed $values): array
    {
        return $this->enumLabels($values, EventAgeGroup::class);
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<string>
     */
    private function enumLabels(mixed $values, string $enumClass): array
    {
        $items = $values instanceof Collection ? $values->all() : Arr::wrap($values);
        $labels = [];

        foreach ($items as $item) {
            $enum = $item instanceof $enumClass ? $item : (is_string($item) ? $enumClass::tryFrom($item) : null);

            if ($enum instanceof EventType || $enum instanceof EventAgeGroup) {
                $labels[] = $enum->getLabel();
            } elseif ($enum instanceof BackedEnum) {
                $labels[] = (string) $enum->value;
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    private function roleLabel(mixed $role): string
    {
        if ($role instanceof EventKeyPersonRole) {
            return $role->getLabel();
        }

        return EventKeyPersonRole::tryFrom((string) $role)?->getLabel() ?? (string) $role;
    }

    private function roleValue(mixed $role): string
    {
        return $role instanceof EventKeyPersonRole ? $role->value : (string) $role;
    }

    private function genderLabel(mixed $gender): ?string
    {
        if ($gender instanceof EventGenderRestriction) {
            return $gender->getLabel();
        }

        return EventGenderRestriction::tryFrom((string) $gender)?->getLabel();
    }

    private function formatLabel(mixed $format): ?string
    {
        if ($format instanceof EventFormat) {
            return $format->label();
        }

        return EventFormat::tryFrom((string) $format)?->label();
    }

    private function visibilityLabel(mixed $visibility): ?string
    {
        if ($visibility instanceof EventVisibility) {
            return $visibility->getLabel();
        }

        return EventVisibility::tryFrom((string) $visibility)?->getLabel();
    }

    private function structureLabel(mixed $structure): ?string
    {
        if ($structure instanceof EventStructure) {
            return $structure->label();
        }

        return EventStructure::tryFrom((string) $structure)?->label();
    }

    private function tagName(Tag $tag): string
    {
        $name = $tag->getAttribute('name');

        if (is_string($name)) {
            return $name;
        }

        if (is_array($name)) {
            $currentLocale = app()->getLocale();
            $localized = data_get($name, $currentLocale) ?? data_get($name, 'ms') ?? data_get($name, 'en');

            if (is_string($localized) && $localized !== '') {
                return $localized;
            }

            foreach ($name as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return '';
        }

        return '';
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        try {
            $value = $model->getAttribute($attribute);
        } catch (MissingAttributeException) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeArray(array $values): array
    {
        return array_map($this->normalizeValue(...), $values);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Collection) {
            return $value->map($this->normalizeValue(...))->values()->all();
        }

        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        return $value;
    }
}
