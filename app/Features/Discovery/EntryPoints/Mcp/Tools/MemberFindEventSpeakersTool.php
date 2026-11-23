<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class MemberFindEventSpeakersTool extends EventSubmissionLookupTool
{
    #[\Override]
    protected string $name = 'member-find-event-speakers';

    #[\Override]
    protected string $title = 'Find Event Submission Speakers';

    #[\Override]
    protected string $description = 'Find people selectable as speakers for an event submission. Use the matching UUIDs in the persons list.';

    #[\Override]
    protected function find(User $member, string $query, int $limit): array
    {
        return $this->catalogs->submitPersons($member, $query, $limit);
    }
}
