<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

/**
 * Review snapshot for one membership application.
 */
final readonly class MembershipApplicationDetail
{
    /**
     * @param  list<MembershipApplicationEvidence>  $evidence
     */
    public function __construct(
        public MembershipApplicationSummary $application,
        public string $subjectLabel,
        public string $subjectTitle,
        public ?string $subjectPublicUrl,
        public string $statusLabel,
        public string $roleLabel,
        public string $justification,
        public ?string $notes,
        public ?MembershipApplicationReviewer $reviewer = null,
        public ?string $reviewerNote = null,
        public ?string $grantedRole = null,
        public ?string $reviewedAt = null,
        public ?string $cancelledAt = null,
        public array $evidence = [],
    ) {}

    /**
     * @return array{
     *   id: string,
     *   subject_type: string,
     *   subject_label: string,
     *   subject_title: string,
     *   subject_public_url: string|null,
     *   status: string,
     *   status_label: string,
     *   role_label: string,
     *   justification: string,
     *   granted_role: string|null,
     *   reviewer_note: string|null,
     *   created_at: string|null,
     *   reviewed_at: string|null,
     *   cancelled_at: string|null,
     *   can_cancel: bool,
     *   reviewer: array{id: string, name: string|null, email: string|null}|null,
     *   evidence: list<array{id: string, name: string, url: string}>
     * }
     */
    public function applicantPayload(string $accountId): array
    {
        return [
            'id' => $this->application->id,
            'subject_type' => $this->application->subjectType->value,
            'subject_label' => $this->subjectLabel,
            'subject_title' => $this->subjectTitle,
            'subject_public_url' => $this->subjectPublicUrl,
            'status' => $this->application->status,
            'status_label' => $this->statusLabel,
            'role_label' => $this->roleLabel,
            'justification' => $this->justification,
            'granted_role' => $this->grantedRole,
            'reviewer_note' => $this->reviewerNote,
            'created_at' => $this->application->submittedAt,
            'reviewed_at' => $this->reviewedAt,
            'cancelled_at' => $this->cancelledAt,
            'can_cancel' => $this->application->status === 'pending' && $this->application->applicantId === $accountId,
            'reviewer' => $this->reviewer?->toArray(),
            'evidence' => array_map(
                static fn (MembershipApplicationEvidence $evidence): array => $evidence->toArray(),
                $this->evidence,
            ),
        ];
    }
}
