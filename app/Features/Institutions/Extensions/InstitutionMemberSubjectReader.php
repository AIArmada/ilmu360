<?php

declare(strict_types=1);

namespace App\Features\Institutions\Extensions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use InvalidArgumentException;

final readonly class InstitutionMemberSubjectReader implements MemberSubjectReader
{
    public function subjectType(): MemberSubjectType
    {
        return MemberSubjectType::Institution;
    }

    public function forIds(array $subjectIds, string $orderColumn, string $direction): array
    {
        if ($subjectIds === []) {
            return [];
        }

        if ($orderColumn !== 'name'
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported member subject sorting.');
        }

        return OwnerContext::withOwner(null, static fn (): array => Institution::query()

            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get()
            ->map(static fn (Institution $model): MemberSubjectRecord => new MemberSubjectRecord(
                type: MemberSubjectType::Institution,
                id: (string) $model->getKey(),
                name: (string) $model->name,
                status: $model->status->value,
            ))
            ->values()
            ->all());
    }
}
