# AIArmada package adoption audit

Date: 2026-07-14

## Scope and evidence

This audit covers every direct `aiarmada/*` requirement in the root
`composer.json` and the installed source under `/Users/Saiffil/Herd/commerce/packages`.
The application-side map was checked against the codebase knowledge graph,
then verified against the actual PHP source, relations, service providers,
routes, tests, and Filament registrations. Package names below are direct
requirements, not inferred transitive dependencies.

The guiding cutover rule is forward-only: app code must use the package's
canonical schema and actions. No app-side aliases, compatibility wrappers, or
parallel persistence paths are retained.

## Direct package inventory

| Package | Native capability used by ilmu360° | Decision |
| --- | --- | --- |
| `aiarmada/addressing` | Country, state/city/area hierarchy, address normalization, snapshots, formatting, `HasAddresses`, geographic providers | Native package models/actions are authoritative. App keeps product-specific address presentation and Google Places adapters. |
| `aiarmada/affiliates` | Affiliates, links, attribution, touchpoints, conversion ledger, commission states and owner scoping | Native package actions now create links and record outcomes. App keeps share URL policy, outcome metadata, and Signals presentation. |
| `aiarmada/authz` | Permission keys, scoped abilities, impersonation, Filament authorization | Used natively throughout app policies, resources, MCP, and API authorization. |
| `aiarmada/commerce-support` | Owner context/scope, shared model traits, money/reporting/support contracts | Used as the cross-package foundation. App-specific policy remains in app services. |
| `aiarmada/communications` | Communication records, delivery lifecycle, notification inbox, preferences, consent, suppression, rendering and delivery contracts | Native persistence and inbox services are used. App resolver implementations remain because they contain app policy and integrations. |
| `aiarmada/contacting` | Contact methods, social profiles, normalization, `HasContactMethods`, `HasSocialProfiles` | Used natively by app models, forms, seeders, APIs, and Filament. |
| `aiarmada/engagement` | Follows, bookmarks, responses, saved searches, reactions, subscriptions, reminders, sharing primitives | Native traits/models/services are used for the app's follow/bookmark/respond/saved flows. Product-specific limits and presentation remain app-owned. |
| `aiarmada/events` | Event aggregate, occurrences/sessions, registrations, attendance, lifecycle, taxonomy/classifications, series, search seams, submissions | Native actions/models/seams are authoritative. App subclasses and adapters retain ilmu360° lifecycle, taxonomy vocabulary, prayer rules, search ranking, and public projection policy. |
| `aiarmada/filament-addressing` | Filament address/geography components | Registered and consumed natively. |
| `aiarmada/filament-authz` | Filament permission and role resources | Registered and consumed natively. |
| `aiarmada/filament-communications` | Communication and notification administration | Registered and consumed natively. |
| `aiarmada/filament-contacting` | Contact/social profile administration | Registered and consumed natively. |
| `aiarmada/filament-engagement` | Engagement administration | Registered and consumed natively. |
| `aiarmada/filament-events` | Event package resources and integrations | Registered; app resources compose product-specific UI over package models/actions. |
| `aiarmada/filament-inventory` | Inventory administration | Registered for package capability; no duplicate app inventory implementation or runtime `AIArmada\\Inventory` import exists. |
| `aiarmada/filament-seating` | Seating administration | Registered for optional seating capability; app currently only needs the package `SeatMap` in its event save workflow. |
| `aiarmada/filament-signals` | Signals administration, reports, tracked properties and ingestion UI | Registered and consumed natively; app keeps product event naming and tracked-property policy. |
| `aiarmada/filament-ticketing` | Ticketing administration | Registered for optional capability; no duplicate app ticketing implementation or runtime `AIArmada\\Ticketing` import exists. |
| `aiarmada/inventory` | Inventory allocation, reservations and stock workflows | No app-side duplicate was found. It is present for package/plugin composition but not an active ilmu360° domain flow. |
| `aiarmada/membership` | Membership applications, approvals, invitations, roles, hooks/notifiers and member models | Native actions, contracts, enums, models, and traits are used. App keeps member-facing policy and UI composition. |
| `aiarmada/moderation` | Generic moderation action records, blocks, moderation states and base actions | Package primitives are used where equivalent. The app `ModerationService` remains because its event review state machine, reasons, Signals, notifications, and visibility rules are product policy. |
| `aiarmada/references` | Reference model, reference parts, types and relationships | Native package `Reference` and reference relations are used. |
| `aiarmada/seating` | Seat maps, allocation, holds, release, rendering contracts | Package `SeatMap` is used where needed; unused optional allocation flows are not duplicated in the app. |
| `aiarmada/signals` | Signal ingestion, trackers, reports, listeners, affiliate integrations | Native ingestion/actions/services are used. App bridges remain thin adapters for ilmu360° event metadata and tracked-property policy. |
| `aiarmada/ticketing` | Passes, transfers, delivery, bundles and ticketing workflows | No active app ticketing flow or duplicate implementation was found; the package remains an optional registered capability. |

## Refactors completed in this pass

### Affiliate links and outcomes

The app's `AffiliatesShareTrackingService` no longer directly creates
`AffiliateLink` or `AffiliateConversion` records. It delegates to:

- `AIArmada\\Affiliates\\Actions\\Affiliates\\CreateTrackingLink`
- `AIArmada\\Affiliates\\Actions\\Conversions\\RecordAffiliateOutcome`

The package gained two generic seams:

- `CreateTrackingLink` accepts a caller-supplied validated absolute tracking
  URL, which supports product-specific signed/canonical URLs without moving
  share policy into the package.
- `RecordAffiliateOutcome` records an idempotent attributed conversion for
  non-cart outcomes and dispatches the package conversion event by default.

The app deliberately disables the package event for this path because its
existing Signals bridge emits the app-specific signal payload. This is an
explicit adapter boundary, not a second conversion persistence path.

Conversion records now use only the real package columns:
`subject_type`, `subject_identifier`, `subject_instance`,
`subject_title_snapshot`, `external_reference`, `value_minor`, and the
canonical lifecycle fields. Invalid conversion aliases such as
`cart_identifier`, `cart_instance`, `order_reference`, and `total_minor` were
removed from the package conversion DTO/model/UI path and from the app share
path.

### Event series

The app-only `App\\Models\\EventSeries` pivot was removed. The package now owns
the reusable `AIArmada\\Events\\Models\\EventSeriesItemPivot` adapter, while
the package's `EventSeries`/`EventSeriesItem` aggregate remains intact. The app
`Event` and `Series` relations use this package pivot and the canonical
`sort_order` column. The adapter synchronizes the polymorphic seriesable fields
when a host uses the package's event-specific `event_id` attach shape.

### Dead and incomplete app paths removed

The following app-only leftovers had no live callers or did not implement a
complete workflow and were deleted:

- duplicate `AppContentRenderer` classes under `app/Services` and
  `app/Support`;
- empty notification listeners `RecordNotificationSent` and
  `HandleNotificationFailed` (the communications package already owns native
  notification capture);
- the unfinished `DeliveryFallbackListener` and its service-provider
  registration.

The app's pass-through `SignalEventRecorder` was also deleted. The Signals
package now exposes `Contracts\\SignalEventIngestor`, binds it to the native
`IngestSignalEvent` action, and the app's product/affiliate/mobile consumers
depend directly on that contract. This keeps the package action native while
leaving a legitimate generic injection seam for failure handling and host
integrations.

## Intentionally app-owned boundaries

These are not package duplicates:

- `App\\Models\\Event` is the product projection/subclass with ilmu360°
  casts, media, API relationships, and public presentation behavior.
- `ModerationService` coordinates the app's event submission/review policy and
  side effects; the moderation package supplies generic primitives, not this
  product workflow.
- `EventSearchService` adds Typesense/database fallback, viewer-timezone date
  boundaries, prayer-relative filters, nearby discovery, taxonomy labels, and
  public ranking. The package search engine remains the reusable lower-level
  seam.
- API/MCP/Filament Data objects are stable public product projections and are
  not replacements for package persistence DTOs.
- Google Maps/place selection, app-specific communications resolvers,
  affiliate share URL classification, and Signals bridges are integration or
  product-policy adapters.

The app's Spatie tag/admin surface is not used as the event persistence or
public classification path; package event taxonomy owns that path. The
remaining tag code is separately used by app administration/AI ingestion and
must not be mistaken for a hidden compatibility adapter.

## Verification and residual upgrade candidates

The full package affiliate test slice passed (1,201 tests, 2,732 assertions,
with five intentional skips). Focused package conversion DTO/model tests passed
(49 tests, 156 assertions) and the new package affiliate action tests passed (2
tests, 6 assertions; the
parallel command still returns the repository's existing unrelated warning
from `CancelShipmentTest`). App share tracking and affiliate Signals tests
passed (2 tests, 15 assertions). Series relation tests no longer fail on the
old app pivot or missing polymorphic identifiers; the remaining failures are
unrelated existing status/reference fixtures.

The remaining package-level candidates are deliberate follow-up seams rather
than compatibility work: generic taxonomy administration for the app's
localized vocabulary, optional ticketing/inventory/seating activation if the
product acquires those workflows, and further narrowing of app Signals
metadata contracts. None requires retaining the deleted app implementations.
