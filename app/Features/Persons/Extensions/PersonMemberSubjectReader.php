<?php

declare(strict_types=1);

namespace App\Features\Persons\Extensions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Persons\Models\Person;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use InvalidArgumentException;

final readonly class PersonMemberSubjectReader implements MemberSubjectReader
{
    public function subjectType(): MemberSubjectType
    {
        return MemberSubjectType::Person;
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

        return OwnerContext::withOwner(null, static fn (): array => Person::query()
            
            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get()
            ->map(static fn (Person $model): MemberSubjectRecord => new MemberSubjectRecord(
                type: MemberSubjectType::Person,
                id: (string) $model->getKey(),
                name: (string) $model->name,
                status: (string) $model->status,
            ))
            ->values()
            ->all());
    }
}
