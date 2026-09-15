<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\PayloadRedactor;
use AIArmada\Communications\Support\PayloadRedactorService;

/**
 * Payload redactor that tolerates list values.
 *
 * Notification metadata legitimately contains lists (matched entity labels,
 * saved search ids, changed fields). The package redactor assumes every key
 * is a string and throws a TypeError on integer keys, which breaks inbox
 * writes for any notification carrying list metadata. Integer keys can never
 * match sensitive key names, so they pass through untouched while every
 * string-keyed scalar is still judged by the package implementation.
 */
final class ListSafePayloadRedactor implements PayloadRedactor
{
    public function __construct(
        private readonly PayloadRedactorService $inner,
    ) {}

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->redactArray($payload);
    }

    /**
     * @param  array<int|string, mixed>  $request
     * @return array<int|string, mixed>
     */
    public function redactRequest(array $request): array
    {
        return $this->redactArray($request);
    }

    /**
     * @param  array<int|string, mixed>  $response
     * @return array<int|string, mixed>
     */
    public function redactResponse(array $response): array
    {
        return $this->redactArray($response);
    }

    public function redactText(?string $text): ?string
    {
        return $this->inner->redactText($text);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);

                continue;
            }

            if (! is_string($key)) {
                $result[$key] = $value;

                continue;
            }

            $redacted = $this->inner->redact([$key => $value]);
            $result[$key] = $redacted[$key] ?? $value;
        }

        return $result;
    }
}
