<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Features\Engagement\Api\EngagementShareOutcomes;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Features\ShareTracking\Api\ShareTrackingContract;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Root recorder for the bookmark, response and follow share outcomes.
 *
 * Only explicit user transitions call this recorder. Account restore and
 * event-delete cleanup continue to delete the package engagement rows without
 * reversing the original outcome fact.
 */
final readonly class EngagementOutcomeRecorder implements EngagementShareOutcomes
{
    /**
     * @var list<ShareOutcomeType>
     */
    private const array FOLLOW_OUTCOMES = [
        ShareOutcomeType::InstitutionFollow,
        ShareOutcomeType::PersonFollow,
        ShareOutcomeType::SeriesFollow,
        ShareOutcomeType::ReferenceFollow,
    ];

    public function __construct(
        private ShareTrackingContract $shareTracking,
        private ShareOutcomeReversals $reverse,
    ) {}

    public function recordBookmark(
        string $bookmarkId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $this->record(
            ShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave($bookmarkId),
            $subject,
            $actor,
            $request,
            $metadata,
            $occurredAt,
        );
    }

    public function reverseBookmark(
        string $bookmarkId,
        string $reason,
        ?AccountActor $actor = null,
    ): void {
        $this->reverseOutcome(
            ShareOutcomeBusinessKey::eventSave($bookmarkId),
            $reason,
            $actor,
        );
    }

    public function recordResponse(
        string $responseId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $this->record(
            ShareOutcomeType::EventGoing,
            ShareOutcomeBusinessKey::eventGoing($responseId),
            $subject,
            $actor,
            $request,
            $metadata,
            $occurredAt,
        );
    }

    public function reverseResponse(
        string $responseId,
        string $reason,
        ?AccountActor $actor = null,
    ): void {
        $this->reverseOutcome(
            ShareOutcomeBusinessKey::eventGoing($responseId),
            $reason,
            $actor,
        );
    }

    public function recordFollow(
        ShareOutcomeType $type,
        string $followId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $this->assertFollowOutcome($type);

        $this->record(
            $type,
            ShareOutcomeBusinessKey::follow($type, $followId),
            $subject,
            $actor,
            $request,
            $metadata,
            $occurredAt,
        );
    }

    public function reverseFollow(
        ShareOutcomeType $type,
        string $followId,
        string $reason,
        ?AccountActor $actor = null,
    ): void {
        $this->assertFollowOutcome($type);

        $this->reverseOutcome(
            ShareOutcomeBusinessKey::follow($type, $followId),
            $reason,
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        ShareOutcomeType $type,
        string $businessKey,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt,
    ): void {
        $this->shareTracking->recordOutcome(
            type: $type,
            businessKey: $businessKey,
            subject: $subject,
            actor: $actor instanceof User ? $actor : null,
            request: $request,
            metadata: $metadata,
            occurredAt: $occurredAt,
        );
    }

    private function reverseOutcome(
        string $businessKey,
        string $reason,
        ?AccountActor $actor,
    ): void {
        $this->reverse->reverse(
            $businessKey,
            $reason,
            $actor instanceof User ? (string) $actor->getKey() : null,
        );
    }

    private function assertFollowOutcome(ShareOutcomeType $type): void
    {
        if (! in_array($type, self::FOLLOW_OUTCOMES, true)) {
            throw new InvalidArgumentException('Engagement follow outcomes must use a follow share-outcome type.');
        }
    }
}
