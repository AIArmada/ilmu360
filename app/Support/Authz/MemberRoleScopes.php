<?php

namespace App\Support\Authz;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;

final readonly class MemberRoleScopes
{
    public function speaker(): Speaker
    {
        return Speaker::query()->firstOrFail();
    }

    public function institution(): Institution
    {
        return Institution::query()->firstOrFail();
    }

    public function event(): Event
    {
        return Event::query()->firstOrFail();
    }

    public function reference(): Reference
    {
        return Reference::query()->firstOrFail();
    }
}
