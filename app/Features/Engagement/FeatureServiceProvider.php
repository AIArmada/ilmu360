<?php

declare(strict_types=1);

namespace App\Features\Engagement;

use App\Platform\FeatureModule;

/**
 * Engagement is the future owner of follow, save and going behaviour over the
 * package engagement tables. Those tables and the package
 * DefaultEngagementManager are package-canonical, so this manifest claims
 * nothing (`owns: {}`) and adds no columns or parallel stores.
 *
 * D4a added the read-side seams only: `EventEngagement`/`SubjectFollows`
 * expose immutable scalar state. D4b adds the root save writer actions
 * (`App\Actions\Engagement\SaveEventAction`/`UnsaveEventAction`); the package
 * manager remains the canonical writer and callers never reproduce its
 * orchestration. Implementations are root adapters bound in
 * AppServiceProvider.
 *
 * Must stay root adapters, documented here so later slices do not absorb them:
 * - EngagementAccountLifecycleContributor (account deletion/restoration
 *   snapshots and restores, registered in AppServiceProvider).
 * - HTTP/Livewire entrypoints (the API controllers, card components and page
 *   traits that call the named engagement actions).
 * Share outcome reversal remains ShareTracking's contract; D4 composes it from
 * the engagement actions rather than moving it in.
 */
class FeatureServiceProvider extends FeatureModule {}
