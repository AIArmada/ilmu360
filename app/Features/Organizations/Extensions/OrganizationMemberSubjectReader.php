<?php

declare(strict_types=1);

namespace App\Features\Organizations\Extensions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Organizations\Models\Organization;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use InvalidArgumentException;

final readonly class OrganizationMemberSubjectReader implements MemberSubjectReader
{
    public function subjectType(): MemberSubjectType
    {
        return MemberSubjectType::Organization;
    }

    public function forIds(array $subjectIds, string $orderColumn, string $direction): array
    {
        if ($subjectIds === []) {
            return [];
        }

        if (! in_array($orderColumn, ['name'], true)
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported member subject sorting.');
        }

        return OwnerContext::withOwner(null, static fn (): array => Organization::query()
            
            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get()
            ->map(static fn (Organization $model): MemberSubjectRecord => new MemberSubjectRecord(
                type: MemberSubjectType::Organization,
                id: (string) $model->getKey(),
                name: (string) $model->name,
                status: (string) ($model->getAttribute('status') ?? ''),
            ))
            ->values()
            ->all());
    }
}
