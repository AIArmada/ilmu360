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
 * D0 intentionally ships no Api contract and no bindings. The current reads
 * still run through the root EloquentEngagementReader adapter, while the
 * Livewire/API entrypoints own the save/follow/going orchestration. D4 moves
 * that orchestration into named actions and exposes the feature Api over the
 * package manager; a contract created now would have no consumer.
 *
 * Must stay root adapters, documented here so later slices do not absorb them:
 * - EngagementAccountLifecycleContributor (account deletion/restoration
 *   snapshots and restores, registered in AppServiceProvider).
 * - HTTP/Livewire entrypoints (the API controllers, card components and page
 *   traits that currently call the package manager and share-outcome writer).
 * Share outcome reversal remains ShareTracking's contract; D4 composes it from
 * the engagement actions rather than moving it in.
 */
class FeatureServiceProvider extends FeatureModule {}
