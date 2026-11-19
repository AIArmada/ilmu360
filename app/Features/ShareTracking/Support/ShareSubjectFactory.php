<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Support;

use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Shared\ShareTracking\ShareSubjectType;
use Illuminate\Database\Eloquent\Model;

final class ShareSubjectFactory
{
    public static function fromModel(Model $model): ShareSubject
    {
        $id = (string) $model->getKey();

        $type = match (true) {
            $model instanceof Event => ShareSubjectType::Event,
            $model instanceof Institution => ShareSubjectType::Institution,
            $model instanceof Person => ShareSubjectType::Person,
            $model instanceof Series => ShareSubjectType::Series,
            $model instanceof Reference => ShareSubjectType::Reference,
            default => ShareSubjectType::Page,
        };

        $title = match (true) {
            $model instanceof Event => self::stringOrNull($model->title),
            $model instanceof Institution => self::stringOrNull($model->name),
            $model instanceof Person => self::stringOrNull($model->formatted_name),
            $model instanceof Series => self::stringOrNull($model->title),
            $model instanceof Reference => self::stringOrNull($model->title),
            default => null,
        };

        return new ShareSubject(
            type: $type->value,
            id: $type === ShareSubjectType::Page ? null : $id,
            key: $type === ShareSubjectType::Page
                ? 'page:model:'.$model->getMorphClass().':'.$id
                : $type->value.':'.$id,
            title: $title,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
