<?php

declare(strict_types=1);

namespace App\Enums\Rewards;

/**
 * Independent allowlist of source outcome kinds the rewards core may name in
 * program rules. Values mirror the current source taxonomy; this enum does
 * not import in-flight source implementations and must not be read as proof
 * that a source fact exists. Unknown outcome strings are rejected.
 */
enum RewardEligibleOutcome: string
{
    case Signup = 'signup';
    case EventRegistration = 'event_registration';
    case EventCheckin = 'event_checkin';
    case EventSubmission = 'event_submission';
    case EventSave = 'event_save';
    case EventGoing = 'event_going';
    case InstitutionFollow = 'institution_follow';
    case PersonFollow = 'person_follow';
    case SeriesFollow = 'series_follow';
    case ReferenceFollow = 'reference_follow';
    case SavedSearchCreated = 'saved_search_created';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
