<?php

namespace App\Services\Ai;

use App\Ai\Agents\EventMediaExtractionAgent;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\TagType;
use App\Models\Tag;
use ArrayAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Nnjeim\World\Models\Language;
use RuntimeException;
use Throwable;

class EventMediaExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extract(UploadedFile $file): array
    {
        $provider = config('ai.features.event_media_extraction.provider');
        $model = config('ai.features.event_media_extraction.model');

        $resolvedProvider = is_string($provider) && filled($provider) ? $provider : null;
        $resolvedModel = is_string($model) && filled($model) ? $model : null;

        if ($resolvedProvider && $resolvedProvider !== 'ollama') {
            $providerKey = config("ai.providers.{$resolvedProvider}.key");

            if (! is_string($providerKey) || blank($providerKey)) {
                throw new RuntimeException('AI provider key is missing for event media extraction.');
            }
        }

        try {
            $response = EventMediaExtractionAgent::make(context: $this->buildContext())->prompt(
                prompt: 'Extract event details from this event poster/image/pdf into structured fields for a submission form.',
                attachments: [$this->resolveAttachment($file)],
                provider: $resolvedProvider,
                model: $resolvedModel,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to extract event data from media.', $exception->getCode(), previous: $exception);
        }

        return $this->normalizePayload($this->extractResponsePayload($response));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractResponsePayload(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (! $response instanceof ArrayAccess) {
            return [];
        }

        $keys = [
            'title',
            'description',
            'event_date',
            'prayer_time',
            'custom_time',
            'end_time',
            'event_type',
            'event_format',
            'visibility',
            'event_url',
            'live_url',
            'gender',
            'age_group',
            'children_allowed',
            'is_muslim_only',
            'language_codes',
            'domain_tag_ids',
            'source_tag_ids',
            'discipline_tags',
            'issue_tags',
        ];

        $payload = [];

        foreach ($keys as $key) {
            $value = $response[$key] ?? null;

            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContext(): array
    {
        return [
            'event_type_values' => array_column(EventType::cases(), 'value'),
            'event_type_labels' => collect(EventType::cases())->mapWithKeys(
                fn (EventType $case): array => [$case->value => $case->getLabel()]
            )->all(),
            'prayer_time_values' => array_column(EventPrayerTime::cases(), 'value'),
            'prayer_time_labels' => collect(EventPrayerTime::cases())->mapWithKeys(
                fn (EventPrayerTime $case): array => [$case->value => $case->getLabel()]
            )->all(),
            'event_format_values' => array_column(EventFormat::cases(), 'value'),
            'visibility_values' => array_column(EventVisibility::cases(), 'value'),
            'gender_values' => array_column(EventGenderRestriction::cases(), 'value'),
            'age_group_values' => array_column(EventAgeGroup::cases(), 'value'),
            'language_codes' => $this->availableLanguageCodes(),
            'domain_tag_options' => $this->tagOptions(TagType::Domain)->all(),
            'source_tag_options' => $this->tagOptions(TagType::Source)->all(),
            'discipline_tag_options' => $this->tagOptions(TagType::Discipline)->all(),
            'issue_tag_options' => $this->tagOptions(TagType::Issue)->all(),
        ];
    }

    protected function resolveAttachment(UploadedFile $file): Image|Document
    {
        $mimeType = (string) $file->getMimeType();

        if (str_starts_with($mimeType, 'image/')) {
            return Image::fromUpload($file);
        }

        return Document::fromUpload($file);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $payload): array
    {
        $eventTypeValues = $this->normalizeEnumArray($payload['event_type'] ?? [], EventType::class, limit: 3);
        $prayerTime = $this->normalizeEnumValue($payload['prayer_time'] ?? null, EventPrayerTime::class);
        $customTime = $this->normalizeTime($payload['custom_time'] ?? null);

        if (filled($customTime) && blank($prayerTime)) {
            $prayerTime = EventPrayerTime::LainWaktu->value;
        }

        $normalized = [
            'title' => $this->normalizeText($payload['title'] ?? null, 255),
            'description' => $this->normalizeDescription($payload['description'] ?? null),
            'event_date' => $this->normalizeDate($payload['event_date'] ?? null),
            'prayer_time' => $prayerTime,
            'custom_time' => $customTime,
            'end_time' => $this->normalizeTime($payload['end_time'] ?? null),
            'event_type' => $eventTypeValues,
            'event_format' => $this->normalizeEnumValue($payload['event_format'] ?? null, EventFormat::class),
            'visibility' => $this->normalizeEnumValue($payload['visibility'] ?? null, EventVisibility::class),
            'event_url' => $this->normalizeUrl($payload['event_url'] ?? null),
            'live_url' => $this->normalizeUrl($payload['live_url'] ?? null),
            'gender' => $this->normalizeEnumValue($payload['gender'] ?? null, EventGenderRestriction::class),
            'age_group' => $this->normalizeEnumArray($payload['age_group'] ?? [], EventAgeGroup::class, limit: 5),
            'children_allowed' => $this->normalizeBoolean($payload['children_allowed'] ?? null),
            'is_muslim_only' => $this->normalizeBoolean($payload['is_muslim_only'] ?? null),
            'languages' => $this->mapLanguageCodesToIds($payload['language_codes'] ?? []),
            'domain_tags' => $this->resolveTagIds(TagType::Domain, $payload['domain_tag_ids'] ?? [], limit: 3),
            'source_tags' => $this->resolveTagIds(TagType::Source, $payload['source_tag_ids'] ?? [], limit: 5),
            'discipline_tags' => $this->resolveTagValues(TagType::Discipline, $payload['discipline_tags'] ?? [], limit: 5),
            'issue_tags' => $this->resolveTagValues(TagType::Issue, $payload['issue_tags'] ?? [], limit: 5),
        ];

        return array_filter(
            $normalized,
            fn (mixed $value): bool => ! (in_array($value, [null, [], ''], true))
        );
    }

    /**
     * @return Collection<string, string>
     */
    protected function tagOptions(TagType $tagType): Collection
    {
        return Tag::query()
            ->where('type', $tagType->value)
            ->whereIn('status', ['verified', 'pending'])
            ->orderBy('order_column')
            ->get()
            ->mapWithKeys(function (Tag $tag): array {
                $label = $tag->getTranslation('name', app()->getLocale());

                if (! is_string($label) || blank($label)) {
                    $label = is_array($tag->name)
                        ? ((string) ($tag->name[app()->getLocale()] ?? $tag->name['ms'] ?? $tag->name['en'] ?? ''))
                        : (string) $tag->name;
                }

                return [(string) $tag->id => $label];
            });
    }

    protected function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return Str::limit($normalized, $maxLength, '');
    }

    protected function normalizeDescription(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim(strip_tags((string) $value));

        if ($normalized === '') {
            return null;
        }

        return nl2br(e(Str::limit($normalized, 5000, '')));
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function normalizeTime(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw) === 1) {
            return $raw;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (Throwable) {
            return null;
        }
    }

    protected function normalizeUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        return filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null;
    }

    protected function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', 'yes', 'ya', '1' => true,
            'false', 'no', 'tidak', '0' => false,
            default => null,
        };
    }

    protected function normalizeEnumValue(mixed $value, string $enumClass): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $enum = $enumClass::tryFrom($raw);

        return $enum?->value;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeEnumArray(mixed $value, string $enumClass, int $limit): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->map(fn (mixed $item): ?string => $this->normalizeEnumValue($item, $enumClass))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function mapLanguageCodesToIds(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $codes = collect($value)
            ->map(fn (mixed $code): string => strtolower(trim((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return [];
        }

        return Language::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function resolveTagIds(TagType $tagType, mixed $value, int $limit): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $existingTagIds = Tag::query()
            ->where('type', $tagType->value)
            ->whereIn('status', ['verified', 'pending'])
            ->whereIn('id', collect($value)->filter('is_string')->values()->all())
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return collect($existingTagIds)
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function resolveTagValues(TagType $tagType, mixed $value, int $limit): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        $tags = Tag::query()
            ->where('type', $tagType->value)
            ->whereIn('status', ['verified', 'pending'])
            ->get();

        $resolved = [];

        foreach ($value as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            if (Str::isUuid($candidate) && $tags->contains('id', $candidate)) {
                $resolved[] = $candidate;

                continue;
            }

            $matchedTag = $tags->first(function (Tag $tag) use ($candidate): bool {
                $normalizedCandidate = $this->normalizeKeyword($candidate);

                return collect($this->tagSearchLabels($tag))
                    ->map(fn (string $label): string => $this->normalizeKeyword($label))
                    ->contains($normalizedCandidate);
            });

            if ($matchedTag) {
                $resolved[] = (string) $matchedTag->id;

                continue;
            }

            $resolved[] = Str::limit($candidate, 120, '');
        }

        return collect($resolved)
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function tagSearchLabels(Tag $tag): array
    {
        if (is_array($tag->name)) {
            $labels = array_values(array_filter(
                $tag->name,
                filled(...)
            ));

            return array_map(
                fn (mixed $value): string => (string) $value,
                $labels,
            );
        }

        return filled($tag->name) ? [(string) $tag->name] : [];
    }

    protected function normalizeKeyword(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9 ]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    /**
     * @return array<int, string>
     */
    protected function availableLanguageCodes(): array
    {
        $preferredCodes = ['ms', 'ar', 'en', 'id', 'zh', 'ta', 'jv', 'ur', 'bn'];

        return Language::query()
            ->whereIn('code', $preferredCodes)
            ->pluck('code')
            ->map(fn (mixed $code): string => strtolower((string) $code))
            ->values()
            ->all();
    }
}
