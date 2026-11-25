<?php

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support;

use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Platform\Admin\AdminResourceLink;
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
            $entity instanceof Reference => $entity->displayTitle(),
            is_string(data_get($request->proposed_data, 'name')) && filled(data_get($request->proposed_data, 'name')) => data_get($request->proposed_data, 'name'),
            is_string(data_get($request->proposed_data, 'title')) && filled(data_get($request->proposed_data, 'title')) => data_get($request->proposed_data, 'title'),
            default => self::labelForSubject($request->subject_type).' Request',
        };
    }

    public static function entityAdminUrl(ContributionRequest $request): ?string
    {
        $entity = $request->entity;

        return match (true) {
            $entity instanceof Institution => AdminResourceLink::to('institution', 'view', (string) $entity->getKey()),
            $entity instanceof Person => AdminResourceLink::to('person', 'view', (string) $entity->getKey()),
            $entity instanceof Event => AdminResourceLink::to('event', 'view', (string) $entity->getKey()),
            $entity instanceof Reference => AdminResourceLink::to('reference', 'edit', (string) $entity->getKey()),
            default => null,
        };
    }

    public static function breadcrumbTitle(ContributionRequest $request): string
    {
        $subject = self::labelForSubject($request->subject_type);

        return sprintf('%s: %s', $subject, self::entityTitle($request));
    }

    /**
     * Readable change set for the admin view.
     *
     * Update requests show only fields that actually changed, formatted with
     * the hub presenter so moderators see labels and clean values instead of
     * raw HTML, UUIDs or JSON. Create requests list the submitted payload.
     *
     * @return list<array{field: string, original: string, proposed: string}>
     */
    public static function payloadChanges(ContributionRequest $request): array
    {
        $hub = app(ContributionsHubPresenter::class);

        if ($request->type === ContributionRequestType::Create) {
            return array_map(
                static fn (array $item): array => [
                    'field' => $item['field'],
                    'original' => '-',
                    'proposed' => $item['value'],
                ],
                $hub->submittedFieldSummaries($request, 2000),
            );
        }

        return array_map(
            static fn (array $item): array => [
                'field' => $item['field'],
                'original' => $item['original'],
                'proposed' => $item['proposed'],
            ],
            $hub->changedFieldSummaries($request, 2000),
        );
    }

    public static function pendingMediaHtml(ContributionRequest $record): HtmlString
    {
        $items = app(ContributionsHubPresenter::class)->stagedMediaItems($record);

        if ($items === []) {
            return new HtmlString('<span class="text-gray-400">-</span>');
        }

        $html = '';

        foreach (collect($items)->groupBy('field') as $medias) {
            $label = (string) ($medias->first()['field_label'] ?? '');

            $html .= sprintf('<div class="mb-4"><div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">%s</div>', e($label));

            foreach ($medias as $item) {
                $url = (string) $item['url'];
                $name = (string) $item['name'];

                if ($item['is_image']) {
                    $html .= sprintf(
                        '<a href="%s" target="_blank" rel="noopener noreferrer" style="margin:0 8px 8px 0;display:inline-block;vertical-align:top;text-decoration:none;">'
                        .'<img src="%s" alt="%s" style="height:96px;width:auto;border-radius:8px;display:block;object-fit:cover;margin-bottom:4px;" />'
                        .'<span style="display:block;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#6b7280;">%s</span>'
                        .'</a>',
                        e($url),
                        e($url),
                        e($name),
                        e($name),
                    );

                    continue;
                }

                $html .= sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:8px;margin:0 8px 8px 0;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none;font-size:13px;color:#374151;">'
                    .'<span>%s</span><span style="font-size:11px;color:#6b7280;">%s</span>'
                    .'</a>',
                    e($url),
                    e($name),
                    e(__('Download')),
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
