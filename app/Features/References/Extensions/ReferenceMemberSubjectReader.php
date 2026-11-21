<?php

declare(strict_types=1);

namespace App\Features\References\Extensions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\References\Models\Reference;
use InvalidArgumentException;

final readonly class ReferenceMemberSubjectReader implements MemberSubjectReader
{
    public function subjectType(): MemberSubjectType
    {
        return MemberSubjectType::Reference;
    }

    public function forIds(array $subjectIds, string $orderColumn, string $direction): array
    {
        if ($subjectIds === []) {
            return [];
        }

        if ($orderColumn !== 'title'
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported member subject sorting.');
        }

        return OwnerContext::withOwner(null, static fn (): array => Reference::query()
            ->with(['parentReference.parentReference'])
            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get()
            ->map(static fn (Reference $model): MemberSubjectRecord => new MemberSubjectRecord(
                type: MemberSubjectType::Reference,
                id: (string) $model->getKey(),
                name: $model->displayTitle(),
                status: (string) $model->status,
            ))
            ->values()
            ->all());
    }
}
