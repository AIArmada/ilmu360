<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;

/**
 * Generic write-support mechanics shared by resource writers.
 *
 * Domain rules, normalization and persistence stay with the owner writer; only
 * the response-shaping helpers live here.
 */
final class EloquentResourceWriterSupport
{
    use BuildsAdminFieldMetadata;

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        $destructiveMediaFields = $this->destructiveMediaFields($normalizedPayload);

        return [
            'normalized_payload' => $this->previewPayload($normalizedPayload),
            'warnings' => array_values(array_map(
                fn (string $field): array => [
                    'code' => 'destructive_media_clear',
                    'field' => $field,
                    'message' => $this->destructiveMediaWarning($field),
                ],
                $destructiveMediaFields,
            )),
            'destructive_media_fields' => $destructiveMediaFields,
        ];
    }
}
