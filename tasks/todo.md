# Friendliness + Lifecycle Hard Cut

## Done
- [x] Lifecycle helper, migrations (Postgres applied), drop entity `is_active`
- [x] Event transitions + ContributionRequest + Report timestamps
- [x] Captcha / GitHub / ShareTracking contracts (+ null objects)
- [x] Fixed bad bulk rewrite: `is_active` → wrong `status in (verified,pending,active)` on **events** → `published_at` + `PUBLIC_STATUSES`
- [x] Spaces stay on `status=active` (not verified/pending)
- [x] Speakers show/index no longer call broken relation `->active()` scope
- [x] EventFactory no longer overwrites status with invalid `active`
- [x] EventKeyPerson mapping + sync package columns
- [x] Migration fix: pgsql `jsonb_exists` instead of `?` (PDO binding conflict)

## Verified green
- LifecycleHardCutTest, EventTest, ActiveScopeTest, SpeakerIndexTest, InspirationTest, EventVisibilityAccessTest

## Remaining known failures (not blocking hard-cut core)
- InstitutionIndexTest: 4 cases (duplicate name validation, location hierarchy/filter/dedupe) — look address-scope related, not `is_active` column
- ModerationServiceTest: package `moderation_actions` vs app expectations (`moderator_id`, `decision`) — pre-existing package cutover debt
- Mega-service splits still optional

## Intentional `is_active`
- Package catalog (EventTerm/Taxonomy/Role), signals TrackedProperty, migration strings, lifecycle assertion names
