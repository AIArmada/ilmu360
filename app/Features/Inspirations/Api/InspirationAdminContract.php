<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Api;

interface InspirationAdminContract
{
    /**
     * @return class-string
     */
    public function modelClass(): string;

    /** @return list<array<string, mixed>> */
    public function fields(): array;

    /** @return array<string, mixed> */
    public function rules(bool $updating = false): array;

    /** @return array<string, mixed> */
    public function createDefaults(): array;

    /** @return array<string, mixed> */
    public function defaultsFor(string $inspirationId): array;

    /** @return list<string> */
    public function mediaCollections(): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload, ?string $inspirationId = null): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload, ?string $inspirationId = null): InspirationSnapshot;
}
