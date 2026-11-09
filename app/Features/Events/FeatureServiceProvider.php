<?php

declare(strict_types=1);

namespace App\Features\Events;

use App\Platform\FeatureModule;

/**
 * Events owns event authoring, submissions, registration, and attendance.
 *
 * The public GuestSubmissionClaims boundary is implemented by the conventional
 * submission action until the remaining Events tables are cut over.
 */
class FeatureServiceProvider extends FeatureModule {}
