<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolve the contribution subject/entity metadata for a model.
 *
 * Shared by the Contributions submit and update-context actions so both
 * resolve the subject through the canonical subject enum.
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
