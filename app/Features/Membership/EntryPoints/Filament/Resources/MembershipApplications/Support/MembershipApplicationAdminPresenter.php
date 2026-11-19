<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Support;

use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Api\MembershipApplicationFormRules;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipSubjectCatalog;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Models\MembershipApplication;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MembershipApplicationAdminPresenter
{
    /**
     * @return array<string, string>
     */
    public static function approvalRoleOptions(): array
    {
        return app(MembershipApplications::class)->reviewRoleOptions();
    }

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

    public static function roleLabel(MembershipApplication $application): string
    {
        $role = $application->granted_role;

        if (! is_string($role) || $role === '') {
            return '-';
        }

        return MemberRole::tryFrom($role)?->label() ?? $role;
    }

    public static function appliedRoleLabel(MembershipApplication $application): string
    {
        $role = $application->meta['applied_role'] ?? null;

        if (! is_string($role) || $role === '') {
            return '-';
        }

        return MemberRole::tryFrom($role)?->label() ?? $role;
    }

    public static function relationshipLabel(MembershipApplication $application): string
    {
        $relationship = $application->meta['relationship'] ?? null;

        if (! is_string($relationship) || $relationship === '') {
            return '-';
        }

        $detail = $application->meta['relationship_detail'] ?? null;

        return MembershipApplicationFormRules::relationshipLabel(
            $relationship,
            is_string($detail) ? $detail : null,
        );
    }

    public static function subjectTitle(MembershipApplication $application): string
    {
        $presentation = self::subjectPresentation($application);

        return $presentation['subject_title'] ?? (string) $application->subject_id;
    }

    public static function subjectPublicUrl(MembershipApplication $application): ?string
    {
        return self::subjectPresentation($application)['redirect_url'] ?? null;
    }

    public static function subjectAdminUrl(MembershipApplication $application): ?string
    {
        return self::subjectPresentation($application)['admin_url'] ?? null;
    }

    public static function evidenceLinks(MembershipApplication $application): HtmlString
    {
        $links = $application->getMedia('evidence')
            ->map(static function (Media $media): string {
                $url = e($media->getAvailableUrl(['thumb']) ?: $media->getUrl());
                $name = e($media->name !== '' ? $media->name : $media->file_name);

                return sprintf(
                    '<a href="%s" target="_blank" rel="noreferrer" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-emerald-300 hover:text-emerald-700">%s</a>',
                    $url,
                    $name,
                );
            })
            ->implode(' ');

        return new HtmlString($links !== '' ? $links : '<span class="text-sm text-slate-500">-</span>');
    }

    public static function evidencePreviewHtml(MembershipApplication $application, int $size = 14): HtmlString
    {
        $media = $application->getMedia('evidence');

        if ($media->isEmpty()) {
            return new HtmlString('<span class="text-sm text-gray-400">-</span>');
        }

        $items = $media->map(static function (Media $media) use ($size): string {
            $url = $media->getUrl();
            $name = e($media->name !== '' ? $media->name : $media->file_name);
            $style = sprintf('height:%dpx;width:%dpx', $size * 4, $size * 4);

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
     * @return array{subject_label: string, subject_title: string, redirect_url: string|null, admin_url: string|null}|null
     */
    private static function subjectPresentation(MembershipApplication $application): ?array
    {
        $subjectTypeValue = $application->subject_type instanceof \BackedEnum
            ? $application->subject_type->value
            : (string) $application->subject_type;
        $subjectType = MemberSubjectType::tryFrom((string) $subjectTypeValue);

        if (! $subjectType instanceof MemberSubjectType) {
            return null;
        }

        $subject = app(MembershipSubjectCatalog::class)->find($subjectType, (string) $application->subject_id);

        if ($subject === null || ! in_array($subjectType, [MemberSubjectType::Institution, MemberSubjectType::Person], true)) {
            return null;
        }

        return [
            'subject_label' => $subjectType->label(),
            'subject_title' => $subject->label,
            'redirect_url' => $subject->publicUrl,
            'admin_url' => $subject->adminUrl,
        ];
    }
}
