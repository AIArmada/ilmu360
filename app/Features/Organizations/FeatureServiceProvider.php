<?php

declare(strict_types=1);

namespace App\Features\Organizations;

use App\Platform\FeatureModule;

/**
 * Organizations owns organization behaviour while package migrations and
 * models remain canonical.
 *
 * The interim adapters live in App\Support\Organizations until O1/O2 move the
 * runtime implementations behind this feature. Their container bindings are
 * registered in AppServiceProvider so feature code keeps no root dependency.
 */
final class FeatureServiceProvider extends FeatureModule {}
