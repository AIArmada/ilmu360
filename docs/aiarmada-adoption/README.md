# AIArmada Adoption Program

Execution hub for rebuilding ilmu360° on local AIArmada packages (`/Users/Saiffil/Herd/commerce/packages/*`).

## Start here

| Priority | File | Purpose |
| ---: | --- | --- |
| 1 | **Live code + Composer** | Absolute truth |
| 2 | [`status.md`](status.md) | Live phase, gaps, blockers, next actions |
| 3 | [`phase-reconciliation.md`](phase-reconciliation.md) | **No-miss matrix** — every phase checkbox reconciled to Done / Phase 9 / Deferred |
| 4 | [`phase-08-cutover.md`](phase-08-cutover.md) | Phase 8 checklist + Phase 9 workstreams |
| 5 | [`gap-closure-report.html`](gap-closure-report.html) · [`cutover-plan.html`](cutover-plan.html) | Dashboards |
| 6 | [`architecture-decisions.md`](architecture-decisions.md) | ADRs (incl. taxonomy, geography, paid commerce) |
| 7 | [`domain-mapping.md`](domain-mapping.md) | Ownership targets |
| 8 | [`paid-commerce-productization.md`](paid-commerce-productization.md) | ADR-013 product path |
| 9 | [`../commerce-package-readiness-reassessment.md`](../commerce-package-readiness-reassessment.md) | Readiness audit |

### Historical phase files (do not use open boxes as backlog)

| File | Use as |
| --- | --- |
| `phase-00` … `phase-03` | Completed history (Verified) |
| `phase-04` … `phase-07*` | **Assessment-era plans** — superseded by Phase 8 execution; residuals only via [`phase-reconciliation.md`](phase-reconciliation.md) |
| `agent-work-queue.md` | Packet history; Phase 9 work is tracked in status + reconciliation, not stale WP-09E “Not Started” rows without cross-check |
| `review-log.md` | Evidence archive |
| `package-inventory.md` | May lag install list — prefer Composer + status.md |

## North star (2026-07-10)

Close dual paths and cutover shims. Packages natively. Feature freeze until Phase 9 exit.

**Active phase:** Phase 9 native purity (+ paid commerce productization when payment ready).

## Status vocabulary

`Not Started` · `Assessing` · `Blocked` · `Ready` · `In Progress` · `In Review` · `Verified` · `Deferred` · `Removed` · `Superseded` · `Mostly complete`

## Operating rules

- Session start: read `status.md` + `phase-reconciliation.md` + current workstream.  
- Do not re-open phase-04–07 checklists as implementation lists — update the reconciliation matrix instead.  
- Package edits stay generic (no ilmu360-only domain rules in packages).  
- Delete superseded code after replacement is verified; no permanent BC shims.  
- UI behavior changes: consider Signals tracking.  

## Verification baseline

```bash
php artisan migrate:fresh --seed
vendor/bin/pest --parallel --compact
vendor/bin/phpstan analyse --ansi
vendor/bin/pint --dirty --format agent
```
