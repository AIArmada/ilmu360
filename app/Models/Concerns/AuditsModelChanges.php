<?php

namespace App\Models\Concerns;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Support\FixedValueRedactor;
use AIArmada\CommerceSupport\Support\OwnerContext;
use BackedEnum;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Audit;

trait AuditsModelChanges
{
    use HasCommerceAudit;

    /**
     * @var array<string, class-string>
     */
    protected array $attributeModifiers = [
        'password' => FixedValueRedactor::class,
        'remember_token' => FixedValueRedactor::class,
        'token' => FixedValueRedactor::class,
        'account_number' => FixedValueRedactor::class,
        'duitnow_value' => FixedValueRedactor::class,
        'two_factor_secret' => FixedValueRedactor::class,
        'two_factor_recovery_codes' => FixedValueRedactor::class,
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

        $resolveRelated = static fn (): ?Model => $modelClass::query()->find($value);

        /** @var Model|null $related */
        $related = OwnerContext::hasOverride()
            ? $resolveRelated()
            : OwnerContext::withOwner(null, $resolveRelated);

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
