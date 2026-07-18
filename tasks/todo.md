# A06 — Location and key-person corrections (superseded by first-class metadata hard cut)

This historical checklist is retained for traceability only. Its former metadata,
alias, and compatibility recommendations are not implementation guidance; the
canonical package relations and columns described in
`tasks/first-class-metadata-hard-cut-plan.md` supersede them.

## Location Tasks
- [x] 1. Event.institution() - already uses institution_id column ✓
- [x] 2. Event.venue() - already uses default_venue_id column ✓
- [x] 3. Store Space in EventLocation.venue_space_id (already done) + fix location_role → 'primary'
- [x] 4. Use location_role = primary consistently
- [x] 5. Replace Event.space() belongsTo with query through primary EventLocation
- [x] 6. Validate Space belongs to Institution (already done in SaveAdminEventAction) ✓
- [x] 7. Validate institution/venue mutual exclusion (already done in SaveAdminEventAction) ✓
- [x] 8. Update serializers to show institution+space OR venue+venueSpace

## Key-Person Tasks
- [x] 1. Update EventKeyPersonSyncService: use display_name consistently (already done ✓)
- [x] 2. Delete HasEventInvolvementRole trait
- [x] 3. Update EventKeyPerson model: replace metadata name with display_name
- [x] 4. Replace metadata->name search with metadata->display_name in EventSearchService + EventController
- [x] 5. Eager-load involveable/speaker

---

# Metadata hard-cut cross-repository audit

## Plan
- [ ] Refresh codebase-memory indexes for `commerce` and `ilmu360`.
- [ ] Audit `commerce` package contracts, migrations, models, tests, docs, and CI for removed metadata/legacy paths.
- [ ] Audit `ilmu360` callers, migrations, serializers, tests, docs, and CI for removed metadata/legacy paths.
- [ ] Delegate an independent Terra Extra High review and reconcile findings.
- [ ] Implement fixes without compatibility aliases, shims, dual keys, or legacy fallbacks.
- [ ] Run focused tests, PHPStan/Pint, full quality workflows, and monitor GitHub Actions with `gh`.
- [ ] Repeat audit until both repositories are clean.

## Review

The cross-repository audit is in progress; findings are being fixed and rechecked
against both repositories and their CI workflows.
