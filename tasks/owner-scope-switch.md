# Owner scope switch playbook

This file is the source of truth for one future question only:

> If we ever turn affiliate owner scoping on later, what exactly do we change, and what exactly do we leave alone?

Current truth:
- The app uses `owner_type` / `owner_id` as the canonical affiliate bridge.
- Global owner scoping is intentionally **off** right now.
- The restore flow must bring the user back with all deleted snapshot data **except security tokens**.
- The one-time purge migration is for pre-launch disposable affiliate data only.

## If you decide to enable owner scoping later

Do these steps in order. Do not guess.

1. **Enable the owner-scope bootstrap/config for the affiliate package.**
   - This is the only step that should flip scoped mode on.
   - Do not change delete/restore behavior just to turn scoping on.

2. **Re-audit the files that control affiliate ownership and restore behavior.**
   Re-check these exact files:
   - `app/Models/User.php`
   - `app/Services/ShareTracking/AffiliatesShareTrackingService.php`
   - `app/Services/ShareTracking/AdminShareAnalyticsService.php`
   - `app/Services/ShareTracking/AffiliateRuntimeDataPurger.php`
   - `app/Http/Controllers/Api/CurrentUserController.php`
   - `app/Filament/Pages/DeletedUsers.php`

3. **Keep admin/global queries explicitly global.**
   These paths must continue to bypass owner scoping if owner scoping is enabled:
   - admin analytics dashboards
   - cleanup / purge tooling
   - super-admin / deleted-user management screens

4. **Keep user-facing affiliate queries owner-scoped only where that is the intended behavior.**
   - If a query is meant to represent “this user’s affiliate data,” it should use the owner relation.
   - If a query is meant to represent “all affiliates,” it must stay explicitly global.

5. **Run the focused checks again after the toggle.**
   Run these tests after any owner-scope switch:
   - `tests/Feature/Api/AuthApiTest.php`
   - `tests/Feature/UserRestoreTest.php`
   - `tests/Feature/DawahShareImpactTest.php`
   - `tests/Feature/ShareTracking/AffiliateRuntimeDataPurgerTest.php`

6. **Verify the two critical behaviors after the toggle.**
   - Restoring a deleted user must restore the full snapshot of user-owned data, except security tokens.
   - The admin share dashboard must still show the full dataset.

## What must not change later

Do **not** do these unless you intentionally want to redesign the system:

- Do not reintroduce `majlis_user_id`.
- Do not change the restore flow so it starts dropping any data other than security tokens.
- Do not remove the one-time purge migration.
- Do not backfill old pre-launch affiliate rows.
- Do not enable owner scoping without rerunning the focused tests above.

## Current implementation rules

- User delete/restore is the source of truth for recovering user-owned data.
- The purge migration is the source of truth for throwing away pre-launch affiliate data.
- The app should always prefer explicit `owner_type` / `owner_id` ownership over old metadata bridges.
- If you are unsure, stop and follow the steps above instead of guessing.

## Definition of done

When the future owner-scope switch is correct, all of the following will be true:

- The user restore path still restores everything except security tokens.
- Admin analytics still reads the full affiliate dataset.
- Cleanup tooling still wipes only the disposable affiliate dataset.
- No code path depends on `majlis_user_id`.
