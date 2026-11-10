<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Admin form/record boundary for Directory resources.
 */
interface DirectoryAdminContract
{
    public function resourceKey(DirectoryRecordType $type): string;

    public function schema(DirectoryRecordType $type, string $operation = 'create', ?string $recordId = null): DirectoryAdminSchema;

    /**
     * @return array<string, mixed>
     */
    public function defaults(DirectoryRecordType $type, ?string $recordId = null): array;

    public function record(DirectoryRecordType $type, string $recordId): ?DirectoryAdminRecord;
}
