<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Ui;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Presentation helpers for the contributions hub across every contribution
 * family. Read-only: it never queries or mutates a foreign feature.
 */
class ContributionsHubPresenter
{
    /** @var list<string> */
    private const INTERNAL_PAYLOAD_KEYS = ['submission_key', '_token'];

    /**
     * Public URL of the request entity, or null when the record is not public.
     */
    public function requestPublicUrl(ContributionRequest $request): ?string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => in_array($this->statusValue($entity), InstitutionStatus::publiclyVisibleValues(), true)
                ? route('institutions.show', $entity)
                : null,
            $entity instanceof Person => in_array($this->statusValue($entity), ['verified', 'pending'], true)
                ? route('persons.show', $entity)
                : null,
            $entity instanceof Reference => $entity->isPubliclyVisible()
                ? route('references.show', $entity)
                : null,
            $entity instanceof Event => $entity->isPubliclyReachable()
                ? route('events.show', $entity)
                : null,
            default => null,
        };
    }

    public function subjectLabel(ContributionSubjectType|string $type): string
    {
        $value = $type instanceof ContributionSubjectType ? $type->value : $type;

        return match ($value) {
            ContributionSubjectType::Event->value => __('Event'),
            ContributionSubjectType::Institution->value => __('Institution'),
            ContributionSubjectType::Person->value => __('Speaker'),
            ContributionSubjectType::Reference->value => __('Reference'),
            default => str($value)->headline()->toString(),
        };
    }

    public function requestTypeLabel(ContributionRequestType|string $type): string
    {
        $value = $type instanceof ContributionRequestType ? $type->value : $type;

        return match ($value) {
            ContributionRequestType::Create->value => __('New Submission'),
            ContributionRequestType::Update->value => __('Update Suggestion'),
            default => str($value)->headline()->toString(),
        };
    }

    public function entityTitle(ContributionRequest $request): string
    {
        return ContributionRequestPresenter::entityTitle($request);
    }

    public function statusTone(string $status): string
    {
        return match ($status) {
            'approved', 'published', 'live', 'completed', 'resolved', 'verified', 'granted', 'converted' => 'emerald',
            'rejected', 'declined' => 'rose',
            'cancelled', 'dismissed', 'archived', 'withdrawn' => 'neutral',
            'triaged', 'scheduled', 'in_review' => 'sky',
            'open', 'pending', 'draft', 'new', 'needs_changes', 'changes_requested', 'postponed', 'rescheduled', 'delayed' => 'amber',
            default => 'neutral',
        };
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('Pending'),
            'approved' => __('Approved'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
            'open' => __('Open'),
            'triaged' => __('Triaged'),
            'resolved' => __('Resolved'),
            'dismissed' => __('Dismissed'),
            'new' => __('New'),
            'declined' => __('Declined'),
            'published' => __('Published'),
            'draft' => __('Draft'),
            'needs_changes', 'changes_requested' => __('Needs Changes'),
            'postponed' => __('Postponed'),
            'rescheduled' => __('Rescheduled'),
            'delayed' => __('Delayed'),
            'live' => __('Live'),
            'completed' => __('Completed'),
            'scheduled' => __('Scheduled'),
            'archived' => __('Archived'),
            'verified' => __('Verified'),
            default => str($status)->headline()->toString(),
        };
    }

    public function rejectionReasonLabel(?string $reasonCode): ?string
    {
        if (! filled($reasonCode)) {
            return null;
        }

        $label = ContributionRequestPresenter::rejectionReasonOptions()[$reasonCode]
            ?? str($reasonCode)->headline()->toString();

        return __($label);
    }

    public function formatDate(CarbonInterface|string|null $date): ?string
    {
        $carbon = $this->toCarbon($date);

        return $carbon instanceof CarbonInterface
            ? UserDateTimeFormatter::translatedFormat($carbon, 'j M Y')
            : null;
    }

    public function formatDateTime(CarbonInterface|string|null $date): ?string
    {
        $carbon = $this->toCarbon($date);

        return $carbon instanceof CarbonInterface
            ? UserDateTimeFormatter::translatedFormat($carbon, 'j M Y, h:i A')
            : null;
    }

    public function fieldLabel(string $key): string
    {
        return match ($key) {
            'name' => __('Name'),
            'title' => __('Title'),
            'family_name' => __('Family Name'),
            'middle_name' => __('Middle Name'),
            'gender' => __('Gender'),
            'type' => __('Type'),
            'record_kind' => __('Record Kind'),
            'names' => __('Alternative Names'),
            'title_ids' => __('Titles'),
            'description' => __('Description'),
            'biography', 'bio' => __('Biography'),
            'notes' => __('Notes'),
            'institutions' => __('Affiliated Institutions'),
            'authors', 'author_ids' => __('Authors'),
            'persons', 'person_ids' => __('Speakers'),
            'references', 'reference_ids' => __('References'),
            'language', 'language_ids' => __('Languages'),
            'event_category_ids' => __('Event Categories'),
            'domain_tags' => __('Main Topics'),
            'discipline_tags' => __('Disciplines'),
            'source_tags' => __('Sources'),
            'issue_tags' => __('Current Issues'),
            'address' => __('Address'),
            'contactMethods' => __('Contact Details'),
            'social_media' => __('Social Media'),
            'donation_channels' => __('Donation Channels'),
            'website', 'url' => __('Website'),
            'event_date' => __('Event Date'),
            'prayer_time' => __('Prayer Time'),
            'custom_time' => __('Start Time'),
            'end_time' => __('End Time'),
            'timezone' => __('Timezone'),
            'event_format' => __('Event Format'),
            'visibility' => __('Visibility'),
            'event_url' => __('Event Link'),
            'live_url' => __('Livestream Link'),
            'recording_url' => __('Recording Link'),
            'age_group' => __('Age Group'),
            'children_allowed' => __('Children Allowed'),
            'is_muslim_only' => __('Muslims Only'),
            'primary_organizer_id' => __('Primary Organizer'),
            'location_same_as_institution' => __('Location Same as Institution'),
            'location_type' => __('Location Type'),
            'location_institution_id', 'location_venue_id' => __('Location'),
            'publisher' => __('Publisher'),
            'publication_year', 'year' => __('Publication Year'),
            'isbn' => __('ISBN'),
            'edition_number', 'edition_label' => __('Edition'),
            'part_type', 'part_number', 'part_label' => __('Part / Volume'),
            default => Str::headline($key),
        };
    }

    public function mediaFieldLabel(string $field): string
    {
        return match ($field) {
            'avatar', 'profile' => __('Profile Photo'),
            'square' => __('Square Photo'),
            'logo' => __('Logo'),
            'cover' => __('Cover Image'),
            'poster' => __('Event Poster'),
            'main' => __('Main Image'),
            'front_cover' => __('Front Cover'),
            'back_cover' => __('Back Cover'),
            'gallery' => __('Gallery'),
            'donation_qr' => __('Donation QR Code'),
            default => __('Media'),
        };
    }

    public function formatPayloadValue(string $key, mixed $value, int $maxLength = 320): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if (is_string($value)) {
            return $this->formatTextValue($key, $value, $maxLength);
        }

        if (is_array($value)) {
            return $this->formatArrayValue($key, $value, $maxLength);
        }

        if ($value instanceof BackedEnum) {
            return Str::limit((string) $value->value, $maxLength);
        }

        if ($value instanceof DateTimeInterface) {
            return $this->formatDateTime(CarbonImmutable::parse($value)) ?? '—';
        }

        if (is_int($value) || is_float($value)) {
            return Str::limit((string) $value, $maxLength);
        }

        return '—';
    }

    /**
     * @return list<array{key: string, field: string, original: string, proposed: string}>
     */
    public function changedFieldSummaries(ContributionRequest $request, int $maxLength = 240): array
    {
        /** @var array<mixed> $proposed */
        $proposed = $request->proposed_data ?? [];
        /** @var array<mixed> $original */
        $original = $request->original_data ?? [];

        $summaries = [];

        foreach ($proposed as $key => $proposedValue) {
            $fieldKey = (string) $key;

            if (in_array($fieldKey, self::INTERNAL_PAYLOAD_KEYS, true)) {
                continue;
            }

            if ($proposedValue === ($original[$key] ?? null)) {
                continue;
            }

            $summaries[] = [
                'key' => $fieldKey,
                'field' => $this->fieldLabel($fieldKey),
                'original' => $this->formatPayloadValue($fieldKey, $original[$key] ?? null, $maxLength),
                'proposed' => $this->formatPayloadValue($fieldKey, $proposedValue, $maxLength),
            ];
        }

        return $summaries;
    }

    /**
     * @return list<array{key: string, field: string, value: string}>
     */
    public function submittedFieldSummaries(ContributionRequest $request, int $maxLength = 320): array
    {
        /** @var array<mixed> $proposed */
        $proposed = $request->proposed_data ?? [];

        $summaries = [];

        foreach ($proposed as $key => $value) {
            $fieldKey = (string) $key;

            if (in_array($fieldKey, self::INTERNAL_PAYLOAD_KEYS, true)) {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $summaries[] = [
                'key' => $fieldKey,
                'field' => $this->fieldLabel($fieldKey),
                'value' => $this->formatPayloadValue($fieldKey, $value, $maxLength),
            ];
        }

        return $summaries;
    }

    /**
     * @return list<array{id: string, field: string, field_label: string, name: string, url: string, is_image: bool}>
     */
    public function stagedMediaItems(ContributionRequest $request): array
    {
        /** @var list<array{id: string, field: string, field_label: string, name: string, url: string, is_image: bool}> $items */
        $items = $request->getMedia('pending_media')
            ->map(function (Media $media): array {
                $field = $media->getCustomProperty('contribution_field', 'media');
                $field = is_string($field) && $field !== '' ? $field : 'media';

                $originalName = $media->getCustomProperty('original_file_name');
                $name = is_string($originalName) && $originalName !== ''
                    ? $originalName
                    : (string) ($media->name ?: $media->file_name);

                return [
                    'id' => (string) $media->getKey(),
                    'field' => $field,
                    'field_label' => $this->mediaFieldLabel($field),
                    'name' => $name,
                    'url' => $media->getUrl(),
                    'is_image' => str_starts_with((string) $media->mime_type, 'image/'),
                ];
            })
            ->values()
            ->all();

        return $items;
    }

    public function requestSummaryLine(ContributionRequest $request): ?string
    {
        $mediaLine = $this->mediaSummaryLine($request);

        if ($request->type === ContributionRequestType::Update) {
            $changes = $this->changedFieldSummaries($request, 140);
            $labels = array_map(static fn (array $change): string => $change['field'], $changes);
            $total = count($labels);
            $labels = array_slice($labels, 0, 3);

            if ($labels !== []) {
                if ($total > 3) {
                    $labels[] = '+'.($total - 3);
                }

                $summary = __('Changed: :fields', ['fields' => implode(', ', $labels)]);

                return $mediaLine === null ? $summary : $summary.' · '.$mediaLine;
            }

            if ($mediaLine !== null) {
                return __('Media uploaded: :fields', ['fields' => $mediaLine]);
            }

            return $this->notePreview($request);
        }

        $labels = [];

        foreach ($this->submittedFieldSummaries($request, 140) as $field) {
            if (in_array($field['key'], ['name', 'title'], true)) {
                continue;
            }

            $labels[] = $field['field'];

            if (count($labels) === 3) {
                break;
            }
        }

        if ($labels !== []) {
            $summary = implode(', ', $labels);

            return $mediaLine === null ? $summary : $summary.' · '.$mediaLine;
        }

        if ($mediaLine !== null) {
            return __('Media uploaded: :fields', ['fields' => $mediaLine]);
        }

        return $this->notePreview($request);
    }

    private function formatTextValue(string $key, string $value, int $maxLength): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '—';
        }

        if (in_array($key, ['description', 'biography', 'bio'], true) || str_contains($trimmed, '<')) {
            $decoded = html_entity_decode(strip_tags($trimmed), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $flattened = trim((string) preg_replace('/\s+/', ' ', $decoded));

            return $flattened === '' ? '—' : Str::limit($flattened, $maxLength);
        }

        if (Str::isUuid($trimmed)) {
            return __('Selected record');
        }

        return Str::limit($trimmed, $maxLength);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function formatArrayValue(string $key, array $value, int $maxLength): string
    {
        if ($key === 'address') {
            return $this->formatAddressValue($value, $maxLength);
        }

        if ($this->isUuidList($key, $value)) {
            $count = count($value);

            return (string) trans_choice(':count item selected|:count items selected', $count, ['count' => $count]);
        }

        if ($this->isListOfArrays($value)) {
            /** @var list<array<mixed>> $value */
            $items = match ($key) {
                'names' => $this->formatNames($value),
                'social_media' => $this->formatSocialProfiles($value),
                'contactMethods' => $this->formatContactMethods($value),
                'donation_channels' => $this->formatDonationChannels($value),
                'institutions' => $this->formatInstitutions($value),
                default => $this->flattenScalars($value),
            };

            return $items === [] ? '—' : Str::limit(implode(', ', $items), $maxLength);
        }

        $scalars = $this->flattenScalars($value);

        return $scalars === [] ? '—' : Str::limit(implode(', ', $scalars), $maxLength);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function formatAddressValue(array $value, int $maxLength): string
    {
        $parts = [];

        foreach (['line1', 'line2', 'postcode', 'city', 'state', 'country'] as $field) {
            $text = $this->scalarText($value[$field] ?? null);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if (filled($value['google_maps_url'] ?? null) || filled($value['google_place_id'] ?? null)) {
            $parts[] = __('Google Maps location');
        }

        if (filled($value['waze_url'] ?? null)) {
            $parts[] = __('Waze link');
        }

        return $parts === [] ? __('Location updated') : Str::limit(implode(', ', $parts), $maxLength);
    }

    /**
     * @param  list<array<mixed>>  $items
     * @return list<string>
     */
    private function formatNames(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $fullName = $this->scalarText($item['full_name'] ?? null);
            $nameType = $this->scalarText($item['name_type'] ?? null);

            if ($fullName === '') {
                continue;
            }

            $labels[] = $nameType === '' ? $fullName : $fullName.' · '.Str::headline($nameType);
        }

        return $labels;
    }

    /**
     * @param  list<array<mixed>>  $items
     * @return list<string>
     */
    private function formatSocialProfiles(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $platform = $this->scalarText($item['platform'] ?? null);
            $handle = $this->scalarText($item['handle'] ?? null) ?: $this->scalarText($item['url'] ?? null);

            if ($platform === '' && $handle === '') {
                continue;
            }

            $labels[] = $platform === ''
                ? $handle
                : Str::ucfirst($platform).': '.$handle;
        }

        return $labels;
    }

    /**
     * @param  list<array<mixed>>  $items
     * @return list<string>
     */
    private function formatContactMethods(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $value = $this->scalarText($item['value'] ?? null);

            if ($value === '') {
                continue;
            }

            $type = $this->scalarText($item['type'] ?? null);
            $labels[] = $type === '' ? $value : Str::ucfirst($type).': '.$value;
        }

        return $labels;
    }

    /**
     * @param  list<array<mixed>>  $items
     * @return list<string>
     */
    private function formatDonationChannels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $bankName = $this->scalarText($item['bank_name'] ?? null);
            $method = $this->scalarText($item['method'] ?? null);
            $parts = array_values(array_filter([
                $bankName !== '' ? $bankName : $method,
                $this->scalarText($item['label'] ?? null),
                $this->scalarText($item['recipient'] ?? null),
                $this->scalarText($item['account_number'] ?? null),
            ], static fn (string $part): bool => $part !== ''));

            if ($parts !== []) {
                $labels[] = implode(' · ', $parts);
            }
        }

        return $labels;
    }

    /**
     * @param  list<array<mixed>>  $items
     * @return list<string>
     */
    private function formatInstitutions(array $items): array
    {
        $labels = [];
        $unnamed = 0;

        foreach ($items as $item) {
            $position = $this->scalarText($item['position'] ?? null);
            $isPrimary = in_array($item['is_primary'] ?? null, [true, 1, '1'], true);

            if ($position === '' && ! $isPrimary) {
                $unnamed++;

                continue;
            }

            $label = $position === '' ? __('Affiliated Institutions') : $position;
            $labels[] = $isPrimary ? $label.' ('.__('Primary').')' : $label;
        }

        if ($unnamed > 0) {
            $labels[] = (string) trans_choice(':count institution|:count institutions', $unnamed, ['count' => $unnamed]);
        }

        return $labels;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isUuidList(string $key, array $value): bool
    {
        if ($value === []) {
            return false;
        }

        if (! str_ends_with($key, '_ids')) {
            return $this->allItemsAreUuids($value);
        }

        return true;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function allItemsAreUuids(array $value): bool
    {
        foreach ($value as $item) {
            if (! is_string($item) || ! Str::isUuid($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isListOfArrays(array $value): bool
    {
        foreach ($value as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function flattenScalars(array $value): array
    {
        $parts = [];

        array_walk_recursive($value, function (mixed $item) use (&$parts): void {
            if (! is_scalar($item) || is_bool($item)) {
                return;
            }

            $text = trim((string) $item);

            if ($text === '' || Str::isUuid($text)) {
                return;
            }

            $parts[] = $text;
        });

        return array_slice($parts, 0, 12);
    }

    private function mediaSummaryLine(ContributionRequest $request): ?string
    {
        $items = $this->stagedMediaItems($request);

        if ($items === []) {
            return null;
        }

        $labels = array_values(array_unique(array_map(
            static fn (array $item): string => $item['field_label'],
            $items,
        )));
        $shown = array_slice($labels, 0, 2);
        $line = implode(', ', $shown);
        $remaining = count($items) - count($shown);

        return $remaining > 0 ? $line.' +'.$remaining : $line;
    }

    private function notePreview(ContributionRequest $request): ?string
    {
        $note = trim((string) $request->proposer_note);

        return $note === '' ? null : Str::limit($note, 110);
    }

    private function scalarText(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function statusValue(mixed $entity): ?string
    {
        $status = $entity instanceof Model ? $entity->getAttribute('status') : null;

        return $status instanceof BackedEnum ? (string) $status->value : (is_string($status) ? $status : null);
    }

    private function toCarbon(CarbonInterface|string|null $date): ?CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date;
        }

        if (is_string($date) && trim($date) !== '') {
            return CarbonImmutable::parse($date);
        }

        return null;
    }
}
