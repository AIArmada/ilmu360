<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

enum EventSubmissionOrganizerKind: string
{
    case Institution = 'institution';
    case Person = 'person';
}
