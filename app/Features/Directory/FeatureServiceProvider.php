<?php

declare(strict_types=1);

namespace App\Features\Directory;

use App\Platform\FeatureModule;

/**
 * Directory owns institutions, speakers, references, venues, spaces and their
 * shared contacts, addresses, names and relations.
 *
 * The public Api boundaries are implemented by conventional root adapters until
 * the owned tables and models move into this feature (S1+). Bindings stay in
 * AppServiceProvider for that reason; do not move them here before the models.
 */
class FeatureServiceProvider extends FeatureModule {}
