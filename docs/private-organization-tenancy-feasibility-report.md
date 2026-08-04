# Feasibility Report: Private Organizations & Tenancy (Owner-Managed Entities)

**Date:** 2026-08-05
**Status:** For research & development — findings only, no implementation yet
**Scope:** Current database/application structure vs. the ability to support user-owned, multi-user-managed, private organizations/companies alongside the existing public community directory.

---

## 1. Goal

Allow users to create an organization/company **within their account** ("kinda like tenancy") that:

- Is **private to them** (and whoever they grant access), in contrast to the majority of institutions, which are **publicly listed**.
- Can be **managed by multiple users** (roles, invitations).
- Distinguishes two independent axes:
  1. **Ownership**: community entity (no owner, curator-driven) vs. user-owned entity (created by and belonging to a user).
  2. **Query/visibility**: public (listed in directory, searchable, discoverable) vs. private (excluded from all public queries).

---

## 2. Current State (Verified Findings)

### 2.1 Event hosting: venues AND institutions, with spaces

Events can be hosted at either an institution or a listed venue, both natively:

- `EventContributionFormSchema` has a `location_type` radio (`institution` / `venue`), `location_institution_id`, `location_venue_id`, and a multi-select `space_ids` (app/Forms/EventContributionFormSchema.php:474-589).
- `Event::syncLocation(?string $venueId, array $spaceIds)` (app/Models/Event.php:855) writes `EventLocation` rows with `venue_id`, `venue_space_id`, `venue_space_type_id`, and `space_name_snapshot` (history-safe). Supports multiple spaces (primary + additional) and venue-only fallback.
- **Spaces** (rooms/halls within a venue or institution):
  - `App\Models\Space` (extends package `VenueSpace`): `name`, `capacity`, `space_type`, `status`, `visibility` (app/Models/Space.php).
  - `Venue::spaces()` (hasMany) and `Institution::spaces()` (belongsToMany via `institution_space` pivot with per-institution `capacity` override, `effectiveCapacity()`).
  - Space types seeded (`VenueSpaceTypeSeeder`): hall, prayer_hall, meeting_room, lecture_room, banquet_hall, VIP room, etc.
  - Eligibility via `SpaceEligibilityResolver` contract (`DefaultSpaceEligibilityResolver`), filtered to `status = active`.

### 2.2 Multi-user management: fully native (membership)

- **Pivot**: `institution_members` (user ↔ institution) with `role`, `joined_at` (app/Models/User.php:185, `institutions()` relation).
- **Roles**: `MemberRole` enum — `Owner`, `Admin`, `Editor`, `Viewer` (vendor/aiarmada/membership/src/Enums/MemberRole.php), mapped to Spatie permissions with `*` for owner.
- **Lifecycle actions** (all exist in aiarmada/membership):
  - `InviteMemberAction` / `AcceptInvitationAction` / `RevokeInvitationAction`
  - `ApplyForMembershipAction` / `ApproveMembershipApplicationAction` / `RejectMembershipApplicationAction` / `CancelMembershipApplicationAction`
  - `AddMemberAction` / `RemoveMemberAction` / `ChangeMemberRoleAction`
  - Models: `MembershipInvitation`, `MembershipApplication` — **polymorphic** (`subject`), so the whole machinery works for any subject model, including a future `Organization`.
- **Authorization plumbing**: `MemberPermissionGate`, `MemberRoleScopes`, `MembershipRoleSyncService` (Spatie role/permission sync), `AppMembershipHook`.
- Admin UI: Filament `MembersRelationManager` on the Institutions resource.

### 2.3 Visibility: does NOT exist

- No `visibility` column on any host entity. Institutions carry only a moderated `status` (`verified` / `pending` / `rejected` / `inactive`) with lifecycle timestamps (`verified_at`, `rejected_at`, `published_at`, `last_state_change_at`).
- Publicness is implicit and hard-coded:
  - `Institution::shouldBeSearchable()` → status in `['verified', 'pending']` (app/Models/Institution.php:110)
  - `makeAllSearchableUsing()` → `whereIn(status, [verified, pending])` (app/Models/Institution.php:130)
  - `active()` scope → same status list (app/Models/Institution.php:388)
  - Public directory, Search API, and Scout indexing all filter by status only.
- There is no mechanism to say "this record is private; exclude from directory/search/API."

### 2.4 Ownership: does NOT exist

- No `owner_id` / `created_by` FK on institutions, venues, spaces, or events (grep across `database/migrations` found none; only OAuth's `owner` morph and the cache-table `owner` column are unrelated).
- The `Owner` member role is a **management** concept (who administers the entity), not record ownership. Nothing distinguishes:
  - a community-curated public entity, vs.
  - a user-created entity that belongs to that user.
- Institution creation today flows through contribution/moderation workflows, not self-serve account creation.

### 2.5 Tenancy scoping: does NOT exist

- Events, venues, spaces, and locations are global records; every public query filters by `status` only.
- No tenant/schema/row-level scoping exists anywhere.

---

## 3. Gap Summary

| Capability | Supported today? | Notes |
|---|---|---|
| Host event at venue or institution | ✅ | `Event::syncLocation()` + form |
| Spaces/rooms (e.g. "Main Hall") per host | ✅ | `Space`, `VenueSpaceType`, resolver |
| Multiple users managing an entity with roles | ✅ | membership package, polymorphic |
| Invite / apply / approve / role-change members | ✅ | full action suite |
| **Private vs public listing (query/visibility)** | ❌ | no `visibility` column; publicness hard-coded by status |
| **User-owned vs community entity (ownership)** | ❌ | no owner FK; no self-serve creation |
| **Tenancy scoping of child records** | ❌ | no scope anywhere |

---

## 4. Proposed Direction (for R&D)

The membership machinery is the hard part and already exists. The missing pieces are small, additive schema + scope changes:

### 4.1 Ownership
- Add nullable `owner_id` (user FK) to the entity table (`institutions`, or a new `organizations` table if a separate entity is preferred — membership is polymorphic and works for either).
  - `NULL` → community/public entity (current behavior unchanged).
  - Set → user-owned entity; creator automatically becomes the `Owner` member.
- New entity types can reuse the existing contribution flow or a new self-serve creation flow.

### 4.2 Visibility (per project lifecycle rules)
- Add `visibility` column with a string-backed enum (`public` / `private`), not a boolean.
- Public entry points must be guarded:
  - `active()` / `shouldBeSearchable()` / `makeAllSearchableUsing()` scopes
  - Public directory, Search API, Scout indexing
  - Event/venue/space listing queries
- Private entities' child records (events, spaces, venues) inherit the exclusion.

### 4.3 Scoping
- Keep `visibility = public` as the default filter for all public surfaces; private records are only reachable through the owning user's authenticated workspace.

### 4.4 Reuse
- Membership (invites, roles, permission gate) needs **no changes** to serve private orgs — it is already polymorphic.

---

## 5. Open Questions for R&D

1. **Entity choice**: add `visibility` + `owner_id` to `institutions`, or introduce a separate `organizations` table? (Institutions carry media/address/sponsorship baggage; companies may not need it.)
2. **Self-serve vs moderated**: should user-created private orgs require admin approval at all, or only when they are made public?
3. **Transition path**: can a private org be "promoted" to a public community institution (and vice versa)? What happens to ownership when it becomes public?
4. **Child record handling**: when an org is private, are its venues/spaces shared or also private? Can a public event be hosted at a private org's venue?
5. **Deletion/transfer**: what happens to the org when the owner leaves or deletes their account?

---

## 6. Verification Points (existing guardrails)

- Lifecycle rules require `visibility` enum + `{status}_at` timestamps — see `.ai/lifecycle rules` (AGENTS.md).
- No DB-level constraints/cascades allowed; enforce owner/member integrity in application logic.
- Membership package ownership boundary: subject models expose `HasMembers`; keep pivot + Spatie role mutations atomic (vendor/aiarmada/membership/CONTEXT.md).
