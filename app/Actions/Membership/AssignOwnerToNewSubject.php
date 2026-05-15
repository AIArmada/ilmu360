<?php

declare(strict_types=1);

namespace App\Actions\Membership;

use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class AssignOwnerToNewSubject
{
    use AsAction;

    public function __construct(
        private AddMemberToSubject $addMemberToSubject,
        private MemberRoleCatalog $memberRoleCatalog,
    ) {}

    public function handle(Institution|Speaker|Event|Reference $subject, User $member): void
    {
        $subjectType = MemberSubjectType::forSubject($subject);

        $this->addMemberToSubject->handle(
            $subject,
            $member,
            $this->memberRoleCatalog->primaryRoleName($subjectType),
        );
    }
}
