# Domain Mapping

This rewrite removes legacy app-owned domain implementations when package-owned equivalents exist. Custom code remains only when there is no generic package capability or when the behavior is ilmu360-specific presentation/workflow.

## Mapping Table

| Current ilmu360 domain | Target package ownership | App-owned remainder | Deletion target | Phase |
| --- | --- | --- | --- | --- |
| Users/auth/profile | Laravel/Fortify/Passport/Sanctum plus `authz` where permissions apply | Account UX, OAuth provider setup, MCP token UX | None unless package provides exact replacement | 3, 8 |
| Teams and scoped permissions | `authz`, `filament-authz`, `membership` | Panel gates and product-specific role copy | App-local permission builders that duplicate package contracts | 3, 4 |
| Countries/states/cities/districts/subdistricts | `addressing` countries and hierarchical address areas | Seed/import sources for country-specific administrative terms and app-specific location presentation | Integer geography models/migrations/resources and country-switching preference logic | 4, 8 |
| Addresses | `addressing` | Public location display and map links where package does not cover UX | `App\Models\Address`, app address traits/actions | 4 |
| Contacts and social profiles | `contacting`, `filament-contacting` | Public display formatting, if not generic | `Contact`, `SocialMedia`, app normalizers after parity | 4 |
| Institutions | `events` `Organization` plus `membership`, `contacting`, `addressing` | Donation channels, public institution pages/dashboard | App institution persistence/actions/resources after package parity | 5, 8 |
| Speakers | `events` involvements and roles; custom thin model if no generic package entity exists | Public speaker profile UX and Islamic honorific presentation | App speaker persistence only if package/thin model replaces it | 5, 8 |
| Venues/spaces | `events` venue/location/space primitives plus `addressing` | Public venue detail UX if still needed | App venue/space models/resources after package replacement | 5, 8 |
| Series/program structure | `events` series, occurrences, recurrence, sessions | Public grouping presentation | App series/event-series models/actions | 5, 8 |
| Events | `events` | Public Livewire pages, MCP tools, Islamic presentation copy | `App\Models\Event`, app event save actions/forms/resources after replacement | 5, 8 |
| Prayer-relative timing | `events` time expressions | Prayer label localization and display | App timing mode fields/enums after mapped | 5 |
| Gender/age/Muslim-only/type | `events` attributes and audiences | Locale labels and public form arrangement | App-specific enum storage after mapped | 5 |
| Tags/taxonomy | `events` taxonomies, terms, classifications | Islamic taxonomy seed labels | Spatie tag app model/resource if fully replaced | 5 |
| References | `references`, `events` event references | Public reference pages | `App\Models\Reference`, app slug action after package parity | 5 |
| Registrations, RSVP, ticketing, attendance, check-in | `events`, `engagement`, and commerce packages where paid orders are required | App-specific public CTA layout, attendee-facing wording, and check-in surfaces | App registration/check-in/event-user tables/actions | 5, 7 |
| Saved events/follows/bookmarks/reactions/shares/reminders | `engagement` | Frontend button UX and Signals events | App event saves/follows wrappers are deleted; save and follow now go through package `EngagementManager`. | 5 |
| Event submissions/contributions | `events` submissions, logs, approvals plus `moderation` | Entity mutation application and public submission UX | App contribution request models/actions after mapped | 5 |
| Moderation reviews, blocks, reports | `moderation`, `events` moderation actions, possibly `feedback` | App report categories and Islamic safety workflow if generic package lacks it | App moderation review/report models after package parity | 5, 7 |
| Membership claims and invitations | `membership`, `authz` | Public claim pages and dashboard panels | `MembershipClaim` uses `membership_applications` table (package-owned). Claim approve/reject actions use package `ApplicationStatus` enum. | 4 |
| Notifications and inbox | `communications`, `filament-communications` | FCM, WhatsApp, digest scheduling, app-specific notification copy | `NotificationMessage` model deleted, `notifications` table dropped. Inbox reads/writes through package `NotificationInbox`. Pipeline/engine still app-owned. | 6 |
| Signals and analytics | `signals`, `filament-signals`, `growth` | Curated app event naming and privacy policy | App telemetry wrappers that duplicate package contracts | 3 |
| Share attribution | `affiliates`, `engagement` shares | Dakwah zero-value outcome semantics if no generic conversion flow fits | App share tracking wrappers after generic mapping | 3, 5 |
| Donation channels | None currently | Full app ownership | None | 8 |
| Media collections | Spatie MediaLibrary remains app/package integration | Collection definitions, conversions, fallback images | App media helpers only where replaced by package media refs | 5, 8 |
| Search indexes | Package search documents where available plus app search UX | Typesense schemas, public filter UX, MCP search docs | App indexers after replacement | 5, 8 |
| Public Livewire UX | None | Full app ownership | None | 8 |
| MCP servers/tools/docs resources | None | Full app ownership | None | 8 |
| API/mobile contracts | Package-backed models plus app controllers/resources | Public contract shape and generated docs | Old controller internals after replacement | 8 |
| AI usage/pricing/inspirations | App-owned unless a package emerges | Full app ownership | None | 8 |

## Islamic Domain Mapping

| ilmu360 concept | Package primitive |
| --- | --- |
| Majlis/event | `AIArmada\Events\Models\Event` |
| Occurrence/session | `EventOccurrence`, `EventSession` |
| Prayer-relative time | `EventTimeExpression` |
| Event type | `EventAttribute` or taxonomy term depending on query needs |
| Gender restriction | `EventAttribute` |
| Age group | `EventAudience` |
| Muslim-only flag | `EventAttribute` |
| Speaker, imam, khatib, bilal | `EventInvolvement` plus seeded `EventRole` |
| Institution/organizer | `Organization`, organizer morphs, `CanOrganizeEvents` |
| Venue/location | `Venue`, `EventLocation`, `addressing` address data |
| Domain/discipline/source/issue taxonomy | `EventTaxonomy`, `EventTerm`, `EventClassification` |
| Kitab/reference | `references` package plus `EventReference` |
| Submission approval | `EventSubmission`, `EventApprovalRequest`, `EventSubmissionLog` |
| Moderation trail | `moderation` actions and event moderation actions |
| Follow/save/interested/reminder/share | `engagement` follows/bookmarks/responses/reminders/shares |

## Global Discovery And Addressing Requirement

ilmu360° must become global by default. The rewrite must remove the current app-wide country switcher and preferred-country assumption. Users should be able to discover, filter, save searches for, and engage with talks across countries without switching application context.

Addressing must use the `aiarmada/addressing` model:

| Requirement | Package path |
| --- | --- |
| Country data | `AddressCountry` seeded from package country data. |
| Country-specific administrative areas | `AddressArea` with `country_code`, `type`, `level`, `parent_id`, `source`, `source_id`, `metadata`, and import sources. |
| Malaysia examples | Represent terms such as `district`, `wilayah_persekutuan`, and `small_district` as area `type` values rather than hard-coded app tables. |
| Other countries | Import each country's own administrative terms and hierarchy through generic area sources. |
| Institution and venue addresses | `Address` with package-native FKs only: `country_id`, `state_id` (State), `city_id` (City), `admin_area_1_id` (district), `admin_area_2_id` (subdistrict). Zero legacy aliases. |
| Search and filters | Global search by default, with optional country, area-type, area hierarchy, text, geohash/radius, and address-component filters. |

Do not rebuild `PublicCountryPreference`, `PreferredCountryResolver`, or session/cookie country switching in the target architecture. Country can be a filter, not an application mode.

## Event Participation And Ticketing Requirement

Current ilmu360 events are free-only. The rewrite target must not preserve that limitation. The package-backed event experience must support these modes from launch:

| Mode | Package path |
| --- | --- |
| Free open/walk-in event with no required registration | `events` access policy with walk-in support and `RecordWalkInAction` / headcount flow. |
| Free event with optional RSVP or interest | `events` free registration path plus `engagement` responses where appropriate. |
| Free ticketed event with pass/check-in | `events` free pricing mode, ticket types, pass issuance, capacity, and check-in. |
| Paid ticketed event | `events` paid pricing mode, ticket types, cart/order registration fulfillment, payment status, pass issuance, and check-in. |
| Mixed free and paid ticket types | `events` mixed pricing mode with ticket-type consistency checks and separate free/paid fulfillment paths. |
| Reserved seating or capacity-managed admission | `events` seat maps, seat sections, ticket seating options, capacity, waitlist, and check-in. |

The app can decide which modes are visible in the public UI first, but the schema, admin workflows, API/MCP contracts, and package integrations must be ready for all of them. Do not rebuild a free-only event abstraction in the app.

## Custom Boundary Rules

- Keep Islamic language, Malay copy, public page composition, MCP prompts, and product-specific donation behavior in the app.
- Move reusable workflow mechanics into packages only if the behavior could serve another Laravel app without ilmu360 context.
- When a package lacks a needed seam, add a generic contract/config point in the package rather than app-side reflection or monkey-patching.
