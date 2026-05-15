<?php

declare(strict_types=1);

namespace App\Enums;

enum DawahShareOutcomeType: string
{
    case Signup = 'signup';
    case EventRegistration = 'event_registration';
    case EventCheckin = 'event_checkin';
    case EventSubmission = 'event_submission';
    case EventSave = 'event_save';
    case EventGoing = 'event_going';
    case InstitutionFollow = 'institution_follow';
    case SpeakerFollow = 'speaker_follow';
    case SeriesFollow = 'series_follow';
    case ReferenceFollow = 'reference_follow';
    case SavedSearchCreated = 'saved_search_created';
}
