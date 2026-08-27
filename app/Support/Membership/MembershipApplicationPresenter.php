<?php

namespace App\Support\Membership;

use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MembershipApplicationPresenter
{
    public static function labelForSubject(MemberSubjectType|string|null $subjectType): string
    {
        if ($subjectType instanceof MemberSubjectType) {
            return $subjectType->label();
        }

        return MemberSubjectType::tryFrom((string) $subjectType)?->label() ?? Str::headline((string) $subjectType);
    }

    public static function labelForStatus(ApplicationStatus|string|null $status): string
    {
        if ($status instanceof ApplicationStatus) {
            return $status->label();
        }

        return ApplicationStatus::tryFrom((string) $status)?->label() ?? Str::headline((string) $status);
    }

    public static function statusColor(ApplicationStatus|string|null $status): string
    {
        $value = $status instanceof ApplicationStatus ? $status->value : (string) $status;

        return match ($value) {
            ApplicationStatus::Pending->value => 'warning',
            ApplicationStatus::Approved->value => 'success',
            ApplicationStatus::Rejected->value => 'danger',
            ApplicationStatus::Cancelled->value => 'gray',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function approvalRoleOptions(MembershipApplication $claim): array
    {
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $r): array => [$r->value => $r->label()])
            ->all();
    }

    public static function roleLabel(MembershipApplication $claim): string
    {
        if (! is_string($claim->granted_role) || $claim->granted_role === '') {
            return '-';
        }

        return MemberRole::tryFrom($claim->granted_role)?->label() ?? $claim->granted_role;
    }

    public static function appliedRoleLabel(MembershipApplication $claim): string
    {
        $role = $claim->meta['applied_role'] ?? null;

        if (! is_string($role) || $role === '') {
            return '-';
        }

        return MemberRole::tryFrom($role)?->label() ?? $role;
    }

    public static function relationshipLabel(MembershipApplication $claim): string
    {
        $relationship = $claim->meta['relationship'] ?? null;

        if (! is_string($relationship) || $relationship === '') {
            return '-';
        }

        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::tryFrom((string) $claim->subject_type);

        return self::relationshipOptions($subjectType)[$relationship]
            ?? self::relationshipOptions()[$relationship]
            ?? $relationship;
    }

    /**
     * @return array<string, string>
     */
    public static function relationshipOptions(?MemberSubjectType $subjectType = null): array
    {
        if ($subjectType === MemberSubjectType::Institution) {
            return [
                'imam' => 'Imam',
                'bilal' => 'Bilal',
                'committee_member' => 'Ahli Jawatan Kuasa',
                'employee' => 'Pekerja',
            ];
        }

        return [
            'self' => 'Diri Sendiri',
            'personal_assistant' => 'Pembantu Peribadi',
            'representative' => 'Wakil',
            'team_member' => 'Ahli Pasukan',
        ];
    }

    public static function subjectTitle(MembershipApplication $claim): string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['subject_title'] ?? (string) $claim->subject_id;
    }

    public static function subjectPublicUrl(MembershipApplication $claim): ?string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['redirect_url'] ?? null;
    }

    public static function subjectAdminUrl(MembershipApplication $claim): ?string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['admin_url'] ?? null;
    }

    public static function evidenceLinks(MembershipApplication $claim): HtmlString
    {
        $links = $claim->getMedia('evidence')
            ->map(function (Media $media): string {
                $url = e($media->getAvailableUrl(['thumb']) ?: $media->getUrl());
                $name = e($media->name !== '' ? $media->name : $media->file_name);

                return sprintf(
                    '<a href="%s" target="_blank" rel="noreferrer" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">%s</a>',
                    $url,
                    $name,
                );
            })
            ->implode(' ');

        if ($links === '') {
            $links = '<span class="text-sm text-slate-500">-</span>';
        }

        return new HtmlString($links);
    }

    public static function evidencePreviewHtml(MembershipApplication $claim, int $size = 14): HtmlString
    {
        $media = $claim->getMedia('evidence');

        if ($media->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-400">-</span>');
        }

        $items = $media->map(function (Media $media) use ($size): string {
            $url = $media->getUrl();
            $name = e($media->name !== '' ? $media->name : $media->file_name);
            $px = $size * 4;
            $style = "height:{$px}px;width:{$px}px";

            if (str_starts_with((string) $media->mime_type, 'image/')) {
                $thumb = $media->getAvailableUrl(['thumb']) ?: $url;

                return sprintf(
                    '<a href="%s" target="_blank" rel="noreferrer"><img src="%s" alt="%s" style="%s" class="rounded-lg object-cover"></a>',
                    e($url),
                    e($thumb),
                    $name,
                    $style,
                );
            }

            return sprintf(
                '<a href="%s" target="_blank" rel="noreferrer" title="%s" style="%s" class="flex items-center justify-center rounded-lg border border-gray-200 bg-gray-50 text-gray-500">%s</a>',
                e($url),
                $name,
                $style,
                self::fileIconSvg(),
            );
        })->implode('');

        return new HtmlString('<div class="flex flex-wrap gap-2">'.$items.'</div>');
    }

    protected static function fileIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6"><path d="M19.5 21a3 3 0 0 0 3-3V9a3 3 0 0 0-3-3h-4.5a3 3 0 0 1-3-3V3.4a1.5 1.5 0 0 0-1.5-1.5H5.6A1.5 1.5 0 0 0 4 3.4v15.1a3 3 0 0 0 3 3h12.5Z" opacity=".3"/><path d="M19.5 21a3 3 0 0 0 3-3V9a3 3 0 0 0-3-3h-4.5a3 3 0 0 1-3-3V3.4a1.5 1.5 0 0 0-1.5-1.5H5.6A1.5 1.5 0 0 0 4 3.4v15.1a3 3 0 0 0 3 3h12.5Z"/></svg>';
    }

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string}|null
     */
    public static function subjectPresentation(MembershipApplication $claim): ?array
    {
        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);

        try {
            $subject = $subjectType->resolveSubject($claim->subject_id);
        } catch (ModelNotFoundException) {
            return null;
        }

        if (! $subject instanceof Institution && ! $subject instanceof Person) {
            return null;
        }

        $label = $subject instanceof Institution
            ? MemberSubjectType::Institution->label()
            : MemberSubjectType::Person->label();

        $title = $subject instanceof Institution ? $subject->name : $subject->formatted_name;
        $redirectUrl = $subject instanceof Institution
            ? route('institutions.show', $subject)
            : route('persons.show', $subject);

        return [
            'subject_label' => $label,
            'subject_title' => $title,
            'redirect_url' => $redirectUrl,
            'admin_url' => $subject instanceof Institution
                ? InstitutionResource::getUrl('view', ['record' => $subject])
                : PersonResource::getUrl('view', ['record' => $subject]),
        ];
    }
}
