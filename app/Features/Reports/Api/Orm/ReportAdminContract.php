<?php

declare(strict_types=1);

namespace App\Features\Reports\Api\Orm;

use App\Features\Reports\Api\ReportSnapshot;

/**
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Reports/FeatureServiceProvider.php, Reports/Services, Reports/EntryPoints
 */
interface ReportAdminContract
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public function modelClass(): string;

    /** @return list<array<string, mixed>> */
    public function fields(): array;

    /** @return array<string, mixed> */
    public function rules(bool $updating = false): array;

    /** @return array<string, mixed> */
    public function createDefaults(): array;

    /** @return array<string, mixed> */
    public function defaultsFor(string $reportId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload, ?string $reportId = null): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(array $payload, ?string $reportId = null): ReportSnapshot;
}
