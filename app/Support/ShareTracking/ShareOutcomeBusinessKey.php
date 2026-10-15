<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Enums\DawahShareOutcomeType;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Canonical share-outcome business identity.
 *
 * Every outcome key is caller-generated from the authoritative source record:
 * `share-outcome:{outcome_type}:{source_transition_id}`. The source
 * transition ID is the immutable business transition (user, registration,
 * checkin, submission, bookmark, response, follow, saved search) with the
 * exact source prefix for its outcome type and the source record UUID.
 * Client input never supplies the identity, and no affiliate/link/type
 * fallback derives it.
 */
final class ShareOutcomeBusinessKey
{
    public const string NAMESPACE = 'share-outcome';

    /**
     * @return array<string, string>
     */
    public static function sourcePrefixes(): array
    {
        return [
            DawahShareOutcomeType::Signup->value => 'user:',
            DawahShareOutcomeType::EventRegistration->value => 'registration:',
            DawahShareOutcomeType::EventCheckin->value => 'checkin:',
            DawahShareOutcomeType::EventSubmission->value => 'submission:',
            DawahShareOutcomeType::EventSave->value => 'bookmark:',
            DawahShareOutcomeType::EventGoing->value => 'response:',
            DawahShareOutcomeType::InstitutionFollow->value => 'follow:',
            DawahShareOutcomeType::PersonFollow->value => 'follow:',
            DawahShareOutcomeType::SeriesFollow->value => 'follow:',
            DawahShareOutcomeType::ReferenceFollow->value => 'follow:',
            DawahShareOutcomeType::SavedSearchCreated->value => 'saved_search:',
        ];
    }

    public static function for(
        DawahShareOutcomeType $type,
        string $sourceTransitionId,
    ): string {
        $sourceTransitionId = trim($sourceTransitionId);

        if ($sourceTransitionId === '' || mb_strlen($sourceTransitionId) > 120 || str_contains($sourceTransitionId, "\0")) {
            throw new InvalidArgumentException('Source transition ID must be a non-empty bounded identity of at most 120 characters.');
        }

        self::assertCanonicalSourceTransition($type, $sourceTransitionId);

        $key = self::NAMESPACE.':'.$type->value.':'.$sourceTransitionId;

        if (mb_strlen($key) > 191) {
            throw new InvalidArgumentException('Share outcome business key exceeds 191 characters.');
        }

        return $key;
    }

    public static function signup(string $userId): string
    {
        return self::for(DawahShareOutcomeType::Signup, 'user:'.trim($userId));
    }

    public static function eventRegistration(string $registrationId): string
    {
        return self::for(DawahShareOutcomeType::EventRegistration, 'registration:'.trim($registrationId));
    }

    public static function eventCheckin(string $checkinId): string
    {
        return self::for(DawahShareOutcomeType::EventCheckin, 'checkin:'.trim($checkinId));
    }

    public static function eventSubmission(string $submissionId): string
    {
        return self::for(DawahShareOutcomeType::EventSubmission, 'submission:'.trim($submissionId));
    }

    public static function eventSave(string $bookmarkId): string
    {
        return self::for(DawahShareOutcomeType::EventSave, 'bookmark:'.trim($bookmarkId));
    }

    public static function eventGoing(string $responseId): string
    {
        return self::for(DawahShareOutcomeType::EventGoing, 'response:'.trim($responseId));
    }

    public static function follow(DawahShareOutcomeType $type, string $followId): string
    {
        return self::for($type, 'follow:'.trim($followId));
    }

    public static function savedSearch(string $savedSearchId): string
    {
        return self::for(DawahShareOutcomeType::SavedSearchCreated, 'saved_search:'.trim($savedSearchId));
    }

    /**
     * @return array{namespace: string, outcome_type: string, source_transition_id: string}
     */
    public static function parse(string $businessKey, DawahShareOutcomeType $expectedType): array
    {
        $businessKey = trim($businessKey);

        if ($businessKey === '' || mb_strlen($businessKey) > 191 || str_contains($businessKey, "\0")) {
            throw new InvalidArgumentException('Share outcome business key must be a non-empty bounded identity of at most 191 characters.');
        }

        $parts = explode(':', $businessKey, 3);

        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Share outcome business key must be a canonical namespace:type:source identity.');
        }

        [$namespace, $type, $sourceTransitionId] = $parts;

        if ($namespace !== self::NAMESPACE) {
            throw new InvalidArgumentException('Share outcome business key must use the canonical share-outcome namespace.');
        }

        if ($type !== $expectedType->value) {
            throw new InvalidArgumentException('Share outcome business key type must match the declared outcome type.');
        }

        $sourceTransitionId = trim($sourceTransitionId);

        if ($sourceTransitionId === '' || mb_strlen($sourceTransitionId) > 120) {
            throw new InvalidArgumentException('Share outcome source transition ID must be a non-empty bounded identity of at most 120 characters.');
        }

        self::assertCanonicalSourceTransition($expectedType, $sourceTransitionId);

        return [
            'namespace' => $namespace,
            'outcome_type' => $type,
            'source_transition_id' => $sourceTransitionId,
        ];
    }

    public static function assertCanonicalSourceTransition(DawahShareOutcomeType $type, string $sourceTransitionId): void
    {
        $prefix = self::sourcePrefixes()[$type->value] ?? null;

        if (! is_string($prefix) || ! str_starts_with($sourceTransitionId, $prefix)) {
            throw new InvalidArgumentException('Share outcome source transition ID must use the exact source prefix for its outcome type.');
        }

        $sourceId = substr($sourceTransitionId, strlen($prefix));

        if ($sourceId === '' || ! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException('Share outcome source transition ID must carry the authoritative source record UUID.');
        }
    }
}
