<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class MemberFindEventInstitutionsTool extends EventSubmissionLookupTool
{
    #[\Override]
    protected string $name = 'member-find-event-institutions';

    #[\Override]
    protected string $title = 'Find Event Submission Institutions';

    #[\Override]
    protected string $description = 'Find selectable institutions for an event submission, such as a masjid or surau named on the flyer. Use the matching UUID as primary_organizer_id or location_institution_id.';

    #[\Override]
    protected function find(User $member, string $query, int $limit): array
    {
        return $this->catalogs->submitInstitutions($member, $query, $limit);
    }
}
