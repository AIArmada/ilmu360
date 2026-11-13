<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/**
 * Owns browser-selection state for share attribution. The persisted selection
 * is attribution authority; the cookie only transports the browser key.
 *
 * The boundary exposes scalar selection identifiers, never mutable models.
 */
interface BrowserSelectionWriter
{
    public function lockOrCreate(string $browserKey): string;

    public function lockExisting(string $browserKey): ?string;

    public function currentAttributionId(string $selectionId): ?string;

    public function accept(string $selectionId, string $attributionId): void;

    public function clear(?string $selectionId = null, ?string $browserKey = null): void;
}
