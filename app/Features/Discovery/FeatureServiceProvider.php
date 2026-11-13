<?php

declare(strict_types=1);

namespace App\Features\Discovery;

use App\Platform\FeatureModule;

/**
 * Discovery is the app-root owner of the read-only public discovery surfaces:
 * event listings/search, the flattened public schedule, directory listings,
 * catalog composition and sitemaps. It claims no tables or cache stores.
 *
 * The wholesale read-service move is closed: query execution and hydration
 * stay in root read adapters so callers keep Event models and paginators
 * without rehydration. Discovery intentionally ships no Api contract and no
 * bindings; `owns: {}` is the accepted boundary.
 *
 * Cache version keys stay with their existing owners: the
 * `person/institution/reference_*_version` keys are split between Platform and
 * Directory, and Discovery must not claim them.
 *
 * Must stay root adapters, documented here so later slices do not absorb them:
 * - EventSearchService, PostgresEventDiscovery and TypesenseEventDiscovery
 *   (event search and flattened-schedule query execution).
 * - PublicScheduleDiscoveryService and PublicScheduleLeaf.
 * - FrontendCatalogService (composes addressing, taxonomy, directory and
 *   membership).
 * - EventNotificationService (notification orchestration and saved-search
 *   matching).
 * - HTTP/Livewire entrypoints and the API DTO/serializer layer.
 *
 * Engagement tables remain package-canonical (`owns: {}`). Sitemaps keep their
 * product-owned visibility filter and index URL mismatch until that decision
 * is made.
 */
class FeatureServiceProvider extends FeatureModule {}
