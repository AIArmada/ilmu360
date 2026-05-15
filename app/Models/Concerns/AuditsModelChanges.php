<?php

namespace App\Models\Concerns;

use App\Support\Auditing\FixedValueRedactor;
use BackedEnum;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Events\AuditCustom;

trait AuditsModelChanges
{
    use Auditable;

    /**
     * @var array<string, class-string>
     */
    protected array $attributeModifiers = [
        'password' => FixedValueRedactor::class,
        'remember_token' => FixedValueRedactor::class,
        'checkin_token' => FixedValueRedactor::class,
        'token' => FixedValueRedactor::class,
    ];

    /**
     * @return list<string>
     */
    public function generateTags(): array
    {
        $tags = [];

        if (app()->bound('request')) {
            $request = request();

            if ($request->is('api/*')) {
                $tags[] = 'api';
            }

            if ($request->is('mcp/*')) {
                $tags[] = 'mcp';
            }
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        if (filled($panelId)) {
            $tags[] = 'filament';
            $tags[] = "panel:{$panelId}";
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordCustomAudit(string $event, array $oldValues, array $newValues): void
    {
        if ($oldValues === [] && $newValues === []) {
            return;
        }

        $previousAuditEvent = $this->auditEvent;
        $previousAuditCustomOld = is_array($this->auditCustomOld ?? null) ? $this->auditCustomOld : [];
        $previousAuditCustomNew = is_array($this->auditCustomNew ?? null) ? $this->auditCustomNew : [];
        $previousIsCustomEvent = $this->isCustomEvent;

        $this->auditEvent = $event;
        $this->auditCustomOld = $oldValues;
        $this->auditCustomNew = $newValues;
        $this->isCustomEvent = true;
        $this->preloadResolverData();

        event(new AuditCustom($this));

        $this->auditEvent = $previousAuditEvent;
        $this->auditCustomOld = $previousAuditCustomOld;
        $this->auditCustomNew = $previousAuditCustomNew;
        $this->isCustomEvent = $previousIsCustomEvent;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordCustomAuditDifferences(string $event, array $before, array $after): void
    {
        $oldValues = [];
        $newValues = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $attribute) {
            $beforeHasValue = array_key_exists($attribute, $before);
            $afterHasValue = array_key_exists($attribute, $after);
            $beforeValue = $before[$attribute] ?? null;
            $afterValue = $after[$attribute] ?? null;

            if ($this->auditValuesMatch($beforeValue, $afterValue)) {
                continue;
            }

            if ($beforeHasValue) {
                $oldValues[$attribute] = $beforeValue;
            }

            if ($afterHasValue) {
                $newValues[$attribute] = $afterValue;
            }
        }

        $this->recordCustomAudit($event, $oldValues, $newValues);
    }

    /**
     * @return array<string, string>
     */
    public function formatAuditFieldsForPresentation(string $field, Audit $record): array
    {
        $values = data_get($record, $field);

        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->mapWithKeys(fn (mixed $value, string $attribute): array => [
                $this->auditFieldLabel($attribute) => $this->stringifyAuditValue($attribute, $value),
            ])
            ->all();
    }

    private function auditFieldLabel(string $attribute): string
    {
        $mapping = config("filament-auditing.mapping.{$attribute}");

        if (is_array($mapping) && filled($mapping['label'] ?? null)) {
            return (string) $mapping['label'];
        }

        return Str::headline($attribute);
    }

    private function auditValuesMatch(mixed $before, mixed $after): bool
    {
        return $this->normalizeComparableAuditValue($before) === $this->normalizeComparableAuditValue($after);
    }

    private function normalizeComparableAuditValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Collection => $this->normalizeComparableAuditValue($value->all()),
            is_array($value) => $this->normalizeComparableAuditArray($value),
            is_bool($value), is_int($value), is_float($value), is_string($value), $value === null => $value,
            is_object($value) => method_exists($value, '__toString')
                ? (string) $value
                : ($this->jsonEncodeAuditValue($value) ?? '[object]'),
            default => (string) $value,
        };
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function normalizeComparableAuditArray(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $nestedValue): mixed => $this->normalizeComparableAuditValue($nestedValue),
                $value,
            );
        }

        ksort($value);

        return collect($value)
            ->map(fn (mixed $nestedValue): mixed => $this->normalizeComparableAuditValue($nestedValue))
            ->all();
    }

    private function stringifyAuditValue(string $attribute, mixed $value): string
    {
        $mappedValue = $this->resolveMappedAuditValue($attribute, $value);

        if ($mappedValue !== null) {
            return $mappedValue;
        }

        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Collection => $this->stringifyAuditArray($value->all()),
            is_array($value) => $this->stringifyAuditArray($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_object($value) => method_exists($value, '__toString')
                ? (string) $value
                : ($this->jsonEncodeAuditValue($value) ?? '[object]'),
            $value === null => 'null',
            default => (string) $value,
        };
    }

    private function resolveMappedAuditValue(string $attribute, mixed $value): ?string
    {
        if (! is_scalar($value) || $value === '') {
            return null;
        }

        $mapping = config("filament-auditing.mapping.{$attribute}");

        if (! is_array($mapping)) {
            return null;
        }

        $modelClass = $mapping['model'] ?? null;
        $field = $mapping['field'] ?? null;

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_string($field)) {
            return null;
        }

        $related = $modelClass::query()->find($value);

        if (! $related instanceof Model) {
            return null;
        }

        $displayValue = data_get($related, $field);

        return filled($displayValue) ? (string) $displayValue : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function stringifyAuditArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        if (! array_is_list($value)) {
            foreach (['name', 'title', 'label', 'file_name', 'id'] as $key) {
                $displayValue = $value[$key] ?? null;

                if (filled($displayValue)) {
                    return (string) $displayValue;
                }
            }

            return $this->jsonEncodeAuditValue($value) ?? '[]';
        }

        return collect($value)
            ->map(fn (mixed $nestedValue): string => is_array($nestedValue)
                ? $this->stringifyAuditArray($nestedValue)
                : $this->stringifyAuditValue('value', $nestedValue))
            ->implode(', ');
    }

    private function jsonEncodeAuditValue(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
