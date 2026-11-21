<?php

declare(strict_types=1);

namespace App\Features\Events\Extensions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Models\Event;
use App\Features\Membership\Api\MemberSubjectReader;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class EventMemberSubjectReader implements MemberSubjectReader
{
    public function subjectType(): MemberSubjectType
    {
        return MemberSubjectType::Event;
    }

    public function forIds(array $subjectIds, string $orderColumn, string $direction): array
    {
        if ($subjectIds === []) {
            return [];
        }

        if (! in_array($orderColumn, ['created_at', 'title', 'starts_at'], true)
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported member subject sorting.');
        }

        return OwnerContext::withOwner(null, static fn (): array => Event::query()
            ->with(['institution:id,name', 'venue:id,name'])
            ->whereKey($subjectIds)
            ->orderBy($orderColumn, $direction)
            ->get()
            ->map(static fn (Event $model): MemberSubjectRecord => new MemberSubjectRecord(
                type: MemberSubjectType::Event,
                id: (string) $model->getKey(),
                name: (string) $model->title,
                status: (string) $model->status,
                startsAt: $model->starts_at instanceof \Carbon\CarbonInterface ? CarbonImmutable::instance($model->starts_at) : null,
                institutionName: $model->institution?->name,
                venueName: $model->venue?->name,
            ))
            ->values()
            ->all());
    }
}
