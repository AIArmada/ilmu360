<?php

declare(strict_types=1);

namespace App\Services\Signals;

final class ProductSignalSchemaRegistry
{
    /**
     * @return list<string>
     */
    public function allowedProperties(string $eventName): array
    {
        $schemas = [
            'auth.login' => ['method', 'created_account'],
            'auth.signup.completed' => ['has_email', 'has_phone'],
            'auth.password_reset.completed' => [],
            'auth.email_verified' => [],
            'report.submitted' => ['report_id', 'entity_type', 'entity_id', 'category', 'status'],
            'notification.read' => ['notification_id', 'family', 'trigger', 'action_url'],
            'notification.read_all' => ['updated_count'],
            'search.executed' => ['interaction_type', 'surface', 'query', 'filter_keys', 'filters', 'result_count', 'saved_search_id'],
            'listing.filtered' => ['interaction_type', 'surface', 'filter_keys', 'filters', 'result_count', 'saved_search_id'],
            'admin.event_cover.generated' => ['event_id', 'event_status'],
        ];

        if (array_key_exists($eventName, $schemas)) {
            return $schemas[$eventName];
        }

        if (str_starts_with($eventName, 'moderation.event.')) {
            return [
                'event_id', 'moderation_action', 'previous_status', 'current_status',
                'moderator_id', 'reason_code', 'has_note',
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function normalize(string $eventName, array $properties): array
    {
        $allowed = array_flip($this->allowedProperties($eventName));

        $normalized = [];

        foreach ($properties as $key => $value) {
            if (! isset($allowed[$key]) || $this->isEmpty($value)) {
                continue;
            }

            if ($this->isSensitiveSearchValue($key, $value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function isSensitiveSearchValue(string $key, mixed $value): bool
    {
        if ($key !== 'query' || ! is_string($value)) {
            return false;
        }

        return preg_match('/(?:@|\+?\d[\d\s().-]{6,}|password|token|secret|api[_ -]?key|credit\s*card)/i', $value) === 1;
    }
}
