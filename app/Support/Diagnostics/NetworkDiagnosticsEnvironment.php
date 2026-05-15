<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

class NetworkDiagnosticsEnvironment
{
    /**
     * @var array<string, string>|null
     */
    protected ?array $dotenvValues = null;

    public function __construct(
        protected ?string $environmentFilePath = null,
    ) {
        $this->environmentFilePath ??= app()->environmentFilePath();
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->value($key);

        if ($value === null) {
            return $default;
        }

        $resolved = trim($value);

        return $resolved !== '' ? $resolved : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->string($key);

        if ($value === null) {
            return $default;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return is_bool($normalized) ? $normalized : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->string($key);

        if ($value === null || ! is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->string($key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function value(string $key): ?string
    {
        $runtimeValue = $this->runtimeValue($key);

        if ($runtimeValue !== null && trim($runtimeValue) !== '') {
            return $runtimeValue;
        }

        $dotenvValues = $this->dotenvValues();

        return $dotenvValues[$key] ?? null;
    }

    protected function runtimeValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || ! is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return array<string, string>
     */
    protected function dotenvValues(): array
    {
        if (is_array($this->dotenvValues)) {
            return $this->dotenvValues;
        }

        $path = $this->environmentFilePath;

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return $this->dotenvValues = [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            return $this->dotenvValues = [];
        }

        $values = [];

        foreach ($lines as $line) {
            $parsedLine = $this->parseLine($line);

            if ($parsedLine === null) {
                continue;
            }

            [$key, $value] = $parsedLine;
            $values[$key] = $value;
        }

        return $this->dotenvValues = $values;
    }

    /**
     * @return array{string, string}|null
     */
    protected function parseLine(string $line): ?array
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
            return null;
        }

        if (str_starts_with($trimmedLine, 'export ')) {
            $trimmedLine = trim(substr($trimmedLine, 7));
        }

        if (! str_contains($trimmedLine, '=')) {
            return null;
        }

        [$key, $rawValue] = explode('=', $trimmedLine, 2);
        $normalizedKey = trim($key);

        if ($normalizedKey === '') {
            return null;
        }

        return [$normalizedKey, $this->parseValue($rawValue)];
    }

    protected function parseValue(string $rawValue): string
    {
        $value = trim($rawValue);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(["\'])(.*)\1(?:\s+#.*)?$/s', $value, $matches) === 1) {
            $unwrapped = $matches[2];

            return $matches[1] === '"' ? stripcslashes($unwrapped) : $unwrapped;
        }

        $withoutComment = preg_replace('/\s+#.*$/', '', $value);

        return trim(is_string($withoutComment) ? $withoutComment : $value);
    }
}
