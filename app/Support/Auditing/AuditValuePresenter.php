<?php

namespace App\Support\Auditing;

use App\Models\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditValuePresenter
{
    /**
     * @return array<string, string>
     */
    public static function values(Audit $audit, string $field, ?Model $ownerRecord = null): array
    {
        $presenter = self::resolvePresenterRecord($audit, $ownerRecord);

        if ($presenter instanceof Model && method_exists($presenter, 'formatAuditFieldsForPresentation')) {
            $values = $presenter->formatAuditFieldsForPresentation($field, $audit);

            return is_array($values) ? $values : [];
        }

        $values = data_get($audit, $field);

        if (! is_array($values)) {
            return [];
        }

        $mappedValues = self::mapRelatedColumns($values);

        return collect($mappedValues)
            ->mapWithKeys(static fn (mixed $value, string $attribute): array => [
                Str::headline($attribute) => self::stringifyValue($value),
            ])
            ->all();
    }

    public static function view(Audit $audit, string $field, ?Model $ownerRecord = null): View
    {
        return view('filament-auditing::tables.columns.key-value', [
            'data' => self::values($audit, $field, $ownerRecord),
        ]);
    }

    private static function resolvePresenterRecord(Audit $audit, ?Model $ownerRecord): ?Model
    {
        if ($ownerRecord instanceof Model) {
            return $ownerRecord;
        }

        $auditable = $audit->auditable;

        return $auditable instanceof Model ? $auditable : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function mapRelatedColumns(array $values): array
    {
        foreach ((array) config('filament-auditing.mapping', []) as $attribute => $mapping) {
            if (! array_key_exists($attribute, $values) || ! is_array($mapping)) {
                continue;
            }

            $label = $mapping['label'] ?? null;
            $modelClass = $mapping['model'] ?? null;
            $field = $mapping['field'] ?? null;

            if (! is_string($label) || ! is_string($modelClass) || ! is_string($field) || ! class_exists($modelClass)) {
                continue;
            }

            $mappedValue = $modelClass::query()->find($values[$attribute])?->{$field};
            $values[$label] = filled($mappedValue) ? $mappedValue : $values[$attribute];
            unset($values[$attribute]);
        }

        return $values;
    }

    private static function stringifyValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => self::stringifyArray($value),
            $value === null => 'null',
            default => (string) $value,
        };
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function stringifyArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        if (! array_is_list($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return collect($value)
            ->map(static function (mixed $item): string {
                if (! is_array($item)) {
                    return self::stringifyValue($item);
                }

                foreach (['name', 'title', 'label', 'file_name', 'id'] as $key) {
                    if (filled($item[$key] ?? null)) {
                        return (string) $item[$key];
                    }
                }

                return (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            })
            ->implode(', ');
    }
}
