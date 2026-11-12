<?php

declare(strict_types=1);

namespace App\Support\Membership;

use App\Features\Directory\Api\DirectoryDetail;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubject;
use App\Shared\Membership\MembershipSubjectCatalog;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership subject catalog root adapter.
 *
 * Directory records resolve through the Directory public reader, organizations
 * through the Organizations access boundary and events through their registered
 * morph alias. The catalog contract stays in Shared while this implementation
 * stays at the root adapter, so it intentionally imports no App\Models classes.
 */
final readonly class EloquentMembershipSubjectCatalog implements MembershipSubjectCatalog
{
    public function __construct(
        private MembershipDirectory $memberships,
        private DirectoryReader $directory,
        private OrganizationAccess $organizations,
    ) {}

    public function forAccount(
        string $accountId,
        MembershipSubjectType $subjectType,
        int $limit = 50,
    ): array {
        $subjectIds = array_slice(
            $this->memberships->subjectIdsFor($accountId, $subjectType),
            0,
            max(0, $limit),
        );

        $subjects = [];

        foreach ($subjectIds as $subjectId) {
            $subject = $this->find($subjectType, $subjectId);

            if ($subject instanceof MembershipSubject) {
                $subjects[] = $subject;
            }
        }

        return $subjects;
    }

    public function find(MembershipSubjectType $subjectType, string $subjectId): ?MembershipSubject
    {
        return match ($subjectType) {
            MembershipSubjectType::Institution => $this->fromDirectory(DirectoryRecordType::Institution, $subjectId),
            MembershipSubjectType::Person => $this->fromDirectory(DirectoryRecordType::Person, $subjectId),
            MembershipSubjectType::Reference => $this->fromDirectory(DirectoryRecordType::Reference, $subjectId),
            MembershipSubjectType::Organization => $this->fromOrganization($subjectId),
            MembershipSubjectType::Event => $this->fromEvent($subjectId),
        };
    }

    public function exists(MembershipSubjectType $subjectType, string $subjectId): bool
    {
        return $this->find($subjectType, $subjectId) instanceof MembershipSubject;
    }

    private function fromDirectory(DirectoryRecordType $recordType, string $subjectId): ?MembershipSubject
    {
        $detail = $this->directory->find($recordType, $subjectId);

        if (! $detail instanceof DirectoryDetail) {
            return null;
        }

        return new MembershipSubject(
            type: MembershipSubjectType::from($recordType->value),
            id: $detail->id,
            label: $detail->title,
            publicUrl: $detail->publicUrl,
            status: $detail->status,
            imageUrl: $detail->imageUrl,
        );
    }

    private function fromOrganization(string $subjectId): ?MembershipSubject
    {
        $summary = $this->organizations->find($subjectId);

        if (! $summary instanceof OrganizationSummary) {
            return null;
        }

        return new MembershipSubject(
            type: MembershipSubjectType::Organization,
            id: $summary->id,
            label: $summary->name,
            publicUrl: route('dashboard.organizations.show', ['organization' => $summary->id]),
            status: $summary->status,
        );
    }

    private function fromEvent(string $subjectId): ?MembershipSubject
    {
        $modelClass = MorphModelResolver::resolve('event');
        $event = $modelClass::query()->find($subjectId);

        if (! $event instanceof Model) {
            return null;
        }

        $slug = $event->getAttribute('slug');

        return new MembershipSubject(
            type: MembershipSubjectType::Event,
            id: (string) $event->getKey(),
            label: (string) ($event->getAttribute('title') ?? $event->getAttribute('name') ?? ''),
            publicUrl: is_string($slug) && $slug !== ''
                ? route('events.show', ['event' => $slug])
                : null,
            status: $this->scalarValue($event->getAttribute('status')),
        );
    }

    private function scalarValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
