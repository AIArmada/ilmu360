<?php

namespace App\Support\Membership;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Actions\Membership\ResolveMembershipClaimSubjectPresentationAction;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\Speaker;
use App\Support\Authz\MemberRoleCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MembershipClaimPresenter
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
    public static function approvalRoleOptions(MembershipClaim $claim): array
    {
        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);

        return app(MemberRoleCatalog::class)->membershipClaimRoleSlugOptionsFor($subjectType);
    }

    public static function roleLabel(MembershipClaim $claim): string
    {
        if (! is_string($claim->granted_role) || $claim->granted_role === '') {
            return '-';
        }

        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);

        return app(MemberRoleCatalog::class)->roleLabel($subjectType, $claim->granted_role);
    }

    public static function subjectTitle(MembershipClaim $claim): string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['subject_title'] ?? (string) $claim->subject_id;
    }

    public static function subjectPublicUrl(MembershipClaim $claim): ?string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['redirect_url'] ?? null;
    }

    public static function subjectAdminUrl(MembershipClaim $claim): ?string
    {
        $presentation = self::subjectPresentation($claim);

        return $presentation['admin_url'] ?? null;
    }

    public static function evidenceLinks(MembershipClaim $claim): HtmlString
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

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string}|null
     */
    public static function subjectPresentation(MembershipClaim $claim): ?array
    {
        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);

        try {
            $subject = $subjectType->resolveSubject($claim->subject_id);
        } catch (ModelNotFoundException) {
            return null;
        }

        if (! $subject instanceof Institution && ! $subject instanceof Speaker) {
            return null;
        }

        return app(ResolveMembershipClaimSubjectPresentationAction::class)->handle($subject);
    }
}
