<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Raw, owner-verified historic affiliate rows are persisted by the host package
 * adapter. The calling feature owns authorization, financial guards and expiry.
 */
interface AffiliateSnapshotPersistence
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return string The persisted affiliate identifier
     */
    public function restoreProfile(array $attributes): string;

    /**
     * @param  'link'|'attribution'|'touchpoint'|'conversion'  $kind
     * @param  array<string, mixed>  $attributes
     */
    public function restoreChild(string $kind, array $attributes): void;
}
