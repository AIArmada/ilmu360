<?php

namespace App\Support\Membership;

use App\Actions\Membership\ResolveMembershipClaimSubjectPresentationAction;
use App\Enums\MembershipClaimStatus;
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

    public static function labelForStatus(MembershipClaimStatus|string|null $status): string
    {
        if ($status instanceof MembershipClaimStatus) {
            return $status->label();
        }

        return MembershipClaimStatus::tryFrom((string) $status)?->label() ?? Str::headline((string) $status);
    }

    public static function statusColor(MembershipClaimStatus|string|null $status): string
    {
        $value = $status instanceof MembershipClaimStatus ? $status->value : (string) $status;

        return match ($value) {
            MembershipClaimStatus::Pending->value => 'warning',
            MembershipClaimStatus::Approved->value => 'success',
            MembershipClaimStatus::Rejected->value => 'danger',
            MembershipClaimStatus::Cancelled->value => 'gray',
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
        if (! is_string($claim->granted_role_slug) || $claim->granted_role_slug === '') {
            return '-';
        }

        $subjectType = $claim->subject_type instanceof MemberSubjectType
            ? $claim->subject_type
            : MemberSubjectType::from((string) $claim->subject_type);

        return app(MemberRoleCatalog::class)->roleLabel($subjectType, $claim->granted_role_slug);
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
