<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class MemberSubjectResolver
{
    /**
     * @return class-string<Model>
     */
    public function modelClass(MemberSubjectType $type): string
    {
        return match ($type) {
            MemberSubjectType::Institution => Institution::class,
            MemberSubjectType::Person => Person::class,
            MemberSubjectType::Event => Event::class,
            MemberSubjectType::Reference => Reference::class,
            MemberSubjectType::Organization => Organization::class,
        };
    }

    /**
     * @throws ModelNotFoundException
     */
    public function resolve(MemberSubjectType $type, string $subjectId): Model
    {
        $modelClass = $this->modelClass($type);
        /** @var Model $subject */
        $subject = $modelClass::query()->findOrFail($subjectId);

        return $subject;
    }
}
