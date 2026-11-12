<?php

declare(strict_types=1);

namespace App\Features\Discovery;

use App\Platform\FeatureModule;

/**
 * Discovery is the future owner of the read-only public discovery surfaces:
 * event listings/search, the flattened public schedule, directory listings,
 * catalog composition and sitemaps. It claims no tables or cache stores.
 *
 * D0 intentionally ships no Api contract and no bindings. Every discovery
 * implementation is still a root adapter, and creating a speculative contract
 * before the D1 callers move would freeze the wrong boundary. The accepted
 * slice order is: D1 moves the event discovery/search/schedule services behind
 * a Discovery Api, D2 moves the directory listing/search services, and D3
 * rewires catalogs through the resulting Directory/Platform boundaries.
 *
 * Cache version keys and store ownership are intentionally out of D0. The
 * `person/institution/reference_*_version` keys are split between Platform and
 * Directory today and are unified in D0a; Discovery must not claim them early.
 *
 * Must stay root adapters, documented here so later slices do not absorb them:
 * - FrontendCatalogService (composes addressing, taxonomy, directory and
 *   membership; D3 keeps the controller and service root).
 * - PublicScheduleLeaf (the public value assembled by the schedule service).
 * - EventNotificationService (notification orchestration and saved-search
 *   matching; D5 adds a targeted matcher without moving the service).
 * - HTTP/Livewire entrypoints and the API DTO/serializer layer.
 *
 * Sitemaps are explicitly not part of D0: their visibility filter and the
 * index URL mismatch are product decisions recorded for the cleanup slice.
 */
class FeatureServiceProvider extends FeatureModule {}
