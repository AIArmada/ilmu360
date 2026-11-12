<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support;

use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Resolve the contribution subject/entity metadata for a model.
 *
 * The conventional ResolveContributionEntityMetadataAction stays with the root
 * read actions that still use it; the moved lifecycle actions resolve through
 * the Shared subject enum so the owning feature never imports the root query.
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
