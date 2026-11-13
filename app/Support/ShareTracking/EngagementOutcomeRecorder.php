<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Contracts\ShareTrackingContract;
use App\Enums\ShareOutcomeType;
use App\Models\User;
use App\Shared\Engagement\EngagementShareOutcomes;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        private ReverseShareOutcomeAction $reverse,
    ) {}

    public function recordBookmark(
        string $bookmarkId,
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
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
        ?Authenticatable $actor = null,
    ): void {
        $this->reverseOutcome(
            ShareOutcomeBusinessKey::eventSave($bookmarkId),
            $reason,
            $actor,
        );
    }

    public function recordResponse(
        string $responseId,
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
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
        ?Authenticatable $actor = null,
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
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
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
        ?Authenticatable $actor = null,
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
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
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
        ?Authenticatable $actor,
    ): void {
        $this->reverse->handle(
            $businessKey,
            $reason,
            $actor instanceof User ? $actor : null,
        );
    }

    private function assertFollowOutcome(ShareOutcomeType $type): void
    {
        if (! in_array($type, self::FOLLOW_OUTCOMES, true)) {
            throw new InvalidArgumentException('Engagement follow outcomes must use a follow share-outcome type.');
        }
    }
}
