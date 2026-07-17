# First-class metadata hard-cut implementation plan

Status: implementation-ready plan only. No application or package code is changed by this document.

This plan supersedes the recommendations in tasks/institution-id-metadata-trace.md where that report says counters are fine in JSON or treats every event field as a candidate for an events-table column. The trace remains useful for finding old call sites, but this document is the source of truth for the target architecture.

## Objective

Remove first-class application state from JSON metadata without replacing it with a collection of redundant columns.

For each current metadata key, choose exactly one final treatment:

1. A real indexed column when the value belongs to the record and is filtered, sorted, joined, constrained, or independently updated.
2. An existing normalized package relation when the package already models the concept.
3. A lifecycle status/timestamp already owned by the package.
4. A counter or analytics source owned by the appropriate package.
5. Deletion when the value is unused or fabricated.
6. Metadata only when the value is opaque, provider-specific, historical, or genuinely schema-flexible and is never used as operational state.

The final implementation must contain no metadata fallback, no dual write, no alias for a removed storage key, and no data-copy migration from JSON.

## Repositories and ownership

There are two working repositories:

| Repository | Root | Responsibility |
| --- | --- | --- |
| ilmu360 application | /Users/Saiffil/Herd/ilmu360 | ilmu360-only institution location, product policies, forms, APIs, serializers, authorization, search integration |
| Commerce packages | /Users/Saiffil/Herd/commerce | Generic Events, Engagement, Affiliates, Communications, and References schema and behavior |

All reusable package changes must be made in /Users/Saiffil/Herd/commerce/packages/*.

Do not edit a copied package under vendor. Do not add an application migration for a generic package field. Because this project is still using a destructive development cutover, edit the canonical package create migrations and rebuild the database.

The one deliberate application-owned addition to the package events table is events.institution_id. Institution is an ilmu360 model and must not be introduced into the generic Events package.

## Non-negotiable hard-cut rules

- Do not read a promoted value from metadata.
- Do not write a promoted value to metadata.
- Do not backfill a new field from metadata.
- Do not preserve old request keys as aliases unless the key is still the intentionally selected public product contract.
- Do not add model accessors that silently redirect removed metadata fields.
- Do not keep custom query-builder JSON rewrites for removed fields.
- Do not introduce database foreign-key constraints or cascades.
- Do not add SoftDeletes.
- Use UUID primary and reference columns throughout.
- Modify canonical package create migrations and run migrate:fresh --seed.
- Delete compatibility migrations and legacy cleanup paths instead of maintaining them.
- Keep package relations and package lifecycle workflows as the source of truth.
- Keep public response projections separate from persistence. A serializer may expose a product term such as saves_count, but it must calculate it from the canonical source and must not make it a fillable Event attribute.
- Every query used by public search, dashboards, or analytics must stop filtering or ordering on JSON.

## Canonical domain meanings

The following links are separate and must never be collapsed into one institution or user field:

| Meaning | Canonical storage | Example |
| --- | --- | --- |
| Event location institution | app-owned indexed events.institution_id | The event is held at Masjid A |
| Event room or hall | event_locations.venue_space_id | Main Hall inside Masjid A |
| Package venue | events.default_venue_id, plus EventLocation when a venue space is selected | Convention Centre B |
| Organizer | event_involvements with role_code = organizer | Masjid A or Speaker C organizes it |
| Owner/manager subject | events.owner_type and events.owner_id | Institution, speaker, or user that manages it |
| Creator | events.created_by_type and events.created_by_id | User who created the record |
| Submitter | event_submissions.submitter_type and submitter_id | Guest or user who submitted it for review |

For “Masjid A in Main Hall” the final records are:

- events.institution_id = Masjid A UUID
- events.default_venue_id = null
- primary event_locations.venue_space_id = Main Hall UUID
- Main Hall remains a VenueSpace/Space associated to Masjid A through institution_space
- organizer is stored independently in event_involvements

Use location_role = primary consistently. Remove the application’s current main value because package readers already look for primary.

For “Venue B in Hall 2” the final records are:

- events.institution_id = null
- events.default_venue_id = Venue B UUID
- primary event_locations.venue_id = Venue B UUID
- primary event_locations.venue_space_id = Hall 2 UUID when supplied

## Final metadata disposition matrix

### Event and event-package records

| Current value | Current source | Final source | Owner | Final action |
| --- | --- | --- | --- | --- |
| institution_id | events.metadata in the old design; native column in current dirty work | events.institution_id | application | Keep the real nullable indexed column; delete the JSON migration/backfill |
| user_id | events.metadata | owner_type/owner_id | Events package | Delete Event user_id; use owner morph |
| submitter_id | events.metadata | event_submissions.submitter_type/submitter_id | Events package | Delete Event submitter_id; query submissions |
| schedule_kind | events.metadata | events.schedule_kind | Events package | Add a real enum-backed indexed column |
| schedule_state | events.metadata | primary event_occurrences.status and lifecycle timestamps | Events package | Delete the app enum and event field |
| timing_mode | events.metadata | event_time_expressions.time_mode | Events package relation | Derive prayer-relative mode from a prayer expression; absence means absolute |
| starts_at / ends_at fallback | Event metadata fallback | primary EventOccurrence | Events package | Remove every metadata fallback and occurrence metadata mirror |
| views_count | events.metadata | Signals analytics | Signals package | Remove from Event and public event payloads; do not add an Event column |
| registrations_count | events.metadata | count of event_registrations | Events package relation | Use withCount/aggregate queries; never persist on Event |
| saves_count | events.metadata | engagement_counters type bookmarks | Engagement package | Keep only a serializer projection if the product API still wants saves_count |
| going_count | events.metadata | engagement_counters type responses, key going | Engagement package | Keep only a serializer projection if the product API still wants going_count |
| occurrence schedule mirrors | event_occurrences.metadata | native occurrence fields and time-expression relation | Events package | Delete mirror writes |
| package attribute/audience/time mirrors | Event/Occurrence/Session metadata | normalized package child tables | Events package | Delete EventMetadataSyncService and metadata-sync config |
| event update status | event_updates.metadata.status | published_at and archived_at | Events package | Delete EventChangeStatus |
| changed_fields | event_updates.metadata | metadata | application audit payload | Retain; immutable audit context, not query state |
| before_snapshot / after_snapshot | event_updates.metadata | metadata | application audit payload | Retain; immutable audit snapshots |
| involvement role_code | involvement metadata fallback | event_involvements.role_code | Events package | Remove fallback |
| involvement sort_order | involvement metadata fallback | event_involvements.sort_order | Events package | Remove fallback |
| key-person name | event_involvements.metadata.name | event_involvements.display_name | Events package | Add nullable real column |
| check-in lat/lng/accuracy | event_attendances.metadata | nowhere | none | Delete; currently factory-only and unused |
| registration primary participant draft | event_registrations.metadata | event_registration_participants | Events package | Delete draft and saved-hook synchronization |
| participant email/phone mirror | participant metadata.contact | Contacting contact_methods | Contacting/Events packages | Delete mirror and query contact_methods |
| checkin_token | registration metadata | nowhere | none | Delete; it is emitted but no check-in flow consumes it |

### Venue, reference, communication, taxonomy, and affiliate records

| Current value | Current source | Final source | Owner | Final action |
| --- | --- | --- | --- | --- |
| venue description | venues.metadata.description | venues.description | Events package | Add text column |
| venue facilities | venues.metadata.facilities | facility_types + venue_facilities | Events package | Normalize and sync relation |
| reference part_type | ref_references.metadata | ref_references.part_type | References package | Add nullable indexed string |
| reference part_number | ref_references.metadata | ref_references.part_number | References package | Add nullable string |
| reference part_label | ref_references.metadata | ref_references.part_label | References package | Add nullable string |
| reference is_canonical | ref_references.metadata | ref_references.is_canonical | References package | Add boolean default false and index |
| push platform | communication_destinations.metadata | communication_destinations.platform | Communications package | Add nullable indexed string |
| app_version | communication_destinations.metadata | communication_destinations.app_version | Communications package | Add nullable string |
| device_label | communication_destinations.metadata | communication_destinations.device_label | Communications package | Add nullable string |
| locale | communication_destinations.metadata | communication_destinations.locale | Communications package | Add nullable string |
| timezone | communication_destinations.metadata | communication_destinations.timezone | Communications package | Add nullable string |
| last_seen_at | communication_destinations.metadata | communication_destinations.last_seen_at | Communications package | Add nullable timestampTz and index |
| term selectable flag | event_terms.metadata.selectable | nowhere | none | Delete; no runtime consumer exists |
| term requires_speaker | event_terms.metadata | event_term_policies row | application | Move to app-owned normalized policy table |
| term requires_physical_delivery | event_terms.metadata | event_term_policies row | application | Move to app-owned normalized policy table |
| affiliate guest identity | affiliates.metadata.majlis_guest_id | external_reference_type + external_reference | Affiliates package | Add generic real fields and composite unique index |
| affiliate subject UUID | subject metadata | subject_id | Affiliates package | Add native UUID field to link/attribution/touchpoint/conversion records |
| affiliate subject key | subject_identifier plus metadata fallbacks | subject_key | Affiliates package | Hard-rename the source schema; no subject_identifier alias |
| affiliate link ID | attribution/touchpoint/conversion metadata | affiliate_link_id | Affiliates package | Add UUID and indexes |
| tracking mode | attribution metadata.tracking_mode | attribution_type | Affiliates package | Add indexed string |
| touchpoint event type | touchpoint metadata.event_type | touchpoint_type | Affiliates package | Add indexed string |
| visit kind | touchpoint metadata.visit_kind | interaction_type | Affiliates package | Add indexed string |
| share provider | several metadata keys | channel | Affiliates package | Use one native channel field |
| share origin | metadata or misused subject_instance | origin | Affiliates package | Add indexed string |
| visitor key | metadata.visitor_key | visitor_key | Affiliates package | Add indexed string |
| visited URL | metadata.visited_url | url | Affiliates package | Add text/string column |
| referrer URL | touchpoint metadata | referrer_url | Affiliates package | Add nullable string/text |
| sharer user | attribution/conversion metadata | sharer_user_id | Affiliates package | Add nullable UUID |
| outcome actor | conversion metadata | actor_user_id | Affiliates package | Add nullable UUID |
| outcome key | conversion metadata.outcome_key fallback | external_reference | Affiliates package | Use existing native field only |

## Metadata that deliberately remains metadata

Do not normalize JSON merely because it exists. Retain these categories:

- Event update changed_fields, before_snapshot, and after_snapshot.
- Opaque third-party provider request/response fragments that are not filtered, sorted, joined, or used as lifecycle state.
- Notification preference maps such as per-family and per-trigger channel choices. These are user configuration documents with evolving keys and are read as a unit.
- Provider-specific communication destination payload that is not one of the promoted device fields.
- Affiliate request context that is useful only as an immutable diagnostic snapshot, such as a sanitized query map. Do not retain copies of promoted IDs, types, provider, origin, subject, or URL.
- Event location address_snapshot, which is an explicitly named historical snapshot rather than generic metadata.
- Event submission submission_data, which is the submitted form document and is not a live Event state store.
- Search-document-only facets. They may be denormalized in the external search document, but not written back to model metadata.

If a retained key later appears in a where, orderBy, join, uniqueness check, authorization decision, or lifecycle transition, it must be promoted before that query ships.

## Package implementation plan

### Change set P01 — Events schema primitives

Modify:

- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000001_create_events_table.php
- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000002_create_event_occurrences_table.php
- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000004_create_event_venues_table.php
- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000007_create_event_locations_table.php
- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000012_create_event_involvements_table.php

Tasks:

- Add events.schedule_kind as string(32), default single, indexed.
- Add an index on event_occurrences(event_id, starts_at) for primary-occurrence projections.
- Add an index on event_occurrences(event_id, status, starts_at) for lifecycle-aware public queries.
- Add an index on event_locations(event_id, location_role, sort_order).
- Add nullable text venues.description.
- Add nullable string event_involvements.display_name.
- Remove the PostgreSQL GIN index on events.metadata. The final application has no operational Event JSON query, so the GIN index would only add write cost.
- Do not add institution_id to the package migration.
- Do not add schedule_state, timing_mode, starts_at, ends_at, or counters to events.

Acceptance:

- A fresh package migration contains the new columns and indexes.
- No package migration uses constrained or cascadeOnDelete.
- Package migration tests pass on SQLite and the configured primary database.

### Change set P02 — Events enum, models, and relationships

Modify/add:

- /Users/Saiffil/Herd/commerce/packages/events/src/Enums/ScheduleKind.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/Event.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/EventOccurrence.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/EventLocation.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/EventInvolvement.php
- package factories and model tests

Tasks:

- Move the single, multi_day, and custom_chain schedule enum into AIArmada\Events\Enums\ScheduleKind.
- Cast Event.schedule_kind to that enum and include it in fillable/defaults.
- Add Event.primaryLocation() as a HasOne constrained to location_role = primary and ordered by sort_order then created_at.
- Add EventInvolvement.display_name to docs/fillable.
- Do not add a name accessor backed by metadata.
- Add query scopes for occurrence status only when a repeated call site exists; otherwise use explicit relationship queries.
- Keep Event.primaryOccurrence as an ordered relationship; do not implement UUID-unsafe MAX(id) ofMany logic.

Acceptance:

- Event can be created with no schedule_kind input and reads ScheduleKind::Single.
- Primary occurrence and primary location resolve deterministically.
- Involvement display_name persists in its column and metadata remains untouched.

### Change set P03 — Submission model resolution

Modify:

- /Users/Saiffil/Herd/commerce/packages/events/config/events.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Support/ModelResolver.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/Event.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Models/EventSubmission.php

Tasks:

- Add events.models.submission with EventSubmission as the package default.
- Add ModelResolver::submissionClass().
- Add Event.submissions() using the configured submission class.
- Make EventSubmission.event() resolve the configured Event class.
- Add an ordered originalSubmission query/relation using submitted_at, created_at, then id as ordering only. Do not use MAX on a UUID.
- Do not add Event.submitter_id or a compatibility accessor.

Acceptance:

- The package default models work without app overrides.
- The application can configure App\Models\EventSubmission and eager-load submissions.submitter from App\Models\Event.

### Change set P04 — Occurrence lifecycle as the only schedule state

Modify:

- /Users/Saiffil/Herd/commerce/packages/events/src/Contracts/EventLifecycleWorkflow.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Services/DefaultEventLifecycleWorkflow.php
- occurrence state classes and focused tests only if an existing transition is incomplete

Tasks:

- Verify postpone, cancel, reschedule, and publish transitions operate on EventOccurrence and set the matching timestamp plus last_state_change_at.
- Add only missing workflow methods; do not build an application-status bridge into the package.
- Make rescheduling update dates and occurrence lifecycle atomically.
- Ensure an ordinary date/title synchronization does not reset an occurrence’s status.
- Keep application moderation Event status separate from occurrence schedule status.

Acceptance:

- Postponing sets occurrence status and postponed_at.
- Cancelling sets occurrence status and cancelled_at.
- Rescheduling sets rescheduled_at and the new dates.
- Saving unrelated Event fields does not change occurrence status.

### Change set P05 — Remove Events metadata mirroring

Delete/refactor:

- /Users/Saiffil/Herd/commerce/packages/events/src/Services/EventMetadataSyncService.php
- EventAttributeObserver
- EventAudienceObserver
- EventTimeExpressionObserver
- EventsServiceProvider registrations
- events.sync attributes_to_metadata, audiences_to_metadata, and time_expressions_to_metadata config
- events.attribute_sync.always_rebuild config
- /Users/Saiffil/Herd/commerce/tests/src/Events/EventMetadataSyncServiceTest.php

Tasks:

- Delete EventMetadataSyncService entirely.
- Remove all observer calls that copy child records into Event, EventOccurrence, or EventSession metadata.
- Keep observers only when they invalidate/build a search document directly from normalized child rows.
- Keep audiences_to_facets and classification-to-facet behavior as search-document behavior, not database metadata behavior.
- Update EventSearchDocumentBuilder so attributes, audiences, classifications, and time expressions are loaded from relations.
- Rewrite EventSearchIndexingObserversTest to assert child changes request reindexing while parent metadata remains unchanged.
- Remove old config setup from /Users/Saiffil/Herd/commerce/tests/src/TestCase.php.

Acceptance:

- Creating/updating/deleting an audience, attribute, or time expression never mutates parent metadata.
- Search documents still contain the expected external facets.
- No reference to EventMetadataSyncService or the deleted config flags remains.

### Change set P06 — Venue facility normalization

Modify/add:

- Events Venue model and schema from P01
- FacilityType and VenueFacility models as needed
- a package action such as SyncVenueFacilitiesAction
- package action tests

Tasks:

- Add Venue.description to fillable/docs.
- Implement one package action that accepts a replacement set of facility codes.
- Resolve only active FacilityType rows by code.
- In a transaction, delete VenueFacility rows omitted from the replacement set and upsert rows that remain, with availability = available and visibility = public unless explicitly supplied.
- Reject unknown facility codes; do not silently store them in metadata.
- Return/load venueFacilities with facilityType to avoid N+1 reads.
- Keep the full VenueFacility model for future quantity/capacity/fee detail.

Acceptance:

- Replacing parking + oku with women_section leaves exactly one normalized relation.
- Explicit empty input clears the relation.
- venues.metadata never contains description or facilities.

### Change set P07 — Registration contact single source

Modify:

- /Users/Saiffil/Herd/commerce/packages/events/database/migrations/2000_01_01_000015_create_event_registration_participants_table.php
- /Users/Saiffil/Herd/commerce/packages/events/src/Services/RegistrationService.php
- EventRegistration and EventRegistrationParticipant models/tests

Tasks:

- Add is_purchaser as a native boolean column with default false. It is a real package participant role used by registration entry points.
- Stop placing participant email, phone, company, or purchaser fields in participant metadata.
- Persist name and other native participant fields on EventRegistrationParticipant.
- Persist email and phone only through HasContactMethods.
- Remove company from accepted participant input because no package or application consumer exists. Do not leave it in metadata as a hidden contract.
- Keep EventRegistration resolvePrimaryParticipantEmail/Phone reading Contacting relations.
- Add a reusable scope/helper for matching a primary participant contact through contact_methods so the app does not hand-write metadata JSON queries.

Acceptance:

- A free registration creates one primary participant and one contact row per supplied email/phone.
- Participant metadata contains no contact object.
- Mail routing still resolves the primary participant email.

### Change set P08 — Engagement counters

Modify:

- /Users/Saiffil/Herd/commerce/packages/engagement/database/migrations/2000_06_01_000009_create_engagement_counters_table.php
- EngagementCounter model
- EngagementCounterService contract
- DefaultEngagementCounterService
- package event listeners/provider registration
- package tests and a reconciliation command

Schema:

- Make counter_key non-null with default empty string.
- Add unique index on subject_type, subject_id, counter_type, counter_key.
- Add lookup index on subject_type, subject_id, counter_type.

Behavior:

- Empty counter_key represents the total for a counter type.
- bookmarks + empty key stores the active bookmark total.
- responses + empty key stores the active response total.
- responses + going stores the active going response total.
- Add value(subject, counterType, counterKey = '').
- Add targeted recalculation methods so one bookmark does not recount unrelated reactions/follows.
- Listen to BookmarkCreated, BookmarkRemoved, BookmarkArchived, ResponseCreated, ResponseChanged, and ResponseCancelled.
- A ResponseChanged event must recalculate the old response key and the new response key.
- Recalculate synchronously after the source row is committed unless the package already supplies an after-commit listener convention.
- Add a command to reconcile counters from source rows using chunkById. This is an operational repair command, not a legacy backfill.

Acceptance:

- Repeated bookmark/unbookmark operations cannot create duplicate counter rows.
- Going to interested decrements going and increments interested.
- Restoring/removing users causes source records and counters to agree.
- Counter reads are one indexed lookup and do not count the source table on every public request.

### Change set P09 — Communications device fields

Modify:

- /Users/Saiffil/Herd/commerce/packages/communications/database/migrations/2000_01_01_000019_create_communication_destinations_table.php
- CommunicationDestination model/factory/tests

Add:

- platform string(32), nullable, indexed
- app_version string(50), nullable
- device_label string(255), nullable
- locale string(16), nullable
- timezone string(64), nullable
- last_seen_at timestampTz, nullable, indexed

Tasks:

- Add fillable, property documentation, and immutable_datetime cast.
- Metadata remains available only for provider-specific opaque data.
- Do not keep device-field accessors that fall back to metadata.

Acceptance:

- Push destinations can be filtered by platform and ordered by last_seen_at without JSON.
- A fresh destination’s metadata does not contain any promoted device field.

### Change set P10 — References part fields

Modify:

- /Users/Saiffil/Herd/commerce/packages/references/database/migrations/2000_01_01_000001_create_references_table.php
- package Reference model/factory/tests

Add:

- part_type string(32), nullable, indexed
- part_number string(50), nullable
- part_label string(255), nullable
- is_canonical boolean, default false, indexed
- composite index on parent_id, part_type, part_number

Tasks:

- Add native casts/fillable/docs.
- Keep parent_id as the row hierarchy.
- Do not use reference_parts JSON as a fallback for these row-level fields. It is a separate package feature for embedded reference fragments.

Acceptance:

- Root/child filtering and part search use native columns.
- is_canonical is a real boolean.
- Metadata remains unchanged when part fields change.

### Change set P11 — Affiliates first-class tracking schema

Modify canonical create migrations 000001, 000002, 000003, 000006, and 000019 under:

- /Users/Saiffil/Herd/commerce/packages/affiliates/database/migrations

Affiliates:

- Add external_reference_type string(64), nullable.
- Add external_reference string(160), nullable.
- Add unique composite index on external_reference_type, external_reference.

Links:

- Replace subject_identifier with subject_key. Do not keep both.
- Add subject_id as nullable UUID.
- Add origin string(32), nullable, indexed.
- Keep subject_instance only for its package-defined instance meaning; the app must stop using it as origin.
- Index subject_type, subject_id and subject_type, subject_key.

Attributions:

- Replace subject_identifier with subject_key.
- Add subject_id nullable UUID.
- Add affiliate_link_id nullable UUID, indexed.
- Add attribution_type string(32), indexed.
- Add visitor_key string(160), nullable, indexed.
- Add channel string(64), nullable, indexed.
- Add origin string(32), nullable, indexed.
- Add sharer_user_id nullable UUID, indexed.

Touchpoints:

- Replace subject_identifier with subject_key.
- Add subject_id nullable UUID.
- Add affiliate_link_id nullable UUID, indexed.
- Add touchpoint_type string(32), indexed.
- Add interaction_type string(32), nullable, indexed.
- Add visitor_key string(160), nullable, indexed.
- Add channel string(64), nullable, indexed.
- Add origin string(32), nullable, indexed.
- Add url text/string, nullable.
- Add referrer_url text/string, nullable.
- Add a dedupe-supporting index on affiliate_attribution_id, touchpoint_type, touched_at.

Conversions:

- Replace subject_identifier with subject_key.
- Add subject_id nullable UUID.
- Add affiliate_link_id nullable UUID, indexed.
- Add sharer_user_id nullable UUID, indexed.
- Add actor_user_id nullable UUID, indexed.
- Add origin string(32), nullable, indexed.
- Use existing channel and external_reference.

Rules:

- No foreign-key constraints.
- No metadata-copy migration.
- No deprecated subject_identifier accessor.
- Metadata and subject_metadata may retain only unqueried diagnostic/provider context.

### Change set P12 — Affiliates model and service cutover

Modify package affiliate models, data objects, actions, reports, and tests.

Tasks:

- Add fillable/casts/property docs and AffiliateLink relationships for every affiliate_link_id.
- Update all package actions to accept/write subject_id and subject_key.
- Update package reports to filter on native fields.
- Update link uniqueness/reuse logic to use affiliate_id + tracking_url + origin.
- Use external_reference_type = majlis_guest and external_reference = the anonymous identifier for ilmu360 guest affiliates.
- Preserve request query context only after sanitizing secrets and promoted fields.
- Remove every metadata fallback for subject, user, provider, origin, link, visitor, event/touchpoint type, URL, or outcome key.

Acceptance:

- Link, visit, attribution, and conversion analytics issue no JSON predicates.
- Per-link reports group on affiliate_link_id.
- Per-channel reports group on channel.
- Guest profile lookup uses the indexed external reference pair.

## Application implementation plan

### Change set A01 — Destructive baseline and legacy removal

Delete:

- /Users/Saiffil/Herd/ilmu360/database/migrations/2026_07_18_000003_migrate_event_institution_id_from_metadata.php
- /Users/Saiffil/Herd/ilmu360/database/migrations/2026_07_18_000004_remove_legacy_event_type_taxonomy_data.php
- legacy taxonomy deletion blocks in EventTaxonomySeeder

Keep/refine:

- /Users/Saiffil/Herd/ilmu360/database/migrations/2026_07_18_000002_add_institution_id_to_events.php

Tasks:

- Keep institution_id nullable and indexed.
- Add composite index on institution_id, status, visibility for institution public/workspace listings.
- Do not copy institution_id out of metadata.
- Update the institution test to prove a fresh Event writes only the column.
- Establish migrate:fresh --seed as the only supported cutover.
- Add a warning to the implementation PR/release notes that this cannot be deployed over a populated database without an explicitly separate migration project.

Acceptance:

- Fresh migration succeeds using local package source.
- No migration mentions events.metadata.
- No seeder cleans “old” taxonomy or metadata rows.

### Change set A02 — Event identity and authorization

Modify:

- Event creation/update actions
- App\Models\Event and App\Models\EventSubmission
- EventPolicy
- event management checks in Livewire and API controllers
- App\Models\User delete/restore snapshot logic
- contribution/dashboard queries and tests

Creation decision table:

| Context | owner | created_by | submission |
| --- | --- | --- | --- |
| Institution workspace | primary organizer institution when it is the managed institution | authenticated user | only if submitted for moderation |
| Speaker-managed event | managed speaker | authenticated user | only if submitted for moderation |
| Personal advanced event | authenticated user | authenticated user | optional according to moderation flow |
| Authenticated public submission | null until accepted, unless current workflow assigns an owner | authenticated user | submitter = authenticated user |
| Guest public submission | null | null | submitter = guest/contact subject when available, otherwise null |

Tasks:

- Remove user_id and submitter_id from Event metadata-backed/fillable/property lists.
- Remove Event.user() and Event.submitter() belongsTo relations.
- Set owner_type/id and created_by_type/id explicitly in CreateAdvancedEventAction, PersistValidatedEventSubmissionAction, SaveAdminEventAction, and any MCP/admin creation path.
- Derive submitter from event_submissions only.
- Change contribution and dashboard queries from where submitter_id on events to whereHas submissions with submitter_type/id.
- Change owner queries to owner_type/id.
- Update EventPolicy so owner management, institution/speaker membership, creator, and submission rights are checked deliberately.
- Update user deletion/restoration to null/restore created_by fields only when required by policy, handle owner morph records, and update event_submissions. Do not snapshot or restore Event user_id/submitter_id.
- Update notifications to notify the original/relevant submission submitter via the submission relation.

Acceptance:

- Organizer, owner, creator, submitter, and location institution can all differ.
- A submitter can manage only the draft/review rights explicitly granted by policy.
- No Event query or property uses user_id or submitter_id.

### Change set A03 — Explicit event schedule orchestration

Add/refactor an application action dedicated to product schedule input, for example SyncEventScheduleAction.

Inputs:

- schedule_kind
- starts_at
- ends_at
- timezone
- timing_mode
- prayer_reference
- prayer_offset
- prayer_display_text

Tasks:

- Persist schedule_kind on Event using the package enum.
- Create/update the primary EventOccurrence explicitly.
- For prayer_relative, upsert one event_time_expressions row with time_mode prayer_relative and anchor_type prayer.
- For absolute, delete the prayer expression. Absence of a prayer expression is the only representation of absolute.
- Never write timing fields into Event or EventOccurrence metadata.
- Use the package lifecycle workflow for rescheduling an already-published/postponed occurrence.
- Remove schedule sync from Event’s saved hook.
- Remove pending schedule state that depends on magic setAttribute where practical; entry actions must call the schedule action explicitly.
- Remove GenerateEventSlugAction fallback to metadata.starts_at.
- Remove occurrenceStatusValue and all ordinary-save status resetting.

Update every entry point:

- advanced event create/update
- frontend submission persistence
- admin API/MCP create/update
- contribution mutation
- event change announcement
- seeders/factories

Acceptance:

- Single absolute event: Event + one occurrence, no prayer expression.
- Prayer-relative event: Event + one occurrence + one prayer expression.
- Multi-day/custom-chain values persist on Event.
- Reschedule transitions the occurrence and preserves lifecycle timestamps.
- No schedule value exists in metadata.

### Change set A04 — Schedule state consumers

Tasks:

- Delete App\Enums\ScheduleState.
- Replace schedule_state output/filter/snapshot keys with occurrence_status where a public contract needs the value.
- Compare against package occurrence state names/classes.
- Update public listing, check-in eligibility, JSON-LD, reminders, notifications, and change announcements.
- Implement database filters through whereHas primary occurrence/status or an indexed occurrence subquery.
- Derive occurrence_status into the Typesense document.
- Delete the unused paused state; do not invent a replacement.

Acceptance:

- Postponed/cancelled occurrences are excluded or displayed according to the existing product rule.
- Event moderation status is not used as a substitute for occurrence status.
- No schedule_state string remains outside historical prose.

### Change set A05 — Event change announcements

Tasks:

- Delete App\Enums\EventChangeStatus.
- Published scope: published_at is not null and archived_at is null.
- Draft: published_at is null and archived_at is null.
- Archived/retracted: archived_at is not null.
- Stop writing metadata.status.
- Keep changed_fields and snapshots.
- Replace schedule_state in changed_fields/snapshots with occurrence_status.
- Inject/use EventLifecycleWorkflow when announcement type postpones, cancels, or reschedules.
- Continue changing the application Event moderation/status only when the product workflow requires it; do not make it the schedule source.

Acceptance:

- Published announcement queries use only timestamp columns.
- An announcement transition and occurrence transition complete in one transaction.
- Metadata contains snapshots but no status.

### Change set A06 — Location and key-person corrections

Location tasks:

- Keep Event.institution() on the native institution_id column.
- Keep Event.venue() on default_venue_id.
- Store institution Space selection in the primary EventLocation.venue_space_id.
- Use location_role = primary.
- Replace the invalid Event.space() belongsTo assumption with a relation/query through primary EventLocation.
- Validate that a selected Space belongs to the selected Institution.
- Validate that institution and default venue are mutually exclusive in writes.
- Update serializers to show institution + space or venue + venue space without calling either one an address.

Key-person tasks:

- Update EventKeyPersonSyncService to write display_name, role_code, visibility, and sort_order directly.
- Delete HasEventInvolvementRole.
- Remove metadata-backed name.
- Stop using the app aliases role, order_column, is_public, and speaker_id inside persistence. Map form input to package fields in the action.
- Replace metadata->name search with display_name plus the related Speaker name.
- Eager-load involveable/speaker to avoid N+1 display queries.

Acceptance:

- “Masjid A in Main Hall” resolves and renders both entities correctly.
- A venue event does not gain an institution_id.
- A named non-Speaker moderator is searchable by display_name.
- No key-person metadata contains name, role_code, or sort_order.

### Change set A07 — Event counters and analytics

Tasks:

- Remove views_count, registrations_count, saves_count, and going_count from Event fillable, metadata-backed lists, factories, change-detection lists, and EventBuilder JSON mapping.
- Remove writes from EventSaveController, going actions, RegistrationSeeder, User restore/delete handling, and Livewire toggles.
- Delete SyncEventGoingCountAction.
- Let Engagement package listeners update bookmark/response counters.
- Load bookmark and going counters in list/detail queries using indexed counter subqueries or a reusable package loader.
- Use withCount(registrations) at endpoints that need registrations_count.
- Fix InstitutionDashboard: do not call getRawOriginal on a withCount alias. Sum the loaded aggregate or aggregate event_registrations directly.
- Update EventJsonLd capacity logic to use active/capacity-blocking registrations or the package capacity resolver.
- Remove views_count from public payloads and allowed sorts.
- Replace homepage popularity ordering with Engagement counter score. Do not scan Signals events during a public request.
- Use Signals ContentPerformanceReportService/PageViewReportService only in analytics/reporting surfaces.
- Derive search-document engagement counts from Engagement counters and registration count from source rows at index time.

API decision:

- It is acceptable to keep saves_count, going_count, and registrations_count as deliberate response fields because they are product projections.
- They must be readonly serializer values, not Event attributes accepted by writes.
- Remove views_count until a dedicated analytics contract is designed.

Acceptance:

- Bookmark/going API and Livewire responses return correct counts after create/remove/change.
- Event rows and metadata never change when engagement changes.
- Registration seeding creates registrations only and never writes a count.
- Public list queries have a bounded query count.

### Change set A08 — Registration cleanup

Tasks:

- Delete Registration.stagePrimaryParticipant, setCheckinToken, resolvedCheckinToken, metadata draft helpers, and the saved hook that synthesizes a participant.
- Make all registration entry points call the package RegistrationService/RegisterForFreeAction with a participants payload.
- Rewrite forPrimaryContact to query contact_methods joined through the primary EventRegistrationParticipant morph identity.
- Read resolved name from the participant, and email/phone from contact methods, with registrant profile only as the explicitly selected final fallback.
- Remove participant metadata contact writes from app code.
- Remove checkin_token from UserRegistrationItemData, docs, factories, tests, and audit redaction.
- Remove EventCheckin lat/lng/accuracy accessors and factory metadata.
- If a QR credential is needed later, design it through Ticketing Pass credentials and store only a hashed/rotatable credential. Do not resurrect the unused raw token.

Acceptance:

- Registration creation produces participant/contact rows immediately.
- Primary-contact lookup works by email and phone without JSON.
- Registration/check-in metadata has none of the removed keys.

### Change set A09 — Venue and reference application cutover

Venue tasks:

- Remove Venue metadata accessors for description/facilities.
- Use venue_type internally; map an external type field only in request/action code if it remains the chosen API contract.
- Change SaveVenueAction to save description natively and call the package facility sync action.
- Expose facility_codes or a clearly documented facilities list by mapping venueFacilities.facilityType.code in serializers.
- Update forms, admin mutation service, contribution service, factories, seeders, Scout data, and tests.

Reference tasks:

- Remove Reference metadata accessors and ReferenceBuilder metadata rewrites.
- Read/write part_type, part_number, part_label, and is_canonical natively.
- Use year internally; map publication_year only at an intentional API/form boundary.
- Update search SQL to qualify real ref_references columns.
- Update factories/seeders/forms/API/MCP/Scout/tests.

Acceptance:

- Venue detail does not query metadata.
- Facility replacement semantics remain explicit.
- Reference family and search tests use native columns.
- No promoted venue/reference key appears in metadata.

### Change set A10 — Taxonomy policy normalization

Add application-owned schema/model:

- event_term_policies
- UUID id
- event_term_id UUID, indexed
- policy_code string(64), indexed
- is_enabled boolean, default true
- timestampsTz
- unique index on event_term_id + policy_code
- no database FK constraint

Tasks:

- Seed requires_speaker and requires_physical_delivery as policy rows.
- Remove selectable metadata because it has no consumer.
- Refactor EventCategoryPolicy to query/eager-load policy rows.
- Expand selected category descendants before policy evaluation as it does today.
- Ensure repeated evaluation loads all relevant policies in one query.
- Remove metadata->group legacy cleanup.

Acceptance:

- Speaker and physical-delivery validation behavior is unchanged.
- EventTerm metadata is not consulted for application policy.

### Change set A11 — Push destinations

Tasks:

- Update NotificationDestinationController to write native fields.
- Replace pushMeta with a native attribute payload; preserve only provider-specific opaque metadata if present.
- Parse last_seen_at into an immutable timestamp.
- Update NotificationDestinationData and NotificationSettingsManager to read native fields.
- Update account/device lists to order by native last_seen_at.
- Change endpoint descriptions from “replace metadata” to “register/update device details.”
- Update API/docs/restore tests.

Acceptance:

- Create/update/list/delete push destination tests pass.
- Platform and last-seen queries use indexes.
- No promoted device field appears in metadata.

### Change set A12 — Affiliate/share-tracking cutover

Modify:

- AffiliatesShareTrackingService
- AffiliatesShareTrackingAnalyticsService
- AdminShareAnalyticsService
- AffiliateSignalsBridge
- ShareTracking data objects/controllers
- deletion/retention jobs
- DawahShareImpactTest and focused analytics tests

Tasks:

- Write all P11 native fields directly.
- Replace metadata filters/grouping with native columns.
- Map app subject classification to subject_type, subject_id, and subject_key once.
- Use origin consistently for web/android/iOS/etc.
- Use channel consistently for WhatsApp/Telegram/etc.
- Use attribution_type for landing/outbound_share.
- Use touchpoint_type and interaction_type for outbound_share, visit, landing, return, and navigated.
- Use affiliate_link_id for all per-link grouping.
- Use visitor_key for dedupe and unique visitor reporting.
- Use url/referrer_url for visit display and dedupe.
- Use sharer_user_id and actor_user_id for impact attribution.
- Use external_reference as the one outcome idempotency key.
- Keep only sanitized diagnostic request context in metadata.
- Update Signals bridge to read native subject/user/destination fields.
- Add composite indexes only when an actual query in these services uses the same leading columns.

Acceptance:

- Share link creation/reuse, outbound share, landing, navigated visit, signup, registration, save, going, and check-in outcomes pass.
- Analytics counts and groupings match source rows.
- rg finds no affiliate metadata predicate or promoted-field fallback.

### Change set A13 — Remove Event metadata machinery

Modify:

- App\Models\Event
- App\Models\Builders\EventBuilder
- event factories/seeders
- all API/MCP/search/data classes that list metadata-backed fields

Tasks:

- Delete MetadataBackedAttributes.
- Delete setMetadataValue, productMetadataValue, and metadataSerializableValue if no retained Event use remains.
- Remove MetadataBackedColumns and every JSON-expression branch from EventBuilder.
- Keep occurrence-backed query projections only where they are an intentional optimized relation query.
- Keep package-column presentation mappings only when they represent the one current product contract, not an old alias. Move mapping to DTO/actions where possible.
- Remove stale comments claiming product counters live in Event metadata.
- Ensure Event metadata is optional extension payload only.

Acceptance:

- Event reads/writes never reinterpret a missing column as metadata.
- EventBuilder has no JSON path SQL.
- All event tests create canonical related rows rather than metadata fixtures.

### Change set A14 — Search, API, docs, and client contract sweep

Tasks:

- Rebuild Typesense schemas/documents for the new sources.
- occurrence_status, timing_mode, schedule_kind, institution_id, default_venue_id, engagement counts, and registration counts must be derived from canonical sources.
- Remove views_count from Typesense and allowed sort/filter contracts.
- Update Scramble schemas, MCP write schemas, frontend form contracts, admin resource schemas, and examples.
- Do not document removed keys as aliases.
- Update saved-search validation so filters target canonical semantics.
- Reindex all search documents after migrate:fresh --seed.
- Explicitly review mobile/client consumers for the removed views_count, checkin_token, Event user_id, Event submitter_id, and schedule_state keys.

Acceptance:

- API/MCP docs contain only the selected final keys.
- Database and Typesense paths return equivalent event sets.
- No removed write key is accepted silently.

## Required test strategy

Tests must assert externally observable behavior and canonical persistence, not private helper implementation.

### Package tests

Events:

- schedule kind persistence/default
- occurrence lifecycle timestamps
- primary occurrence/location selection
- submission model resolver
- participant contacts
- key-person display_name
- facility sync
- metadata non-mirroring
- search-document relation loading

Engagement:

- bookmark counter create/remove/archive
- response counter create/change/cancel
- counter uniqueness
- reconciliation command

Communications:

- device field persistence/casts/filter/order

References:

- part fields, canonical flag, hierarchy query

Affiliates:

- schema/model native fields
- link reuse by origin
- touchpoint/attribution/conversion reporting without metadata

### Application tests

- Event institution column and institution/venue exclusivity
- Masjid A + Main Hall display and search
- owner/creator/submitter/organizer authorization separation
- event submission dashboards
- occurrence status filters and change announcements
- absolute/prayer-relative scheduling
- save/going/registration derived counts
- Event API and JSON-LD
- registration primary contact
- venue facilities
- reference family/search
- notification destinations
- share tracking and Signals bridge
- user deletion/restoration
- Typesense/database parity
- Scramble and MCP contracts

### Performance checks

For the expected long-term scale, capture EXPLAIN plans for:

- upcoming public events at one institution
- events ordered by primary occurrence starts_at
- occurrence status filter
- prayer-relative filter
- bookmark/going counter lookup
- event registration aggregate
- affiliate per-link/channel/origin report
- reference part search

Expected properties:

- No JSON extraction in a filter, sort, join, or group.
- Institution query starts from events.institution_id index.
- Primary-occurrence lookup uses event_id + starts_at.
- Counter lookup uses subject identity + counter type/key.
- Affiliate reports use affiliate_link_id/channel/origin/touchpoint indexes.
- List serializers eager-load relations and do not issue one query per row.

## Verification commands and gates

Run package checks from /Users/Saiffil/Herd/commerce:

~~~sh
vendor/bin/pest --parallel tests/src/Events
vendor/bin/pest --parallel tests/src/Engagement
vendor/bin/pest --parallel tests/src/Communications
vendor/bin/pest --parallel tests/src/References
vendor/bin/pest --parallel tests/src/Affiliates
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --test
rg -n -- "constrained\\(|cascadeOnDelete\\(" packages/*/database
~~~

The constrained/cascade grep must be empty for modified migrations.

Run application checks from /Users/Saiffil/Herd/ilmu360:

~~~sh
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --test
git diff --check
~~~

Run these hard-cut source gates:

~~~sh
rg -n "metadata->" app database tests
rg -n "EventMetadataSyncService|attributes_to_metadata|audiences_to_metadata|time_expressions_to_metadata" /Users/Saiffil/Herd/commerce/packages/events /Users/Saiffil/Herd/commerce/tests
rg -n "\\b(user_id|submitter_id|schedule_state|views_count)\\b" app/Models/Event.php app/Models/Builders/EventBuilder.php
rg -n "productMetadataValue|MetadataBackedAttributes|MetadataBackedColumns" app/Models app/Models/Builders
rg -n "metadata.*(institution_id|schedule_kind|schedule_state|timing_mode|starts_at|ends_at|views_count|registrations_count|saves_count|going_count)" app database tests
rg -n "metadata.*(role_code|sort_order|display_name|checkin_token|primary_participant|contact)" app database tests
rg -n "metadata.*(platform|app_version|device_label|locale|timezone|last_seen_at)" app tests
rg -n "metadata.*(link_id|visitor_key|share_provider|share_origin|event_type|tracking_mode|visited_url|subject_id|subject_key|actor_user_id|sharer_user_id|outcome_key)" app/Services/ShareTracking app/Services/Signals tests/Feature/DawahShareImpactTest.php
~~~

Interpretation:

- metadata-> should be empty unless a newly documented intentional query is approved. The target design currently requires none.
- The Events package metadata-sync grep must be empty.
- Removed Event storage fields must be absent from Event persistence/query machinery. Readonly response DTO names such as saves_count may remain outside it.
- Snapshot/config metadata reads may remain only in the explicitly retained files/categories above.

Add focused persistence assertions that reserved keys are absent:

- events.metadata: institution_id, user_id, submitter_id, schedule_kind, schedule_state, timing_mode, starts_at, ends_at, views_count, registrations_count, saves_count, going_count
- event_occurrences.metadata: all schedule/timing/prayer mirrors
- event_updates.metadata: status
- event_involvements.metadata: name, role_code, sort_order
- event_registration_participants.metadata: contact
- communication_destinations.metadata: all promoted device fields
- venues.metadata: description, facilities
- ref_references.metadata: part_type, part_number, part_label, is_canonical
- affiliate metadata fields promoted in P11

## Atomic implementation order

Use the following order so a lower-capability implementation model has one concern at a time:

1. P01 Events schema primitives.
2. P02 Events enum/models/relations.
3. P03 submission model resolution.
4. P04 occurrence lifecycle verification/fixes.
5. P05 package metadata mirror removal.
6. P06 venue facility normalization.
7. P07 participant contact source.
8. P08 Engagement counters.
9. P09 Communications fields.
10. P10 References fields.
11. P11 Affiliate schema.
12. P12 Affiliate package cutover.
13. Run the full package suite and PHPStan before application work.
14. A01 destructive baseline.
15. A02 identity/authorization.
16. A03 explicit scheduling.
17. A04 schedule consumers.
18. A05 change announcements.
19. A06 location/key people.
20. A07 counters/analytics.
21. A08 registrations.
22. A09 venues/references.
23. A10 taxonomy policies.
24. A11 push destinations.
25. A12 share tracking.
26. A13 remove Event metadata machinery.
27. A14 search/API/docs sweep.
28. Run migrate:fresh --seed, reindex search, full tests, PHPStan, Pint, diff check, grep gates, and EXPLAIN checks.

Do not combine the package schema cutovers into one unreviewable commit. Each P change set should include its package tests. Each A change set should include its focused application tests. The final A13/A14 commits are deletion and contract-sweep commits, not places to introduce new storage.

## Out of scope

- Production data preservation or a live zero-downtime migration.
- A compatibility window for old mobile/API clients.
- Replacing all legitimate JSON documents in every Commerce package.
- Redesigning notification preference documents.
- Replacing historical address or announcement snapshots with live relationships.
- Adding a new native Institution model to the Events package.
- Adding an event-level schedule_state or timing_mode column.
- Adding an event-level views or registration counter column.
- Building a new QR check-in credential system.
- Refactoring normalized audience/link/attribute projections solely because they are projected in the app model, unless required to remove the metadata mirror.

## Definition of done

The work is complete only when:

- Every row in the disposition matrix uses its listed canonical source.
- No promoted field is read from or written to metadata.
- No fallback accessor, query rewrite, dual write, cleanup migration, or alias for removed storage remains.
- Package-generic schema is implemented in /Users/Saiffil/Herd/commerce/packages/*.
- events.institution_id remains app-owned and indexed.
- Organizer, location, owner, creator, and submitter semantics are independently tested.
- Event schedule lifecycle is occurrence-owned.
- Engagement and registration counts come from their source packages.
- Public, admin, MCP, mobile/API, database-search, and Typesense contracts agree.
- Fresh migration/seed, full parallel tests, PHPStan level 6, Pint, diff check, hard-cut greps, and query-plan review pass.
