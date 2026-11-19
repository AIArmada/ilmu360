<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubject;
use App\Shared\Membership\MembershipSubjectCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership subject catalog root adapter.
 *
 * Owner lookup contracts resolve institution, person and reference records; organizations
 * through the Organizations access boundary and events through their registered
 * morph alias. The catalog contract stays in Shared while this implementation
 * stays at the root adapter, so it intentionally imports no App\Models classes.
 */
final readonly class EloquentMembershipSubjectCatalog implements MembershipSubjectCatalog
{
    public function __construct(
        private MembershipDirectory $memberships,
        private InstitutionLookup $institutions,
        private PersonLookup $persons,
        private ReferenceLookup $references,
        private OrganizationAccess $organizations,
    ) {}

    public function forAccount(
        string $accountId,
        MemberSubjectType $subjectType,
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

    public function find(MemberSubjectType $subjectType, string $subjectId): ?MembershipSubject
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => $this->fromSummary($subjectType, $this->institutions->find($subjectId)),
            MemberSubjectType::Person => $this->fromSummary($subjectType, $this->persons->find($subjectId)),
            MemberSubjectType::Reference => $this->fromSummary($subjectType, $this->references->find($subjectId)),
            MemberSubjectType::Organization => $this->fromOrganization($subjectId),
            MemberSubjectType::Event => $this->fromEvent($subjectId),
        };
    }

    public function exists(MemberSubjectType $subjectType, string $subjectId): bool
    {
        return $this->find($subjectType, $subjectId) instanceof MembershipSubject;
    }

    public function resolveIdentifier(MemberSubjectType $subjectType, string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return match ($subjectType) {
            MemberSubjectType::Institution => $this->institutions->resolveIdentifier($identifier),
            MemberSubjectType::Person => $this->persons->resolveIdentifier($identifier),
            MemberSubjectType::Reference => $this->references->resolveIdentifier($identifier),
            MemberSubjectType::Organization => $this->organizations->find($identifier)?->id,
            MemberSubjectType::Event => $this->fromEvent($identifier)?->id,
        };
    }

    /**
     * @param  \App\Features\Institutions\Api\InstitutionSummary|\App\Features\Persons\Api\PersonSummary|\App\Features\References\Api\ReferenceSummary|null  $detail
     */
    private function fromSummary(MemberSubjectType $subjectType, mixed $detail): ?MembershipSubject
    {
        if ($detail === null) {
            return null;
        }

        return new MembershipSubject(
            type: $subjectType,
            id: $detail->id,
            label: $detail->title,
            publicUrl: $detail->publicUrl,
            status: $detail->status,
            imageUrl: $detail->imageUrl,
            adminUrl: $detail->adminUrl,
        );
    }

    private function fromOrganization(string $subjectId): ?MembershipSubject
    {
        $summary = $this->organizations->find($subjectId);

        if (! $summary instanceof OrganizationSummary) {
            return null;
        }

        return new MembershipSubject(
            type: MemberSubjectType::Organization,
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
            type: MemberSubjectType::Event,
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
