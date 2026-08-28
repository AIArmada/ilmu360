<?php

namespace App\Filament\Resources\ContributionRequests\Support;

use AIArmada\FilamentEvents\Resources\EventResource;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Persons\PersonResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ContributionRequestPresenter
{
    public static function labelForType(ContributionRequestType|string|null $type): string
    {
        $value = $type instanceof ContributionRequestType ? $type->value : $type;

        return filled($value) ? Str::headline((string) $value) : '-';
    }

    public static function labelForSubject(ContributionSubjectType|string|null $subjectType): string
    {
        $value = $subjectType instanceof ContributionSubjectType ? $subjectType->value : $subjectType;

        return filled($value) ? Str::headline((string) $value) : '-';
    }

    public static function labelForStatus(ContributionRequestStatus|string|null $status): string
    {
        $value = $status instanceof ContributionRequestStatus ? $status->value : $status;

        return filled($value) ? Str::headline((string) $value) : '-';
    }

    public static function statusColor(ContributionRequestStatus|string|null $status): string
    {
        $value = $status instanceof ContributionRequestStatus ? $status->value : $status;

        return match ((string) $value) {
            'pending' => 'warning',
            'approved' => 'success',
            'rejected' => 'danger',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    public static function entityTitle(ContributionRequest $request): string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => $entity->name,
            $entity instanceof Person => $entity->formatted_name,
            $entity instanceof Event => $entity->title,
            $entity instanceof Reference => $entity->title,
            is_string(data_get($request->proposed_data, 'name')) && filled(data_get($request->proposed_data, 'name')) => data_get($request->proposed_data, 'name'),
            is_string(data_get($request->proposed_data, 'title')) && filled(data_get($request->proposed_data, 'title')) => data_get($request->proposed_data, 'title'),
            default => self::labelForSubject($request->subject_type).' Request',
        };
    }

    public static function entityAdminUrl(ContributionRequest $request): ?string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => InstitutionResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Person => PersonResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Event => EventResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Reference => ReferenceResource::getUrl('edit', ['record' => $entity]),
            default => null,
        };
    }

    public static function breadcrumbTitle(ContributionRequest $request): string
    {
        $subject = self::labelForSubject($request->subject_type);

        return sprintf('%s: %s', $subject, self::entityTitle($request));
    }

    /**
     * @return list<array{field: string, original: string, proposed: string}>
     */
    public static function payloadChanges(ContributionRequest $request): array
    {
        /** @var array<int|string, mixed> $proposed */
        $proposed = $request->proposed_data ?? [];
        /** @var array<int|string, mixed> $original */
        $original = $request->original_data ?? [];

        return collect($proposed)
            ->map(fn (mixed $proposedValue, int|string $key): array => [
                'field' => Str::headline((string) $key),
                'original' => self::formatValue($original[$key] ?? null),
                'proposed' => self::formatValue($proposedValue),
            ])
            ->values()
            ->all();
    }

    public static function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $flat = array_filter($value, fn (mixed $item): bool => is_scalar($item) || $item === null);

            if (count($flat) === count($value)) {
                return implode(', ', array_map(fn (mixed $item): string => (string) $item, $flat));
            }

            return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    public static function changedFields(ContributionRequest $request): string
    {
        $keys = array_keys($request->proposed_data ?? []);

        return $keys === [] ? '-' : implode(', ', $keys);
    }

    public static function pendingMediaHtml(ContributionRequest $record): HtmlString
    {
        $staged = $record->getMedia('pending_media');

        if ($staged->isEmpty()) {
            return new HtmlString('<span class="text-gray-400">-</span>');
        }

        $grouped = $staged->groupBy(fn ($media): string => (string) $media->getCustomProperty('contribution_field', 'media'));

        $html = '';

        foreach ($grouped as $field => $medias) {
            $label = Str::headline($field);

            $html .= sprintf('<div class="mb-4"><div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">%s</div>', e($label));

            foreach ($medias as $media) {
                $url = $media->getUrl();
                $html .= sprintf(
                    '<img src="%s" alt="%s" style="height:96px;width:auto;border-radius:8px;margin:0 8px 8px 0;display:inline-block;object-fit:cover;" />',
                    e($url),
                    e($label),
                );
            }

            $html .= '</div>';
        }

        return new HtmlString($html);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return collect(ContributionRequestStatus::cases())
            ->mapWithKeys(fn (ContributionRequestStatus $status): array => [$status->value => self::labelForStatus($status)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return collect(ContributionRequestType::cases())
            ->mapWithKeys(fn (ContributionRequestType $type): array => [$type->value => self::labelForType($type)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function subjectOptions(): array
    {
        return collect(ContributionSubjectType::cases())
            ->mapWithKeys(fn (ContributionSubjectType $subjectType): array => [$subjectType->value => self::labelForSubject($subjectType)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function rejectionReasonOptions(): array
    {
        return [
            'needs_more_evidence' => 'Needs More Evidence',
            'incorrect_information' => 'Incorrect Information',
            'duplicate_request' => 'Duplicate Request',
            'out_of_scope' => 'Out of Scope',
            'other' => 'Other',
            'rejected_by_reviewer' => 'Rejected by Reviewer',
        ];
    }
}
