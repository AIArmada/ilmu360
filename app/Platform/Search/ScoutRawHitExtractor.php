<?php

declare(strict_types=1);

namespace App\Platform\Search;

use Illuminate\Database\Eloquent\Model;

final class ScoutRawHitExtractor
{
    /**
     * Extract normalized string record IDs from a raw Scout search payload.
     *
     * Supports Typesense (`hits[].document.id`), Meilisearch (`hits[].id`),
     * Algolia (`hits[].objectID`), and generic Scout result arrays.
     *
     * @return list<string>
     */
    public function extractIds(mixed $rawResults): array
    {
        if (! is_array($rawResults)) {
            return [];
        }

        $hits = null;

        if (isset($rawResults['hits']) && is_array($rawResults['hits'])) {
            $hits = $rawResults['hits'];
        } elseif (isset($rawResults['results']) && is_array($rawResults['results'])) {
            $hits = $rawResults['results'];
        } elseif (array_is_list($rawResults)) {
            $hits = $rawResults;
        }

        if ($hits === null) {
            return [];
        }

        $ids = [];

        foreach ($hits as $hit) {
            $id = $this->extractHitId($hit);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function extractHitId(mixed $hit): ?string
    {
        if ($hit instanceof Model) {
            $key = $hit->getKey();

            return is_scalar($key) && trim((string) $key) !== '' ? trim((string) $key) : null;
        }

        if (! is_array($hit)) {
            return is_scalar($hit) && trim((string) $hit) !== '' ? trim((string) $hit) : null;
        }

        $document = $hit['document'] ?? null;
        $candidate = (is_array($document) ? ($document['id'] ?? null) : null)
            ?? $hit['id']
            ?? $hit['objectID']
            ?? null;

        if (! is_scalar($candidate)) {
            return null;
        }

        $normalized = trim((string) $candidate);

        return $normalized === '' ? null : $normalized;
    }
}
