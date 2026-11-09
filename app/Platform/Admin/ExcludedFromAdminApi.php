<?php

declare(strict_types=1);

namespace App\Platform\Admin;

/**
 * Marks a Filament resource as excluded from the generic admin API/MCP catalog
 * without making the shared registry depend on the owning feature.
 */
interface ExcludedFromAdminApi {}
