<?php

namespace App\Actions\Contributions;

use App\Enums\ContributionSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ResolveContributionEntityMetadataAction
{
    use AsAction;

    /**
     * @return array{
     *     subject_type: ContributionSubjectType,
     *     entity_type: string,
     *     entity_id: string
     * }
     */
    public function handle(Model $entity): array
    {
        return [
            'subject_type' => match (true) {
                $entity instanceof Event => ContributionSubjectType::Event,
                $entity instanceof Institution => ContributionSubjectType::Institution,
                $entity instanceof Speaker => ContributionSubjectType::Speaker,
                $entity instanceof Reference => ContributionSubjectType::Reference,
                default => throw new RuntimeException('Unsupported contribution entity type.'),
            },
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => (string) $entity->getKey(),
        ];
    }
}
