<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards\Host\Support;

use App\Features\Rewards\Api\MemberEnrollment;
use App\Features\Rewards\Api\MemberEnrollmentSnapshot;

final class Rw2bRecordingMemberEnrollment implements MemberEnrollment
{
    /** @var list<array{programId: string, memberId: string, recipientType: string, versionId: ?string, surface: string, recordSignal: bool, acceptedVia: ?string}> */
    public array $enrollments = [];

    /** @var list<array{programId: string, memberId: string, recipientType: string, reason: ?string}> */
    public array $leaves = [];

    /** @var list<array{programId: string, memberId: string, recipientType: string, versionId: ?string, acceptedVia: string}> */
    public array $reaccepts = [];

    public function __construct(private readonly MemberEnrollment $inner) {}

    public function enroll(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $surface = 'member-portal',
        bool $recordSignal = true,
        ?string $acceptedVia = null,
    ): ?MemberEnrollmentSnapshot {
        $this->enrollments[] = ['programId' => $programId, 'memberId' => $memberId, 'recipientType' => $recipientType, 'versionId' => $versionId, 'surface' => $surface, 'recordSignal' => $recordSignal, 'acceptedVia' => $acceptedVia];

        return $this->inner->enroll($programId, $memberId, $recipientType, $versionId, $surface, $recordSignal, $acceptedVia);
    }

    public function leave(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $reason = null,
    ): void {
        $this->leaves[] = ['programId' => $programId, 'memberId' => $memberId, 'recipientType' => $recipientType, 'reason' => $reason];

        $this->inner->leave($programId, $memberId, $recipientType, $reason);
    }

    public function reaccept(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $acceptedVia = 'member-portal',
    ): ?MemberEnrollmentSnapshot {
        $this->reaccepts[] = ['programId' => $programId, 'memberId' => $memberId, 'recipientType' => $recipientType, 'versionId' => $versionId, 'acceptedVia' => $acceptedVia];

        return $this->inner->reaccept($programId, $memberId, $recipientType, $versionId, $acceptedVia);
    }

    public function preclaim(
        string $programId,
        string $memberId,
        string $recipientType,
        string $versionId,
    ): void {
        $this->inner->preclaim($programId, $memberId, $recipientType, $versionId);
    }
}
