<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Membership\Enums\ApplicationStatus;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplicationEvidence;
use App\Features\Membership\Api\MembershipApplicationReviewer;
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Features\Membership\Api\MembershipSubjectCatalog;
use App\Features\Membership\Api\MemberSubjectType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Maps membership models to scalar Api values without leaking Eloquent types.
 */
final readonly class MembershipValueMapper
{
    public static function invitation(Model $invitation): MembershipInvitationDetail
    {
        return new MembershipInvitationDetail(
            id: (string) $invitation->getKey(),
            subjectType: MemberSubjectType::from(self::stringValue($invitation->getAttribute('subject_type')) ?? ''),
            subjectId: (string) $invitation->getAttribute('subject_id'),
            email: (string) $invitation->getAttribute('email'),
            role: self::stringValue($invitation->getAttribute('role')) ?? '',
            status: self::stringValue($invitation->getAttribute('status')) ?? '',
            invitedBy: self::stringValue($invitation->getAttribute('invited_by')),
            expiresAt: self::dateTime($invitation->getAttribute('expires_at')),
            acceptedAt: self::dateTime($invitation->getAttribute('accepted_at')),
            createdAt: self::dateTime($invitation->getAttribute('created_at')),
            isValid: method_exists($invitation, 'isValid') && (bool) $invitation->isValid(),
        );
    }

    public static function applicationSummary(Model $application): MembershipApplicationSummary
    {
        return new MembershipApplicationSummary(
            id: (string) $application->getKey(),
            subjectType: MemberSubjectType::from(self::stringValue($application->getAttribute('subject_type')) ?? ''),
            subjectId: (string) $application->getAttribute('subject_id'),
            applicantId: (string) $application->getAttribute('applicant_id'),
            status: self::stringValue($application->getAttribute('status')) ?? '',
            submittedAt: self::dateTime($application->getAttribute('created_at')),
        );
    }

    public static function applicationDetail(
        Model $application,
        MembershipSubjectCatalog $subjectCatalog,
    ): MembershipApplicationDetail {
        $summary = self::applicationSummary($application);
        $subject = $subjectCatalog->find($summary->subjectType, $summary->subjectId);
        $status = self::stringValue($application->getAttribute('status')) ?? '';
        $grantedRole = self::stringValue($application->getAttribute('granted_role'));
        $reviewerModel = $application->getRelationValue('reviewer');

        $reviewer = $reviewerModel instanceof Model
            ? new MembershipApplicationReviewer(
                id: (string) $reviewerModel->getKey(),
                name: self::stringValue($reviewerModel->getAttribute('name')),
                email: self::stringValue($reviewerModel->getAttribute('email')),
            )
            : null;

        $evidence = $application instanceof HasMedia
            ? $application->getMedia('evidence')
                ->map(static fn (Media $media): MembershipApplicationEvidence => new MembershipApplicationEvidence(
                    id: (string) $media->getKey(),
                    name: $media->name !== '' ? $media->name : $media->file_name,
                    url: $media->getAvailableUrl(['thumb']) ?: $media->getUrl(),
                ))
                ->all()
            : [];
        $meta = $application->getAttribute('meta');
        $notes = is_array($meta) && is_string($meta['notes'] ?? null) && $meta['notes'] !== ''
            ? $meta['notes']
            : null;

        return new MembershipApplicationDetail(
            application: $summary,
            subjectLabel: $summary->subjectType->label(),
            subjectTitle: $subject->label ?? $summary->subjectId,
            subjectPublicUrl: $subject?->publicUrl,
            statusLabel: ApplicationStatus::tryFrom($status)?->label() ?? Str::headline($status),
            roleLabel: $grantedRole === null
                ? '-'
                : (MemberRole::tryFrom($grantedRole)?->label() ?? $grantedRole),
            justification: (string) $application->getAttribute('justification'),
            notes: $notes,
            reviewer: $reviewer,
            reviewerNote: self::stringValue($application->getAttribute('reviewer_note')),
            grantedRole: $grantedRole,
            reviewedAt: self::dateTime($application->getAttribute('reviewed_at')),
            cancelledAt: self::dateTime($application->getAttribute('cancelled_at')),
            evidence: $evidence,
        );
    }

    public static function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
