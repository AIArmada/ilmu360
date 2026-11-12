<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Support;

use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolve the contribution subject/entity metadata for a model.
 *
 * Shared by the Contributions feature lifecycle actions and the root read
 * queries so both resolve the subject through the Shared subject enum.
 */
final class ContributionEntityMetadata
{
    /**
     * @return array{
     *     subject_type: ContributionSubjectType,
     *     entity_type: string,
     *     entity_id: string
     * }
     */
    public static function for(Model $entity): array
    {
        foreach (ContributionSubjectType::cases() as $subjectType) {
            if (is_a($entity, $subjectType->modelClass())) {
                return [
                    'subject_type' => $subjectType,
                    'entity_type' => $entity->getMorphClass(),
                    'entity_id' => (string) $entity->getKey(),
                ];
            }
        }

        throw new RuntimeException('Unsupported contribution entity type.');
    }
}
