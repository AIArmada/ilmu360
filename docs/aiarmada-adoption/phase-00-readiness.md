# Phase 0 - Baseline Readiness

State: `Verified`

## Objective

Create the durable adoption command center and reconcile the existing readiness documents with live source facts.

## Entry Criteria

- User-approved rewrite plan exists.
- Local package source is available at `/Users/Saiffil/Herd/commerce/packages/*`.
- App source is available at `/Users/Saiffil/Herd/ilmu360`.

## Checklist

- [x] Create `docs/aiarmada-adoption/`.
- [x] Create phase, inventory, status, decision, domain mapping, agent queue, and review-log docs.
- [x] Update `tasks/todo.md` to point to this hub and mirror the active phase only.
- [x] Re-run package inventory commands and compare with `package-inventory.md`.
- [x] Confirm package migration safety scans.
- [x] Confirm current Composer AIArmada package state.
- [x] Record verification evidence in `review-log.md`.
- [x] Mark Phase 0 `Verified` in `status.md` only after proof exists.

## Verification

```bash
test -d docs/aiarmada-adoption
find docs/aiarmada-adoption -maxdepth 1 -type f | sort
find /Users/Saiffil/Herd/commerce/packages -maxdepth 2 -name composer.json -print | wc -l
find /Users/Saiffil/Herd/commerce/packages -path '*/database/migrations/*.php' -print | wc -l
rg -n -- "aiarmada/" composer.json
rg -n -- "constrained\(|cascadeOnDelete\(" /Users/Saiffil/Herd/commerce/packages/*/database
rg -n -- "softDeletes\(\)|SoftDeletes" app database /Users/Saiffil/Herd/commerce/packages
```

## Exit Criteria

- Status dashboard exists and is internally consistent.
- Inventory facts are backed by commands or prior recorded exploration.
- Blockers are recorded with resolution paths.
- Work queue can be handed to another agent without oral context.

## Stop And Re-plan Triggers

- Live source package count differs from the recorded 59 packages.
- Package migration safety differs materially from recorded findings.
- Existing docs contain a newer adoption strategy that conflicts with this hub.
