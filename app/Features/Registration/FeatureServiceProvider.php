<?php

declare(strict_types=1);

namespace App\Features\Registration;

use App\Features\Registration\Api\AdmissionFacts;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Platform\FeatureModule;

/**
 * Registration owns event admissions, attendees and attendance from R1 onward.
 *
 * R0 is a seam-only slice: the feature claims no tables and exposes only the
 * scalar AdmissionFacts read boundary used by Events authoring. The following
 * writers remain root-owned and are recorded for the later migration slices:
 *
 * - root Registration model: booted() repeats the vendor cascade and deletes
 *   check-in rows on a direct registration delete. The vendor cascade runs
 *   from the registration `deleting` hook and does remove participants,
 *   answers, items and attendances for direct deletes, but root keeps the
 *   hook until R6 verifies every delete path and the participant-only
 *   attendance edge.
 * - package registration/attendance actions, checkout orchestration and the
 *   vendor Filament registration importer/check-in pages stay external
 *   writers until their owning slices (R1-R4) move them.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AdmissionFacts::class, DatabaseAdmissionFacts::class);
    }
}
