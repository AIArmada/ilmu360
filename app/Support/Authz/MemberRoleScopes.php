<?php

namespace App\Support\Authz;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;

final readonly class MemberRoleScopes
{
    public function person(): Person
    {
        return Person::query()->firstOrFail();
    }

    public function speaker(): Person
    {
        return $this->person();
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
