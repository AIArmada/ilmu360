<?php

namespace App\Filament\Resources\ContributionRequests\Support;

use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
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
            $entity instanceof Speaker => $entity->formatted_name,
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
            $entity instanceof Speaker => SpeakerResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Event => EventResource::getUrl('view', ['record' => $entity]),
            $entity instanceof Reference => ReferenceResource::getUrl('edit', ['record' => $entity]),
            default => null,
        };
    }

    public static function changedFields(ContributionRequest $request): string
    {
        $keys = array_keys($request->proposed_data ?? []);

        return $keys === [] ? '-' : implode(', ', $keys);
    }

    public static function prettyJson(mixed $payload): HtmlString
    {
        if (is_string($payload) && $payload !== '') {
            $decodedPayload = json_decode($payload, true);
            $payload = is_array($decodedPayload) ? $decodedPayload : $payload;
        }

        $json = is_string($payload)
            ? $payload
            : json_encode($payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{}';
        }

        return new HtmlString(sprintf(
            '<pre class="whitespace-pre-wrap rounded-xl bg-slate-950 p-4 text-xs text-white">%s</pre>',
            e($json)
        ));
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
