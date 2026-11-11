<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Shared\Membership\MembershipSubjectType;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps membership models to scalar Api values without leaking Eloquent types.
 */
final readonly class MembershipValueMapper
{
    public static function invitation(Model $invitation): MembershipInvitationDetail
    {
        return new MembershipInvitationDetail(
            id: (string) $invitation->getKey(),
            subjectType: MembershipSubjectType::from(self::stringValue($invitation->getAttribute('subject_type')) ?? ''),
            subjectId: (string) $invitation->getAttribute('subject_id'),
            email: (string) $invitation->getAttribute('email'),
            role: self::stringValue($invitation->getAttribute('role')) ?? '',
            status: self::stringValue($invitation->getAttribute('status')) ?? '',
            invitedBy: self::stringValue($invitation->getAttribute('invited_by')),
            expiresAt: self::dateTime($invitation->getAttribute('expires_at')),
            acceptedAt: self::dateTime($invitation->getAttribute('accepted_at')),
            createdAt: self::dateTime($invitation->getAttribute('created_at')),
        );
    }

    public static function applicationSummary(Model $application): MembershipApplicationSummary
    {
        return new MembershipApplicationSummary(
            id: (string) $application->getKey(),
            subjectType: MembershipSubjectType::from(self::stringValue($application->getAttribute('subject_type')) ?? ''),
            subjectId: (string) $application->getAttribute('subject_id'),
            applicantId: (string) $application->getAttribute('applicant_id'),
            status: self::stringValue($application->getAttribute('status')) ?? '',
            submittedAt: self::dateTime($application->getAttribute('created_at')),
        );
    }

    public static function applicationDetail(Model $application): MembershipApplicationDetail
    {
        return new MembershipApplicationDetail(
            application: self::applicationSummary($application),
            reviewerId: self::stringValue($application->getAttribute('reviewer_id')),
            reviewerNote: self::stringValue($application->getAttribute('reviewer_note')),
            grantedRole: self::stringValue($application->getAttribute('granted_role')),
            reviewedAt: self::dateTime($application->getAttribute('reviewed_at')),
            cancelledAt: self::dateTime($application->getAttribute('cancelled_at')),
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
